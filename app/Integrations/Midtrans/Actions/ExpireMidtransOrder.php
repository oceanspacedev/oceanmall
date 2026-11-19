<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Actions;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Integrations\Midtrans\Midtrans;
use App\Integrations\Midtrans\Support\MidtransOrderState;
use Illuminate\Support\Collection;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * Cancel an unpaid Midtrans order (orders:expire-unpaid after the Snap
 * expiry, or staff "cancel order"):
 *
 * 1. reconcile every attempt with the Core API first: a payment that landed
 *    just before expiry settles the order instead (a lookup failure throws,
 *    the order is left for the next run);
 * 2. close every still-payable attempt (expire / cancel) so it cannot be
 *    paid afterwards; an attempt that turns out paid settles the order;
 * 3. cancel locally through the store's generic CancelOrderWithProviders
 *    (lock, voided payment status, stock release, customer notification,
 *    Shopper's OrderCancelled), and mark open attempts failed.
 *
 * Money that still arrives later is reported as MidtransPaymentNeedsReview
 * by the webhook, never applied to the cancelled order.
 */
final readonly class ExpireMidtransOrder
{
    public function __construct(
        private SyncMidtransPayment $sync,
        private CloseMidtransAttempt $close,
        private CancelOrderWithProviders $cancel,
    ) {}

    public function handle(Order $order, string $reason = 'Payment expired'): Order
    {
        $order->refresh();

        if ($order->payment_status === PaymentStatus::Paid || $order->status === OrderStatus::Cancelled) {
            return $order;
        }

        $verdict = $this->sync->reconcile($order);

        if ($verdict === SyncMidtransPayment::LOOKUP_FAILED) {
            throw new RuntimeException("Could not verify the Midtrans payment of order {$order->number}; not cancelling ({$reason}).");
        }

        if (in_array($verdict, [SyncMidtransPayment::HANDLED, SyncMidtransPayment::ALREADY_PROCESSED, SyncMidtransPayment::AMOUNT_MISMATCH, SyncMidtransPayment::NEEDS_REVIEW], true)) {
            return $order->refresh();
        }

        foreach ($this->openAttempts($order) as $transaction) {
            try {
                $status = $this->close->handle((string) $transaction->reference);
            } catch (Throwable $e) {
                throw new RuntimeException("Midtrans attempt {$transaction->reference} of order {$order->number} could not be closed; not cancelling.", 0, $e);
            }

            if ($status->isPaid()) {
                $this->sync->handle((string) $transaction->reference);

                return $order->refresh();
            }
        }

        $cancelled = $this->cancel->handle($order, throughPaymentProvider: false);

        if ($cancelled->status === OrderStatus::Cancelled) {
            PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->where('driver', Midtrans::CODE)
                ->where('type', TransactionType::Initiate)
                ->whereIn('status', [TransactionStatus::Pending, TransactionStatus::Success])
                ->update(['status' => TransactionStatus::Failed]);

            MidtransOrderState::write($cancelled, ['cancel_reason' => $reason]);
        }

        return $cancelled->refresh();
    }

    /**
     * @return Collection<int, PaymentTransaction>
     */
    private function openAttempts(Order $order): Collection
    {
        return MidtransOrderState::transactions($order)
            ->filter(static fn (PaymentTransaction $transaction): bool => $transaction->type === TransactionType::Initiate
                && $transaction->status !== TransactionStatus::Failed)
            ->values();
    }
}
