<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Enums\OrderNotificationType;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShipmentTimeline;
use App\Support\EnabledCarriers;
use App\Support\OrderPaymentType;
use Illuminate\Support\Carbon;
use Shopper\Core\Enum\FulfillmentStatus;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderCompleted;
use Shopper\Core\Events\Orders\OrderShipmentCreated;
use Shopper\Core\Events\Orders\OrderShipmentDelivered;
use Shopper\Core\Events\Orders\OrderShipped;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Throwable;

/**
 * Mirror the provider-fulfilled warehouse shipments (order_shipments) into
 * Shopper's OrderShipping rows and the order's shipping status, firing
 * Shopper's shipment events on each transition so listeners work the same as
 * for Shopper's own label flow:
 *
 * - OrderShipmentCreated when a parcel's OrderShipping row is first created,
 * - OrderShipped when the order becomes fully shipped (also when it jumps
 *   straight to delivered),
 * - OrderShipmentDelivered when a parcel becomes delivered,
 * - OrderCompleted when every parcel is delivered and the order completes.
 *
 * Each transition is claimed with a conditional UPDATE, so concurrent syncs
 * (a webhook racing a tracking refresh) fire every event once. Provider scans
 * from metadata.tracking_history are also recorded as Shopper
 * OrderShippingEvent rows (mirrorProviderEvents) for the cpanel event log.
 *
 * Shopper's MarkShipmentDeliveredAction / RecordShipmentEventAction are not
 * used: their state machine only allows OutForDelivery -> Delivered, while
 * provider tracking jumps from in transit straight to delivered.
 *
 * Known gap: an order going unfulfilled -> partially delivered -> delivered
 * never fires OrderShipped (the "all shipped" moment in between is not
 * observable from the aggregate status).
 */
final class SyncOrderShippingFromShipments
{
    public function __construct(
        private readonly NotifyOrderCustomer $notifyOrderCustomer,
        private readonly NormalizeShipmentStatus $normalizeStatus,
    ) {}

    public function handle(Order $order): Order
    {
        // Its own OrderShipping writes are not mirrored back onto the
        // warehouse shipments it reads (MirrorManualShopperShipping).
        return MirrorManualShopperShipping::whileSyncing(fn (): Order => $this->sync($order));
    }

    private function sync(Order $order): Order
    {
        $shipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->get();

        if ($shipments->isEmpty()) {
            return $order;
        }

        foreach ($shipments as $shipment) {
            $this->syncShopperOrderShipping($order, $shipment);
        }

        $statuses = $shipments->pluck('status')
            ->map(static fn (mixed $status): string => strtolower(trim((string) $status)))
            ->filter(static fn (string $status): bool => $status !== '' && $status !== NormalizeShipmentStatus::CANCELLED)
            ->values()
            ->all();

        $previousShipping = $order->shipping_status;
        $shippingStatus = $statuses === []
            ? ShippingStatus::Unfulfilled
            : $this->aggregateShippingStatus($statuses);

        // Conditional updates claim each transition atomically: a webhook racing
        // a tracking refresh cannot fire the same events or notifications twice.
        $transitioned = Order::query()
            ->whereKey($order->id)
            ->where(static function ($query) use ($shippingStatus): void {
                $query->whereNull('shipping_status')->orWhere('shipping_status', '!=', $shippingStatus->value);
            })
            ->update(['shipping_status' => $shippingStatus->value, 'updated_at' => now()]) > 0;

        $completed = $shippingStatus === ShippingStatus::Delivered
            && (! OrderPaymentType::isCod($order) || $order->payment_status === PaymentStatus::Paid)
            && Order::query()
                ->whereKey($order->id)
                ->whereIn('status', [OrderStatus::New->value, OrderStatus::Processing->value])
                ->update(['status' => OrderStatus::Completed->value, 'updated_at' => now()]) > 0;

        $order = $order->refresh();

        // Fully shipped, including a jump straight to delivered. Not fired again
        // when a shipped order moves on to (partially) delivered.
        if (
            $transitioned
            && in_array($shippingStatus, [ShippingStatus::Shipped, ShippingStatus::Delivered], true)
            && ! in_array($previousShipping, [ShippingStatus::Shipped, ShippingStatus::Delivered, ShippingStatus::PartiallyDelivered], true)
        ) {
            event(new OrderShipped($order));
        }

        if ($completed) {
            event(new OrderCompleted($order));
        }

        if ($transitioned) {
            if (in_array($shippingStatus, [ShippingStatus::Shipped, ShippingStatus::PartiallyShipped], true)) {
                $this->notifyOrderCustomer->handle($order, OrderNotificationType::Shipped);
            }

            if (in_array($shippingStatus, [ShippingStatus::Delivered, ShippingStatus::PartiallyDelivered], true)) {
                $this->notifyOrderCustomer->handle($order, OrderNotificationType::Delivered);
            }
        }

        return $order;
    }

    /**
     * @param  list<string>  $statuses
     */
    private function aggregateShippingStatus(array $statuses): ShippingStatus
    {
        $total = count($statuses);
        $delivered = count(array_filter(
            $statuses,
            static fn (string $status): bool => $status === NormalizeShipmentStatus::DELIVERED,
        ));
        $returned = count(array_filter(
            $statuses,
            static fn (string $status): bool => $status === NormalizeShipmentStatus::RETURNED,
        ));
        $active = count(array_filter(
            $statuses,
            static fn (string $status): bool => in_array($status, [
                NormalizeShipmentStatus::LABELED,
                NormalizeShipmentStatus::PICKED_UP,
                NormalizeShipmentStatus::IN_TRANSIT,
                NormalizeShipmentStatus::DELIVERY_FAILED,
                NormalizeShipmentStatus::DELIVERED,
            ], true),
        ));

        if ($returned === $total && $total > 0) {
            return ShippingStatus::Returned;
        }

        if ($returned > 0 && $delivered === 0) {
            return ShippingStatus::PartiallyReturned;
        }

        if ($delivered === $total && $total > 0) {
            return ShippingStatus::Delivered;
        }

        if ($delivered > 0) {
            return ShippingStatus::PartiallyDelivered;
        }

        if ($active === $total && $active > 0) {
            return ShippingStatus::Shipped;
        }

        if ($active > 0) {
            return ShippingStatus::PartiallyShipped;
        }

        return ShippingStatus::Unfulfilled;
    }

    /**
     * The Shopper carrier of a shipment: the one recorded with the selected
     * rate (order_shipments.carrier_id); for shipments created before that,
     * the carrier with the shipment's courier code, preferring the one its
     * own driver owns. Null rather than an unrelated carrier.
     */
    private function carrierId(OrderShipment $shipment): ?int
    {
        $recorded = $shipment->getAttribute('carrier_id');

        if (is_numeric($recorded) && Carrier::query()->whereKey((int) $recorded)->exists()) {
            return (int) $recorded;
        }

        $carrierCode = strtolower(trim((string) ($shipment->carrier_code ?: $shipment->carrier_name)));

        if ($carrierCode === '') {
            return null;
        }

        $byCode = Carrier::query()->orderBy('id')->get()
            ->filter(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $carrierCode);
        $match = $byCode->firstWhere('driver', $shipment->driver) ?? $byCode->first();

        return $match instanceof Carrier ? (int) $match->id : null;
    }

    private function syncShopperOrderShipping(Order $order, OrderShipment $shipment): void
    {
        $awb = $shipment->awb ?? $shipment->tracking_number;
        if (! $awb) {
            return;
        }

        $carrierId = $this->carrierId($shipment);

        $status = match (strtolower((string) $shipment->status)) {
            'delivered' => ShipmentStatus::Delivered,
            'picked_up', 'in_transit', 'labeled' => ShipmentStatus::InTransit,
            'delivery_failed', 'failed' => ShipmentStatus::DeliveryFailed,
            'returned' => ShipmentStatus::Returned,
            default => ShipmentStatus::Pending,
        };

        $itemFulfillmentStatus = match (strtolower((string) $shipment->status)) {
            'delivered' => FulfillmentStatus::Delivered,
            'picked_up', 'in_transit', 'labeled', 'delivery_failed', 'failed' => FulfillmentStatus::Shipped,
            'returned', 'cancelled' => FulfillmentStatus::Cancelled,
            default => FulfillmentStatus::Processing,
        };

        $orderShipping = OrderShipping::query()->firstOrCreate(
            [
                'order_id' => $order->id,
                'tracking_number' => $awb,
            ],
            [
                'carrier_id' => $carrierId,
                'status' => $status,
                'tracking_url' => route('account.orders.show', $order),
                'shipped_at' => now(),
            ],
        );

        $created = $orderShipping->wasRecentlyCreated;

        if (! $created) {
            $orderShipping->forceFill([
                'carrier_id' => $carrierId,
                'tracking_url' => route('account.orders.show', $order),
                'shipped_at' => $orderShipping->shipped_at ?? now(),
            ])->save();
        }

        // Claim the status change atomically, so a webhook racing a tracking
        // refresh fires OrderShipmentDelivered once.
        $statusChanged = $created || OrderShipping::query()
            ->whereKey($orderShipping->id)
            ->where(static function ($query) use ($status): void {
                $query->whereNull('status')->orWhere('status', '!=', $status->value);
            })
            ->update(['status' => $status->value, 'updated_at' => now()]) > 0;

        $orderShipping->refresh();

        if ($created) {
            event(new OrderShipmentCreated($order, $orderShipping));
        }

        if ($status === ShipmentStatus::Delivered && $statusChanged) {
            if ($orderShipping->received_at === null) {
                $orderShipping->forceFill(['received_at' => now()])->save();
            }

            event(new OrderShipmentDelivered($order, $orderShipping));
        }

        if ($status === ShipmentStatus::Returned && $statusChanged && $orderShipping->returned_at === null) {
            $orderShipping->forceFill(['returned_at' => now()])->save();
        }

        $this->mirrorProviderEvents($shipment, $orderShipping);

        $order->loadMissing('items');
        $shipment->loadMissing('lines');

        foreach ($shipment->lines as $line) {
            $order->items()
                ->where('product_id', $line->purchasable_id)
                ->where('product_type', $line->purchasable_type)
                ->where(static function ($query) use ($orderShipping): void {
                    $query->whereNull('order_shipping_id')->orWhere('order_shipping_id', $orderShipping->id);
                })
                ->update([
                    'order_shipping_id' => $orderShipping->id,
                    'fulfillment_status' => $itemFulfillmentStatus,
                ]);
        }
    }

    /**
     * Record the provider's scans (metadata.tracking_history) as Shopper
     * OrderShippingEvent rows, so the cpanel shipment event log shows them.
     * Deduplicated per status + time + description; undated scans are kept in
     * metadata only (an event needs occurred_at). Rows are tagged
     * metadata.source = 'provider' so ShipmentTimeline does not show them twice.
     */
    private function mirrorProviderEvents(OrderShipment $shipment, OrderShipping $orderShipping): void
    {
        $history = ShipmentMetadata::trackingHistory($shipment);

        if ($history === []) {
            return;
        }

        $timezone = (string) config('app.timezone');
        $seen = [];

        foreach ($orderShipping->events()->get() as $existing) {
            $existingStatus = $existing->status instanceof ShipmentStatus ? $existing->status->value : (string) $existing->status;
            $seen[$this->eventKey($existingStatus, $existing->occurred_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i'), (string) $existing->description)] = true;
        }

        // Providers list newest first; record oldest first.
        foreach (array_reverse($history) as $scan) {
            if ($scan['datetime'] === null) {
                continue;
            }

            try {
                $occurredAt = Carbon::parse($scan['datetime'], $timezone);
            } catch (Throwable) {
                continue;
            }

            $status = $this->eventStatus($scan['description']);
            $key = $this->eventKey($status->value, $occurredAt->format('Y-m-d H:i'), $scan['description']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $orderShipping->logEvent($status, [
                'description' => $scan['description'],
                'location' => $scan['location'],
                'occurred_at' => $occurredAt,
                'metadata' => ['source' => ShipmentTimeline::PROVIDER_SOURCE, 'driver' => $shipment->driver],
            ]);
        }
    }

    private function eventKey(string $status, ?string $occurredAt, string $description): string
    {
        return $status.'|'.$occurredAt.'|'.strtolower(trim($description));
    }

    private function eventStatus(string $description): ShipmentStatus
    {
        $lower = strtolower($description);

        if (str_contains($lower, 'out for delivery') || str_contains($lower, 'out_for_delivery') || str_contains($lower, 'diantar')) {
            return ShipmentStatus::OutForDelivery;
        }

        if (str_contains($lower, 'return') || str_contains($lower, 'retur')) {
            return ShipmentStatus::Returned;
        }

        return match ($this->normalizeStatus->handle($description)) {
            NormalizeShipmentStatus::DELIVERED => ShipmentStatus::Delivered,
            NormalizeShipmentStatus::PICKED_UP => ShipmentStatus::PickedUp,
            NormalizeShipmentStatus::PENDING, NormalizeShipmentStatus::LABELED => ShipmentStatus::Pending,
            // Failed / undelivered attempts normalise to null (the courier retries).
            null => ShipmentStatus::DeliveryFailed,
            default => ShipmentStatus::InTransit,
        };
    }
}
