<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use Illuminate\Support\Facades\DB;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;

/**
 * Provider-neutral "the provider confirmed this payment" step, shared by every
 * gateway webhook / reconcile path:
 *
 * - locks the order, refuses cancelled / voided / refunded orders,
 * - refuses a confirmed amount that does not match the order (or its pending
 *   transaction) beyond the driver's tolerance,
 * - captures the provider's PaymentTransaction (or records one),
 * - marks the order paid and fires Shopper's OrderPaid.
 *
 * Side effects (customer notification, shipment fulfilment, analytics...) are
 * OrderPaid listeners, exactly as for Shopper's own cpanel "mark paid".
 * OrderPaid is fired only on the unpaid -> paid transition, so retries and
 * duplicate webhooks never dispatch it twice.
 */
final class MarkOrderPaid
{
    public const HANDLED = 'handled';

    public const ALREADY_PROCESSED = 'already_processed';

    public const CANCELLED_ORDER = 'cancelled_order';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const NO_ORDER = 'no_order';

    /**
     * @param  string  $driver  Shopper payment driver code that confirmed the payment
     * @param  int|null  $confirmedAmount  Amount the provider reports as paid (null = not reported)
     * @param  int  $amountTolerance  Accepted drift (e.g. unique-amount QR suffixes)
     * @param  array<string, mixed>  $transactionMetadata  Metadata for a transaction recorded here
     * @return self::HANDLED|self::ALREADY_PROCESSED|self::CANCELLED_ORDER|self::AMOUNT_MISMATCH|self::NO_ORDER
     */
    public function handle(
        Order $order,
        string $driver,
        ?string $reference,
        ?int $confirmedAmount = null,
        int $amountTolerance = 0,
        array $transactionMetadata = [],
    ): string {
        return DB::transaction(function () use ($order, $driver, $reference, $confirmedAmount, $amountTolerance, $transactionMetadata): string {
            $lockedOrder = Order::query()->lockForUpdate()->find($order->id);

            if (! $lockedOrder instanceof Order) {
                return self::NO_ORDER;
            }

            if (self::isClosed($lockedOrder)) {
                return self::CANCELLED_ORDER;
            }

            if ($lockedOrder->payment_status === PaymentStatus::Paid) {
                return self::ALREADY_PROCESSED;
            }

            $transaction = $reference !== null && $reference !== ''
                ? PaymentTransaction::query()
                    ->where('order_id', $lockedOrder->id)
                    ->where('driver', $driver)
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first()
                : null;

            if ($confirmedAmount !== null) {
                $expected = (int) ($transaction?->amount ?? $lockedOrder->price_amount);

                if (abs($confirmedAmount - $expected) > $amountTolerance) {
                    return self::AMOUNT_MISMATCH;
                }
            }

            $updates = ['payment_status' => PaymentStatus::Paid];

            if ($lockedOrder->status === OrderStatus::New) {
                $updates['status'] = OrderStatus::Processing;
            }

            $lockedOrder->update($updates);

            if ($transaction instanceof PaymentTransaction) {
                $transaction->update([
                    'type' => TransactionType::Capture,
                    'status' => TransactionStatus::Success,
                ]);
            } else {
                PaymentTransaction::query()->create([
                    'order_id' => $lockedOrder->id,
                    'payment_method_id' => $lockedOrder->payment_method_id,
                    'driver' => $driver,
                    'type' => TransactionType::Capture,
                    'status' => TransactionStatus::Success,
                    'amount' => $confirmedAmount ?? (int) $lockedOrder->price_amount,
                    'currency_code' => $lockedOrder->currency_code,
                    'reference' => $reference,
                    'metadata' => $transactionMetadata !== [] ? $transactionMetadata : null,
                ]);
            }

            // ShouldDispatchAfterCommit: listeners run once the row is committed.
            event(new OrderPaid($lockedOrder->refresh()));

            return self::HANDLED;
        });
    }

    public static function isClosed(Order $order): bool
    {
        return $order->status === OrderStatus::Cancelled
            || in_array($order->payment_status, [
                PaymentStatus::Voided,
                PaymentStatus::Refunded,
            ], true);
    }
}
