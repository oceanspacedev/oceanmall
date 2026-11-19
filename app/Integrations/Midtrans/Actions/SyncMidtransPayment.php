<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Actions;

use App\Actions\Orders\MarkOrderPaid;
use App\Integrations\Midtrans\Exceptions\MidtransPaymentNeedsReview;
use App\Integrations\Midtrans\Midtrans;
use App\Integrations\Midtrans\Services\MidtransClient;
use App\Integrations\Midtrans\Support\MidtransOrderState;
use App\Integrations\Midtrans\Support\MidtransTransactionStatus;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * The one place a Midtrans payment changes an order. Used by the
 * notification webhook (one attempt), "check status" / expiry
 * (reconcile(): every attempt of the order) and retry.
 *
 * The status always comes from the Core API (GET /v2/{order_id}/status),
 * never from the notification body, and is applied idempotently:
 *
 * - paid (settlement / capture + fraud accept): amount and currency must
 *   match the order, then the provider-neutral MarkOrderPaid captures the
 *   attempt's transaction and fires Shopper's OrderPaid once;
 * - failed / cancelled: the attempt's transaction is marked failed; the
 *   order stays pending (the customer may pay again; orders:expire-unpaid
 *   cancels it after its expiry), as for Komerce's expired payments;
 * - pending / no transaction yet: nothing changes;
 * - money for a cancelled order, or a second attempt paid after the order
 *   was already paid: nothing is revived or double-applied; a critical
 *   MidtransPaymentNeedsReview is reported so a human refunds it.
 */
final readonly class SyncMidtransPayment
{
    public const HANDLED = 'handled';

    public const ALREADY_PROCESSED = 'already_processed';

    public const NOT_PAID = 'not_paid';

    public const FAILED = 'failed';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const LOOKUP_FAILED = 'lookup_failed';

    public const NO_ORDER = 'no_order';

    public const NO_ATTEMPT = 'no_attempt';

    public const CANCELLED_ORDER = 'cancelled_order';

    public const NEEDS_REVIEW = 'needs_review';

    public function __construct(
        private MidtransClient $client,
        private MarkOrderPaid $markOrderPaid,
    ) {}

    /**
     * Apply the current status of one attempt (a notification's order_id).
     */
    public function handle(string $midtransOrderId): string
    {
        $midtransOrderId = trim($midtransOrderId);
        $transaction = $this->transaction($midtransOrderId);
        $order = $transaction instanceof PaymentTransaction
            ? Order::query()->find($transaction->order_id)
            : $this->orderByNumber($midtransOrderId);

        if (! $order instanceof Order) {
            return self::NO_ORDER;
        }

        return $this->settle($order, $midtransOrderId, $transaction);
    }

    /**
     * Pull every attempt of the order, newest first, and settle it when one
     * is paid.
     */
    public function reconcile(Order $order): string
    {
        $order->refresh();
        $references = MidtransOrderState::transactions($order)
            ->pluck('reference')
            ->map(static fn (mixed $reference): string => (string) $reference)
            ->push((string) MidtransOrderState::currentOrderId($order))
            ->filter(static fn (string $reference): bool => $reference !== '')
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return $order->payment_status === PaymentStatus::Paid ? self::ALREADY_PROCESSED : self::NO_ATTEMPT;
        }

        $lookupFailed = false;
        $verdicts = [];

        foreach ($references as $reference) {
            $verdict = $this->settle($order->refresh(), $reference, $this->transaction($reference));

            if (in_array($verdict, [self::HANDLED, self::ALREADY_PROCESSED, self::AMOUNT_MISMATCH, self::NEEDS_REVIEW], true)) {
                return $verdict;
            }

            $lookupFailed = $lookupFailed || $verdict === self::LOOKUP_FAILED;
            $verdicts[] = $verdict;
        }

        if ($lookupFailed) {
            return self::LOOKUP_FAILED;
        }

        return in_array(self::NOT_PAID, $verdicts, true) ? self::NOT_PAID : ($verdicts[0] ?? self::NOT_PAID);
    }

    private function settle(Order $order, string $midtransOrderId, ?PaymentTransaction $transaction): string
    {
        // Duplicate notification for an attempt already captured: no API call.
        if ($order->payment_status === PaymentStatus::Paid
            && $transaction instanceof PaymentTransaction
            && $transaction->type === TransactionType::Capture
            && $transaction->status === TransactionStatus::Success) {
            return self::ALREADY_PROCESSED;
        }

        $status = $this->fetch($midtransOrderId);

        if ($status === null) {
            return self::LOOKUP_FAILED;
        }

        if (MarkOrderPaid::isClosed($order)) {
            if ($status->isPaid() && ! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
                $this->needsReview($order, $midtransOrderId, 'paid after the order was cancelled');

                return self::NEEDS_REVIEW;
            }

            return self::CANCELLED_ORDER;
        }

        if ($order->payment_status === PaymentStatus::Paid) {
            // A concurrent duplicate of the notification that just paid the
            // order (its transaction row was read before that commit): this
            // very attempt is the captured one, nothing is extra.
            if ($this->isCaptured($midtransOrderId)) {
                return self::ALREADY_PROCESSED;
            }

            if ($status->isPaid()) {
                // Another attempt paid the order first: this money is extra.
                $this->needsReview($order, $midtransOrderId, 'a second attempt was paid after the order was already paid');

                return self::NEEDS_REVIEW;
            }

            return self::ALREADY_PROCESSED;
        }

        if ($status->isClosedUnpaid()) {
            $this->recordClosedAttempt($order, $midtransOrderId, $status);

            return self::FAILED;
        }

        if (! $status->isPaid()) {
            return self::NOT_PAID;
        }

        if ($status->currency !== null && $status->currency !== strtoupper((string) $order->currency_code)) {
            report(new RuntimeException("Midtrans payment {$midtransOrderId} for order {$order->number} is in {$status->currency}, not {$order->currency_code}."));

            return self::AMOUNT_MISMATCH;
        }

        $verdict = $this->markOrderPaid->handle(
            order: $order,
            driver: Midtrans::CODE,
            reference: $midtransOrderId,
            confirmedAmount: $status->amount ?? -1,
            transactionMetadata: $this->transactionMetadata($midtransOrderId, $status),
        );

        return match ($verdict) {
            MarkOrderPaid::HANDLED => $this->afterPaid($order, $midtransOrderId, $status),
            MarkOrderPaid::ALREADY_PROCESSED => self::ALREADY_PROCESSED,
            MarkOrderPaid::AMOUNT_MISMATCH => $this->amountMismatch($order, $midtransOrderId, $status),
            MarkOrderPaid::CANCELLED_ORDER => $this->cancelledMeanwhile($order, $midtransOrderId),
            default => self::NO_ORDER,
        };
    }

    private function afterPaid(Order $order, string $midtransOrderId, MidtransTransactionStatus $status): string
    {
        $transaction = $this->transaction($midtransOrderId);

        if ($transaction instanceof PaymentTransaction) {
            $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
            $transaction->forceFill(['metadata' => [...$metadata, ...$this->transactionMetadata($midtransOrderId, $status)]])->save();
        }

        MidtransOrderState::write($order, ['paid_order_id' => $midtransOrderId, 'payment_type' => $status->raw['payment_type'] ?? null]);

        return self::HANDLED;
    }

    private function amountMismatch(Order $order, string $midtransOrderId, MidtransTransactionStatus $status): string
    {
        report(new RuntimeException(sprintf(
            'Midtrans payment amount mismatch for order %s (attempt %s, paid %s, expected %d).',
            $order->number,
            $midtransOrderId,
            $status->amount === null ? 'unknown' : (string) $status->amount,
            (int) $order->price_amount,
        )));

        return self::AMOUNT_MISMATCH;
    }

    private function cancelledMeanwhile(Order $order, string $midtransOrderId): string
    {
        $this->needsReview($order->refresh(), $midtransOrderId, 'paid while the order was being cancelled');

        return self::NEEDS_REVIEW;
    }

    private function recordClosedAttempt(Order $order, string $midtransOrderId, MidtransTransactionStatus $status): void
    {
        $transaction = $this->transaction($midtransOrderId);

        if (! $transaction instanceof PaymentTransaction || $transaction->type === TransactionType::Capture) {
            return;
        }

        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];

        $transaction->forceFill([
            'status' => TransactionStatus::Failed,
            'metadata' => [...$metadata, 'transaction_status' => $status->transactionStatus, 'fraud_status' => $status->fraudStatus],
        ])->save();
    }

    private function needsReview(Order $order, string $midtransOrderId, string $reason): void
    {
        report(new MidtransPaymentNeedsReview((string) $order->number, $midtransOrderId, $reason));
    }

    private function fetch(string $midtransOrderId): ?MidtransTransactionStatus
    {
        try {
            return MidtransTransactionStatus::fromResponse($this->client->status($midtransOrderId));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function isCaptured(string $midtransOrderId): bool
    {
        return $midtransOrderId !== '' && PaymentTransaction::query()
            ->where('driver', Midtrans::CODE)
            ->where('reference', $midtransOrderId)
            ->where('type', TransactionType::Capture)
            ->where('status', TransactionStatus::Success)
            ->exists();
    }

    private function transaction(string $midtransOrderId): ?PaymentTransaction
    {
        if ($midtransOrderId === '') {
            return null;
        }

        return PaymentTransaction::query()
            ->where('driver', Midtrans::CODE)
            ->where('reference', $midtransOrderId)
            ->latest('id')
            ->first();
    }

    /**
     * Fallback for an attempt whose transaction row is missing: the order
     * number the id was derived from, and only for a Midtrans order.
     */
    private function orderByNumber(string $midtransOrderId): ?Order
    {
        $number = MidtransOrderState::orderNumberOf($midtransOrderId);

        if ($number === '') {
            return null;
        }

        $order = Order::query()->where('number', $number)->first();

        if (! $order instanceof Order) {
            return null;
        }

        $order->loadMissing('paymentMethod');

        return ($order->paymentMethod?->driver ?? null) === Midtrans::CODE || MidtransOrderState::read($order) !== []
            ? $order
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionMetadata(string $midtransOrderId, MidtransTransactionStatus $status): array
    {
        return array_filter([
            'midtrans_order_id' => $midtransOrderId,
            'transaction_id' => $status->raw['transaction_id'] ?? null,
            'transaction_status' => $status->transactionStatus,
            'fraud_status' => $status->fraudStatus,
            'payment_type' => $status->raw['payment_type'] ?? null,
            'settlement_time' => $status->raw['settlement_time'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
