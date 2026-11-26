<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Actions\Payment;

use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\RecordPaymentDispute;
use App\Integrations\Komerce\Exceptions\KomercePaidAfterCancellation;
use App\Integrations\Komerce\Payment\KomerceDriver;
use App\Integrations\Komerce\Support\KomercePaymentLookupContext;
use App\Models\PaymentDispute;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * Komerce side of a payment confirmation: find the order for a Komerce /
 * QRISLY payment id, verify the status with Komerce, alert on money received
 * for a cancelled order, then hand over to the provider-neutral MarkOrderPaid
 * (which fires Shopper's OrderPaid).
 *
 * Shipping is never called from here: provider shipments react to OrderPaid
 * (FulfillShipmentsOnOrderPaid), and paid shipments still without an AWB
 * (e.g. a dispatch that failed before a webhook retry) are swept by the
 * generic shipping:fulfill-paid schedule.
 */
final class MarkOrderPaidFromKomerce
{
    public function __construct(
        private readonly MarkOrderPaid $markOrderPaid,
        private readonly KomercePaymentLookupContext $lookupContext,
    ) {}

    /**
     * @param  'payment_api'|'qrisly'|null  $provider  Null = auto-detect from transaction/order metadata.
     */
    public function handle(string $paymentId, ?string $provider = null): string
    {
        $transaction = PaymentTransaction::query()
            ->where('driver', 'komerce')
            ->where('reference', $paymentId)
            ->first();

        $order = $transaction
            ? Order::query()->find($transaction->order_id)
            : $this->findOrderByPaymentReference($paymentId);

        if (! $transaction && ! $order) {
            return 'no_transaction';
        }

        if (! $order) {
            return 'no_order';
        }

        $provider ??= $this->resolveProvider($transaction, $order);

        if ($this->isTerminallyCancelled($order)) {
            return $this->handleCancelledOrder($order, $paymentId, $provider);
        }

        if ($order->payment_status === PaymentStatus::Paid) {
            $alreadyCapturedHere = PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->where('driver', KomerceDriver::CODE)
                ->where('type', TransactionType::Capture)
                ->where('status', TransactionStatus::Success)
                ->where(static function ($q) use ($paymentId, $transaction): void {
                    $q->where('reference', $paymentId);
                    if ($transaction instanceof PaymentTransaction) {
                        $q->orWhere('id', $transaction->id);
                    }
                })
                ->exists();

            if (! $alreadyCapturedHere) {
                $retrieved = $this->retrieve($paymentId, $provider);
                if ($retrieved instanceof PaymentResult && $retrieved->status === 'captured') {
                    $remotePayment = is_array($retrieved->data['raw_response'] ?? null)
                        ? $retrieved->data['raw_response']
                        : [];

                    resolve(RecordPaymentDispute::class)->handle(
                        order: $order,
                        driver: KomerceDriver::CODE,
                        type: PaymentDispute::TYPE_DOUBLE_PAYMENT,
                        reference: $paymentId,
                        amount: $this->remoteAmount($remotePayment, $provider),
                        expectedAmount: (int) $order->price_amount,
                        currencyCode: (string) $order->currency_code,
                        reason: 'Pembayaran Komerce kedua diterima untuk order yang sudah lunas.',
                        payload: $remotePayment,
                    );
                }
            }

            // Webhook retry for a paid order: OrderPaid already ran.
            return 'already_processed';
        }

        $retrieved = $this->retrieve($paymentId, $provider);

        if (! $retrieved instanceof PaymentResult) {
            return 'lookup_failed';
        }

        if ($retrieved->status !== 'captured') {
            return 'not_paid';
        }

        $remotePayment = is_array($retrieved->data['raw_response'] ?? null)
            ? $retrieved->data['raw_response']
            : [];

        // Lock, amount check, transaction capture and Shopper's OrderPaid are
        // provider-neutral; notification and delivery are OrderPaid listeners.
        $status = $this->markOrderPaid->handle(
            order: $order,
            driver: KomerceDriver::CODE,
            reference: $transaction instanceof PaymentTransaction ? (string) $transaction->reference : $paymentId,
            confirmedAmount: $this->remoteAmount($remotePayment, $provider),
            amountTolerance: $provider === 'qrisly' ? 999 : 0,
            transactionMetadata: [
                'komerce_payment_ref' => $paymentId,
                'komerce_provider' => $provider,
                'komerce_status' => 'PAID',
            ],
        );

        if ($status === MarkOrderPaid::AMOUNT_MISMATCH) {
            report(new RuntimeException(sprintf(
                'Komerce payment amount mismatch for order %s (payment %s).',
                $order->number,
                $paymentId,
            )));

            resolve(RecordPaymentDispute::class)->handle(
                order: $order,
                driver: KomerceDriver::CODE,
                type: PaymentDispute::TYPE_AMOUNT_MISMATCH,
                reference: $paymentId,
                amount: $this->remoteAmount($remotePayment, $provider),
                expectedAmount: (int) $order->price_amount,
                currencyCode: (string) $order->currency_code,
                reason: 'Nominal pembayaran Komerce tidak cocok dengan total tagihan.',
                payload: $remotePayment,
            );

            return 'amount_mismatch';
        }

        if ($status === MarkOrderPaid::CANCELLED_ORDER) {
            // Cancelled between the remote lookup and the row lock, yet Komerce says captured.
            $this->alertPaidAfterCancellation($order->refresh(), $paymentId, $provider);

            return 'paid_after_cancel';
        }

        // ALREADY_PROCESSED: paid meanwhile (e.g. QRIS and VA webhooks
        // racing); whoever won fired OrderPaid.
        return $status;
    }

    /**
     * A cancelled order is never revived, but money that still reaches Komerce
     * afterwards (late VA transfer, QRIS without a remote cancel) has to be
     * refunded by a human — so verify it and raise a critical alert.
     */
    private function handleCancelledOrder(Order $order, string $paymentId, string $provider): string
    {
        if (in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
            return 'cancelled_order';
        }

        if ($this->retrieve($paymentId, $provider)?->status !== 'captured') {
            return 'cancelled_order';
        }

        $this->alertPaidAfterCancellation($order, $paymentId, $provider);

        return 'paid_after_cancel';
    }

    private function alertPaidAfterCancellation(Order $order, string $paymentId, string $provider): void
    {
        report(new KomercePaidAfterCancellation($order->number, $paymentId, $provider));

        resolve(RecordPaymentDispute::class)->handle(
            order: $order,
            driver: KomerceDriver::CODE,
            type: PaymentDispute::TYPE_PAID_AFTER_CANCELLATION,
            reference: $paymentId,
            amount: (int) $order->price_amount,
            expectedAmount: (int) $order->price_amount,
            currencyCode: (string) $order->currency_code,
            reason: "Dana Komerce ({$provider}) masuk setelah pesanan dibatalkan.",
        );
    }

    private function retrieve(string $paymentId, string $provider): ?PaymentResult
    {
        $this->lookupContext->setProvider($provider);

        try {
            return Payment::driver('komerce')->retrievePayment($paymentId);
        } catch (Throwable) {
            return null;
        } finally {
            $this->lookupContext->clear();
        }
    }

    private function isTerminallyCancelled(Order $order): bool
    {
        return MarkOrderPaid::isClosed($order);
    }

    private function findOrderByPaymentReference(string $paymentId): ?Order
    {
        return Order::query()
            ->where(function ($query) use ($paymentId): void {
                $query->where('metadata->komerce_payment_ref', $paymentId)
                    ->orWhere('metadata->komerce->payment_ref', $paymentId)
                    ->orWhere('metadata->komerce->qrisly_history_id', $paymentId);
            })
            ->first();
    }

    private function resolveProvider(?PaymentTransaction $transaction, Order $order): string
    {
        $fromTx = data_get($transaction?->metadata, 'komerce_provider');
        if (is_string($fromTx) && $fromTx !== '') {
            return $fromTx;
        }

        $metadata = $order->getAttribute('metadata');
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $fromOrder = data_get($metadata, 'komerce.provider');

        return is_string($fromOrder) && $fromOrder !== '' ? $fromOrder : 'payment_api';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function remoteAmount(array $response, string $provider): ?int
    {
        $paths = $provider === 'qrisly'
            ? ['data.final_amount', 'data.original_amount', 'data.amount', 'final_amount', 'amount', 'paid_amount']
            : ['data.amount', 'amount'];

        foreach ($paths as $path) {
            $amount = data_get($response, $path);
            if (is_numeric($amount)) {
                return (int) $amount;
            }
        }

        return null;
    }
}
