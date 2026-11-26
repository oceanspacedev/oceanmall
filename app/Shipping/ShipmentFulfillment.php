<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

/**
 * The one provider-neutral gate in front of "book this shipment with its
 * shipping provider" (store order, pickup request, create order). Booking is
 * not idempotent at every provider and costs money, so:
 *
 *  - run() serializes the paid-order job (App\Jobs\FulfillOrderShipment),
 *    its inline after-response run and the shipping:fulfill-paid retry sweep
 *    (which runs the same job inline) per shipment (atomic Cache::lock), and
 *    re-reads the shipment and its order AFTER taking the lock, before the
 *    job hands it to the driver that fulfils it (FulfillsShipments);
 *  - assertEligible() is called by every provider fulfilment service right
 *    before each booking call, so direct callers (cpanel buttons, staff
 *    "issue AWB") and a status change during a slow provider round trip are
 *    covered too;
 *  - scopeEligible() is the SQL twin for batch selection.
 *
 * A shipment may be booked only while it has no airway bill, it is not
 * cancelled or delivered, and its order is still open: not cancelled /
 * completed / archived and not refunded / voided. (The order's aggregate
 * shipping_status is not used: a provider status such as "labeled" without
 * an AWB already makes it "shipped" while the pickup is still to request.)
 */
final class ShipmentFulfillment
{
    /**
     * Longer than the worst-case booking round trip (quote, store order,
     * pickup, detail, label at a 30 s timeout each, plus connect retries), so
     * the lock never expires under a live booking; a crashed worker frees it
     * within ten minutes and the sweep then retries.
     */
    public const LOCK_SECONDS = 600;

    public const BOOKED = 'booked';

    public const SKIPPED = 'skipped';

    public const BUSY = 'busy';

    public const NO_DRIVER = 'no_driver';

    /**
     * Shipment statuses that mean the parcel will never ship again. Not
     * picked_up / in_transit: provider statuses are normalized loosely (an
     * unknown one, or "waiting for pickup", may land there) while a booking
     * may still have to finish its pickup request.
     */
    private const CLOSED_SHIPMENT_STATUSES = [
        NormalizeShipmentStatus::CANCELLED,
        NormalizeShipmentStatus::DELIVERED,
        NormalizeShipmentStatus::RETURNED,
    ];

    /** Order statuses whose shipments are never booked automatically. */
    private const CLOSED_ORDER_STATUSES = [
        OrderStatus::Cancelled,
        OrderStatus::Completed,
        OrderStatus::Archived,
    ];

    /** Payment statuses whose goods must never ship. */
    private const VOID_PAYMENT_STATUSES = [
        PaymentStatus::Refunded,
        PaymentStatus::Voided,
    ];

    /**
     * Book one shipment at most once at a time: $book receives the shipment
     * re-read under the lock, only when it is still eligible, and returns
     * false when no driver fulfils it (staff ship it by hand). Returns BUSY
     * when another process is booking it right now, SKIPPED when it is not
     * (or no longer) eligible. Provider errors are rethrown for the caller to
     * retry.
     *
     * @param  Closure(OrderShipment): bool  $book
     */
    public static function run(int $shipmentId, Closure $book): string
    {
        $lock = Cache::lock(self::lockKey($shipmentId), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return self::BUSY;
        }

        try {
            $shipment = OrderShipment::query()->with('order')->find($shipmentId);

            if (! $shipment instanceof OrderShipment || self::blockedReason($shipment) !== null) {
                return self::SKIPPED;
            }

            try {
                return $book($shipment) ? self::BOOKED : self::NO_DRIVER;
            } catch (ShipmentNotFulfillable) {
                return self::SKIPPED;
            }
        } finally {
            $lock->release();
        }
    }

    public static function lockKey(int $shipmentId): string
    {
        return 'shipment:fulfill:'.$shipmentId;
    }

    /**
     * Re-read the shipment and its order from the database and refuse to go
     * on when the shipment must not be booked (anymore). Provider fulfilment
     * services call this right before every booking request.
     *
     * @throws ShipmentNotFulfillable
     */
    public static function assertEligible(OrderShipment $shipment): void
    {
        $fresh = OrderShipment::query()->with('order')->find($shipment->getKey());

        $reason = $fresh instanceof OrderShipment
            ? self::blockedReason($fresh)
            : __('The shipment no longer exists.');

        if ($reason !== null) {
            throw new ShipmentNotFulfillable($reason);
        }
    }

    /**
     * Why this shipment must not be booked with a provider, or null when it
     * may be. Reads the given models as they are; assertEligible() re-reads.
     */
    public static function blockedReason(OrderShipment $shipment): ?string
    {
        if (trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '') {
            return __('The shipment already has an airway bill.');
        }

        if (in_array(self::value($shipment->status), self::CLOSED_SHIPMENT_STATUSES, true)) {
            return __('The shipment is cancelled or delivered; it is not booked with the courier again.');
        }

        $order = $shipment->order;

        if (! $order instanceof Order) {
            return __('The shipment is missing its order.');
        }

        if (in_array(self::value($order->status), self::values(self::CLOSED_ORDER_STATUSES), true)) {
            return __('The order is cancelled, completed or archived; the shipment is not booked with the courier.');
        }

        if (in_array(self::value($order->payment_status), self::values(self::VOID_PAYMENT_STATUSES), true)) {
            return __('The order payment is refunded or voided; the shipment is not booked with the courier.');
        }

        return null;
    }

    /**
     * Restrict an order_shipments query to rows blockedReason() lets through
     * (the order's payment status is left to the caller).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeEligible(Builder $query): Builder
    {
        return $query
            ->where(static function (Builder $where): void {
                $where->whereNull('awb')->orWhere('awb', '');
            })
            ->where(static function (Builder $where): void {
                $where->whereNull('tracking_number')->orWhere('tracking_number', '');
            })
            ->where(static function (Builder $where): void {
                $where->whereNull('status')->orWhereNotIn('status', self::CLOSED_SHIPMENT_STATUSES);
            })
            ->whereHas('order', static function (Builder $order): void {
                $order
                    ->where(static function (Builder $where): void {
                        $where->whereNull('status')->orWhereNotIn('status', self::values(self::CLOSED_ORDER_STATUSES));
                    })
                    ->where(static function (Builder $where): void {
                        $where->whereNull('payment_status')->orWhereNotIn('payment_status', self::values(self::VOID_PAYMENT_STATUSES));
                    });
            });
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    }

    private static function value(mixed $status): string
    {
        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        return is_scalar($status) ? strtolower(trim((string) $status)) : '';
    }
}
