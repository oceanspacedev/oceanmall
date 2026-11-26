<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Actions;

use App\Integrations\Midtrans\Midtrans;
use App\Integrations\Midtrans\Payment\MidtransDriver;
use App\Integrations\Midtrans\Support\MidtransOrderState;
use App\Payment\Exceptions\PaymentRetryRefused;
use App\Payment\PaymentMethodCatalog;
use App\Payment\PaymentReturnUrl;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Payment\Services\PaymentProcessingService;
use Throwable;

/**
 * Customer "pay again" for a Midtrans order whose attempt expired or failed:
 * settle first if any attempt was paid after all, close the previous attempt
 * when Midtrans still holds it open, then create a new Snap transaction
 * ({number}~{attempt}) through Shopper's PaymentProcessingService (so the
 * new Initiate transaction is recorded like at checkout) on the same channel.
 */
final readonly class RetryMidtransPayment
{
    public function __construct(
        private SyncMidtransPayment $sync,
        private CloseMidtransAttempt $close,
        private PaymentProcessingService $payments,
    ) {}

    /** Seconds a retry may hold the per-order lock (Snap + status calls). */
    private const LOCK_SECONDS = 60;

    /**
     * One retry per order at a time: two concurrent "pay again" clicks would
     * otherwise both pass the eligibility check and open two payable Snap
     * transactions (the second one bumped to the next attempt number).
     */
    public function handle(Order $order): void
    {
        $lock = Cache::lock('midtrans:retry:'.$order->getKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw PaymentRetryRefused::notEligible('A new payment for this order is already being created.');
        }

        try {
            $this->retry($order);
        } finally {
            $lock->release();
        }
    }

    private function retry(Order $order): void
    {
        $order->refresh();

        if ($order->payment_status === PaymentStatus::Paid) {
            throw PaymentRetryRefused::alreadyPaid();
        }

        $driver = Payment::driver(Midtrans::CODE);

        if (! $driver instanceof MidtransDriver || ! $driver->canRetryPayment($order)) {
            throw PaymentRetryRefused::notEligible('Order is not eligible for a new Midtrans payment.');
        }

        $previous = MidtransOrderState::currentOrderId($order);

        if ($previous !== null) {
            $this->closePrevious($order, $previous);
        }

        $order->loadMissing('paymentMethod');
        $method = $order->paymentMethod;

        if (! $method instanceof PaymentMethod) {
            throw PaymentRetryRefused::notEligible('Order has no payment method.');
        }

        $channel = MidtransOrderState::read($order)['channel_code']
            ?? PaymentMethodCatalog::metadata($method)['channel_code']
            ?? null;

        try {
            $result = $this->payments->initiate($order, array_filter([
                'order' => $order,
                'payment_method_id' => $method->id,
                'channel_code' => is_string($channel) ? $channel : null,
                'midtrans_attempt' => MidtransOrderState::nextAttempt($order),
                'return_url' => PaymentReturnUrl::get($order),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException('Unable to create a new Midtrans payment: '.$e->getMessage(), 0, $e);
        }

        if (! $result->success) {
            throw new RuntimeException($result->message ?? 'Unable to create a new Midtrans payment.');
        }

        $driver->afterPaymentInitiated($order->refresh(), $result);
    }

    private function closePrevious(Order $order, string $previous): void
    {
        $verdict = $this->sync->reconcile($order);

        if (in_array($verdict, [SyncMidtransPayment::HANDLED, SyncMidtransPayment::ALREADY_PROCESSED], true)) {
            throw PaymentRetryRefused::alreadyPaid();
        }

        if ($verdict === SyncMidtransPayment::LOOKUP_FAILED) {
            throw new RuntimeException('Midtrans could not confirm the previous payment; try again shortly.');
        }

        if (in_array($verdict, [SyncMidtransPayment::AMOUNT_MISMATCH, SyncMidtransPayment::NEEDS_REVIEW], true)) {
            throw PaymentRetryRefused::notEligible('The previous payment is under review.');
        }

        try {
            $closed = $this->close->handle($previous);
        } catch (Throwable $e) {
            throw new RuntimeException('The previous Midtrans payment could not be closed: '.$e->getMessage(), 0, $e);
        }

        if ($closed->isPaid()) {
            $this->sync->handle($previous);

            throw PaymentRetryRefused::alreadyPaid();
        }

        PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', Midtrans::CODE)
            ->where('reference', $previous)
            ->where('status', '!=', TransactionStatus::Failed)
            ->update(['status' => TransactionStatus::Failed]);
    }
}
