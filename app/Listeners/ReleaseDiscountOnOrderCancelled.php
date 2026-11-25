<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Order;

/**
 * An order that was cancelled without ever being paid gives its coupon use
 * back. Shopper reserves the use when the order is created, before any
 * payment: without this, an expired VA burns the coupon ("already used" on
 * the customer's retry) and a campaign with a usage limit is exhausted by
 * orders nobody paid for.
 *
 * - the discount's total_use is decremented once (metadata.discount_released_at
 *   marks it: OrderCancelled is fired again for an already cancelled order);
 * - the order's discount_id is cleared, because Shopper's "one use per
 *   customer" rule counts every order that carries it, cancelled or not. The
 *   id stays readable as metadata.released_discount_id; the amounts on the
 *   order are untouched.
 *
 * A paid order that is cancelled keeps its use: the coupon was redeemed.
 */
final class ReleaseDiscountOnOrderCancelled
{
    public function handle(OrderCancelled $event): void
    {
        $orderId = $event->order->id ?? null;

        if ($orderId === null) {
            return;
        }

        DB::transaction(static function () use ($orderId): void {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order instanceof Order
                || $order->discount_id === null
                || $order->payment_status === PaymentStatus::Paid) {
                return;
            }

            $metadata = self::metadata($order);

            if (filled($metadata['discount_released_at'] ?? null)) {
                return;
            }

            $discountId = $order->discount_id;

            Discount::query()
                ->whereKey($discountId)
                ->where('total_use', '>', 0)
                ->decrement('total_use');

            // Shopper's Order does not cast metadata: it is stored as JSON text.
            $metadata['discount_released_at'] = now()->toIso8601String();
            $metadata['released_discount_id'] = $discountId;

            $order->forceFill([
                'discount_id' => null,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ])->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(Order $order): array
    {
        $metadata = $order->getAttribute('metadata');

        if (is_string($metadata) && trim($metadata) !== '') {
            $metadata = json_decode($metadata, true);
        }

        return is_array($metadata) ? $metadata : [];
    }
}
