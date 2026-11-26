<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Actions;

use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\RecordPaymentDispute;
use App\Integrations\Xendit\Exceptions\XenditApiException;
use App\Integrations\Xendit\Exceptions\XenditPaidAfterCancellation;
use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\Services\XenditClient;
use App\Integrations\Xendit\Support\XenditOrderPayment;
use App\Models\PaymentDispute;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * Xendit side of a payment confirmation (webhook or reconcile): find the
 * order of an invoice, read the invoice back from the Xendit API (a callback
 * body is never trusted on its own), check it really belongs to the order,
 * then hand a PAID / SETTLED invoice to the provider-neutral MarkOrderPaid
 * (lock, amount check, capture transaction, Shopper's OrderPaid once).
 *
 * Idempotent: a paid order answers `already_processed` without an API call,
 * and MarkOrderPaid fires OrderPaid only on the unpaid -> paid transition.
 */
final readonly class ConfirmXenditInvoice
{
    public const HANDLED = MarkOrderPaid::HANDLED;

    public const ALREADY_PROCESSED = MarkOrderPaid::ALREADY_PROCESSED;

    public const AMOUNT_MISMATCH = MarkOrderPaid::AMOUNT_MISMATCH;

    public const CANCELLED_ORDER = MarkOrderPaid::CANCELLED_ORDER;

    public const NO_ORDER = MarkOrderPaid::NO_ORDER;

    public const NO_TRANSACTION = 'no_transaction';

    public const LOOKUP_FAILED = 'lookup_failed';

    public const NOT_PAID = 'not_paid';

    /** The invoice is EXPIRED at Xendit (and still the order's current invoice). */
    public const EXPIRED = 'expired';

    /** An EXPIRED invoice that was replaced by a retry: nothing to do. */
    public const SUPERSEDED = 'superseded';

    /** The invoice's external_id does not belong to the order it was found for. */
    public const FOREIGN_INVOICE = 'foreign_invoice';

    public const PAID_AFTER_CANCEL = 'paid_after_cancel';

    /** An unrecorded invoice was paid for an order already paid through another one. */
    public const DUPLICATE_PAYMENT = 'duplicate_payment';

    public function __construct(
        private XenditClient $client,
        private MarkOrderPaid $markOrderPaid,
    ) {}

    /**
     * @return array{verdict: string, order: Order|null}
     */
    public function handle(string $invoiceId, bool $adoptUnknownPaidInvoice = false): array
    {
        $invoiceId = trim($invoiceId);
        $transaction = $invoiceId === '' ? null : PaymentTransaction::query()
            ->where('driver', XenditDriver::CODE)
            ->where('reference', $invoiceId)
            ->latest('id')
            ->first();

        if (! $transaction instanceof PaymentTransaction) {
            return $adoptUnknownPaidInvoice && $invoiceId !== ''
                ? $this->adoptUnknownInvoice($invoiceId)
                : ['verdict' => self::NO_TRANSACTION, 'order' => null];
        }

        $order = Order::query()->find($transaction->order_id);

        if (! $order instanceof Order) {
            return ['verdict' => self::NO_ORDER, 'order' => null];
        }

        if (MarkOrderPaid::isClosed($order)) {
            return ['verdict' => $this->closedOrder($order, $invoiceId), 'order' => $order];
        }

        if ($order->payment_status === PaymentStatus::Paid) {
            $alreadyCapturedHere = PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->where('driver', XenditDriver::CODE)
                ->where('reference', $invoiceId)
                ->where('status', TransactionStatus::Success)
                ->exists();

            if ($alreadyCapturedHere) {
                return ['verdict' => self::ALREADY_PROCESSED, 'order' => $order];
            }

            try {
                $invoice = $this->client->getInvoice($invoiceId);
            } catch (Throwable $e) {
                report($e);

                return ['verdict' => self::LOOKUP_FAILED, 'order' => $order];
            }

            if (XenditOrderPayment::belongsTo($order, $invoice['external_id'] ?? null)
                && XenditOrderPayment::isPaid($invoice)) {
                report(new RuntimeException(sprintf(
                    'Xendit invoice %s was paid for order %s, which is already paid through another invoice: refund one of them.',
                    $invoiceId,
                    $order->number,
                )));

                resolve(RecordPaymentDispute::class)->handle(
                    order: $order,
                    driver: XenditDriver::CODE,
                    type: PaymentDispute::TYPE_DOUBLE_PAYMENT,
                    reference: $invoiceId,
                    amount: XenditOrderPayment::paidAmount($invoice),
                    expectedAmount: (int) $order->price_amount,
                    currencyCode: (string) $order->currency_code,
                    reason: 'Invoice Xendit kedua dibayar untuk order yang sudah lunas.',
                    payload: $invoice,
                );

                return ['verdict' => self::DUPLICATE_PAYMENT, 'order' => $order];
            }

            return ['verdict' => self::ALREADY_PROCESSED, 'order' => $order];
        }

        try {
            $invoice = $this->client->getInvoice($invoiceId);
        } catch (Throwable $e) {
            report($e);

            return ['verdict' => self::LOOKUP_FAILED, 'order' => $order];
        }

        if (! XenditOrderPayment::belongsTo($order, $invoice['external_id'] ?? null)) {
            report(new RuntimeException(sprintf(
                'Xendit invoice %s (external_id %s) does not belong to order %s.',
                $invoiceId,
                (string) ($invoice['external_id'] ?? ''),
                $order->number,
            )));

            return ['verdict' => self::FOREIGN_INVOICE, 'order' => $order];
        }

        $status = XenditOrderPayment::status($invoice);
        $isCurrent = XenditOrderPayment::currentInvoiceId($order) === $invoiceId;

        if ($isCurrent) {
            XenditOrderPayment::write($order, ['status' => $status]);
        }

        if ($status === 'EXPIRED') {
            return ['verdict' => $isCurrent ? self::EXPIRED : self::SUPERSEDED, 'order' => $order];
        }

        if (! XenditOrderPayment::isPaid($invoice)) {
            return ['verdict' => self::NOT_PAID, 'order' => $order];
        }

        // The invoice itself must be for the order's amount, and so must what
        // was paid (MarkOrderPaid compares the paid amount, zero tolerance).
        $expected = (int) ($transaction->amount ?: $order->price_amount);
        $invoiceAmount = XenditOrderPayment::paidAmount(['amount' => $invoice['amount'] ?? null]);

        $verdict = $invoiceAmount !== null && $invoiceAmount !== $expected
            ? MarkOrderPaid::AMOUNT_MISMATCH
            : $this->markOrderPaid->handle(
                order: $order,
                driver: XenditDriver::CODE,
                reference: $invoiceId,
                confirmedAmount: XenditOrderPayment::paidAmount($invoice),
                transactionMetadata: [
                    'xendit_invoice_id' => $invoiceId,
                    'xendit_status' => $status,
                ],
            );

        if ($verdict === MarkOrderPaid::AMOUNT_MISMATCH) {
            report(new RuntimeException(sprintf(
                'Xendit invoice %s amount mismatch for order %s (expected %d, invoice %s, paid %s).',
                $invoiceId,
                $order->number,
                $expected,
                var_export($invoiceAmount, true),
                var_export(XenditOrderPayment::paidAmount($invoice), true),
            )));

            resolve(RecordPaymentDispute::class)->handle(
                order: $order,
                driver: XenditDriver::CODE,
                type: PaymentDispute::TYPE_AMOUNT_MISMATCH,
                reference: $invoiceId,
                amount: XenditOrderPayment::paidAmount($invoice) ?? $invoiceAmount,
                expectedAmount: $expected,
                currencyCode: (string) $order->currency_code,
                reason: 'Nominal pembayaran Xendit tidak sesuai dengan order.',
                payload: $invoice,
            );

            return ['verdict' => self::AMOUNT_MISMATCH, 'order' => $order];
        }

        if ($verdict === MarkOrderPaid::HANDLED) {
            $this->recordPayment($order, $invoice);
        }

        if ($verdict === MarkOrderPaid::CANCELLED_ORDER) {
            // Cancelled between the API read and the row lock, yet Xendit says paid.
            report(new XenditPaidAfterCancellation((string) $order->number, $invoiceId));

            resolve(RecordPaymentDispute::class)->handle(
                order: $order,
                driver: XenditDriver::CODE,
                type: PaymentDispute::TYPE_PAID_AFTER_CANCELLATION,
                reference: $invoiceId,
                amount: XenditOrderPayment::paidAmount($invoice) ?? $invoiceAmount,
                expectedAmount: $expected,
                currencyCode: (string) $order->currency_code,
                reason: 'Invoice Xendit dibayar setelah pesanan dibatalkan.',
                payload: $invoice,
            );

            return ['verdict' => self::PAID_AFTER_CANCEL, 'order' => $order->refresh()];
        }

        return ['verdict' => $verdict, 'order' => $order->refresh()];
    }

    /**
     * A PAID callback for an invoice this store never recorded: the invoice
     * was created at Xendit but its answer never reached us (timeout), and
     * "pay again" then issued another one. The invoice is read back and,
     * only when Xendit says it is paid AND both its metadata.order_id and
     * external_id point at the same Xendit order, the order is settled
     * through MarkOrderPaid (amount checked) and its other open invoice is
     * expired. Anything else (an invoice of another system sharing the
     * Xendit account) stays `no_transaction`.
     *
     * @return array{verdict: string, order: Order|null}
     */
    private function adoptUnknownInvoice(string $invoiceId): array
    {
        try {
            $invoice = $this->client->getInvoice($invoiceId);
        } catch (XenditApiException $e) {
            if ($e->httpStatus === 404) {
                return ['verdict' => self::NO_TRANSACTION, 'order' => null];
            }

            report($e);

            return ['verdict' => self::LOOKUP_FAILED, 'order' => null];
        } catch (Throwable $e) {
            report($e);

            return ['verdict' => self::LOOKUP_FAILED, 'order' => null];
        }

        if (! XenditOrderPayment::isPaid($invoice)) {
            return ['verdict' => self::NO_TRANSACTION, 'order' => null];
        }

        $orderId = data_get($invoice, 'metadata.order_id');
        $order = is_numeric($orderId) ? Order::query()->find((int) $orderId) : null;

        if (! $order instanceof Order
            || ! XenditOrderPayment::belongsTo($order, $invoice['external_id'] ?? null)
            || $order->paymentMethod?->driver !== XenditDriver::CODE) {
            return ['verdict' => self::NO_TRANSACTION, 'order' => null];
        }

        if (MarkOrderPaid::isClosed($order)) {
            report(new XenditPaidAfterCancellation((string) $order->number, $invoiceId));

            resolve(RecordPaymentDispute::class)->handle(
                order: $order,
                driver: XenditDriver::CODE,
                type: PaymentDispute::TYPE_PAID_AFTER_CANCELLATION,
                reference: $invoiceId,
                amount: XenditOrderPayment::paidAmount($invoice),
                expectedAmount: (int) $order->price_amount,
                currencyCode: (string) $order->currency_code,
                reason: 'Invoice Xendit tidak tercatat dibayar setelah pesanan dibatalkan.',
                payload: $invoice,
            );

            return ['verdict' => self::PAID_AFTER_CANCEL, 'order' => $order];
        }

        if ($order->payment_status === PaymentStatus::Paid) {
            report(new RuntimeException(sprintf(
                'Xendit invoice %s was paid for order %s, which is already paid through another invoice: refund one of them.',
                $invoiceId,
                $order->number,
            )));

            resolve(RecordPaymentDispute::class)->handle(
                order: $order,
                driver: XenditDriver::CODE,
                type: PaymentDispute::TYPE_DOUBLE_PAYMENT,
                reference: $invoiceId,
                amount: XenditOrderPayment::paidAmount($invoice),
                expectedAmount: (int) $order->price_amount,
                currencyCode: (string) $order->currency_code,
                reason: 'Invoice Xendit kedua dibayar untuk order yang sudah lunas.',
                payload: $invoice,
            );

            return ['verdict' => self::DUPLICATE_PAYMENT, 'order' => $order];
        }

        $expected = (int) $order->price_amount;
        $invoiceAmount = XenditOrderPayment::paidAmount(['amount' => $invoice['amount'] ?? null]);

        $verdict = $invoiceAmount !== $expected
            ? MarkOrderPaid::AMOUNT_MISMATCH
            : $this->markOrderPaid->handle(
                order: $order,
                driver: XenditDriver::CODE,
                reference: $invoiceId,
                confirmedAmount: XenditOrderPayment::paidAmount($invoice),
                transactionMetadata: [
                    'xendit_invoice_id' => $invoiceId,
                    'xendit_status' => XenditOrderPayment::status($invoice),
                    'adopted' => true,
                ],
            );

        if ($verdict === MarkOrderPaid::AMOUNT_MISMATCH) {
            report(new RuntimeException(sprintf(
                'Xendit invoice %s amount mismatch for order %s (expected %d, invoice %s, paid %s).',
                $invoiceId,
                $order->number,
                $expected,
                var_export($invoiceAmount, true),
                var_export(XenditOrderPayment::paidAmount($invoice), true),
            )));

            return ['verdict' => self::AMOUNT_MISMATCH, 'order' => $order];
        }

        if ($verdict === MarkOrderPaid::CANCELLED_ORDER) {
            report(new XenditPaidAfterCancellation((string) $order->number, $invoiceId));

            return ['verdict' => self::PAID_AFTER_CANCEL, 'order' => $order->refresh()];
        }

        if ($verdict === MarkOrderPaid::HANDLED) {
            $this->expireOtherInvoice($order, $invoiceId);
            $this->recordPayment($order, $invoice);
        }

        return ['verdict' => $verdict, 'order' => $order->refresh()];
    }

    /**
     * The order is paid through $paidInvoiceId: its current invoice (when it
     * is another one) must not take a second payment.
     */
    private function expireOtherInvoice(Order $order, string $paidInvoiceId): void
    {
        $current = XenditOrderPayment::currentInvoiceId($order);

        if ($current === null || $current === $paidInvoiceId || ! XenditOrderPayment::modeMatches($order)) {
            return;
        }

        try {
            $this->client->expireInvoice($current);
            XenditOrderPayment::write($order, ['status' => 'EXPIRED']);
        } catch (Throwable $e) {
            report(new RuntimeException(
                "Order {$order->number} is paid by Xendit invoice {$paidInvoiceId}; its open invoice {$current} could not be expired: expire it in the Xendit Dashboard.",
                0,
                $e,
            ));
        }
    }

    /**
     * A cancelled order is never revived; money that still reached Xendit
     * (paid in the last seconds, expire call lost) is raised as critical.
     */
    private function closedOrder(Order $order, string $invoiceId): string
    {
        if (in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
            return self::CANCELLED_ORDER;
        }

        try {
            $invoice = $this->client->getInvoice($invoiceId);
        } catch (Throwable $e) {
            report($e);

            return self::LOOKUP_FAILED;
        }

        if (! XenditOrderPayment::isPaid($invoice)) {
            return self::CANCELLED_ORDER;
        }

        report(new XenditPaidAfterCancellation((string) $order->number, $invoiceId));

        resolve(RecordPaymentDispute::class)->handle(
            order: $order,
            driver: XenditDriver::CODE,
            type: PaymentDispute::TYPE_PAID_AFTER_CANCELLATION,
            reference: $invoiceId,
            amount: XenditOrderPayment::paidAmount($invoice),
            expectedAmount: (int) $order->price_amount,
            currencyCode: (string) $order->currency_code,
            reason: 'Invoice Xendit dibayar setelah pesanan dibatalkan.',
            payload: $invoice,
        );

        return self::PAID_AFTER_CANCEL;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function recordPayment(Order $order, array $invoice): void
    {
        XenditOrderPayment::write($order, array_filter([
            'paid_at' => is_string($invoice['paid_at'] ?? null) ? $invoice['paid_at'] : null,
            'payment_method' => is_string($invoice['payment_method'] ?? null) ? $invoice['payment_method'] : null,
            'payment_channel' => is_string($invoice['payment_channel'] ?? null) ? $invoice['payment_channel'] : null,
        ]));
    }
}
