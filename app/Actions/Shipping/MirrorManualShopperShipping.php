<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\EnabledCarriers;
use Illuminate\Support\Collection;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderShipping;

/**
 * The reverse of SyncOrderShippingFromShipments, for parcels no provider
 * fulfils (manual carrier, zone option, COD, or no provider configured).
 *
 * Staff ship those with Shopper's own cpanel flow: "Buat Label Pengiriman"
 * creates a Shopper OrderShipping row with the AWB they typed, and "Tambah
 * acara" moves its status (picked up, in transit, delivered). The customer
 * order page, the mobile API and "Pesanan diterima" read the warehouse
 * shipment (order_shipments) instead, so without this mirror the customer saw
 * "Label menunggu" and a pending parcel while the order said "Dikirim", and
 * could not confirm receipt (no AWB).
 *
 * Copies the AWB onto the matching warehouse shipment (only when it has none)
 * and moves its status forward only. Provider-fulfilled shipments are never
 * touched: their provider is the source of truth. Writes made BY the forward
 * sync (SyncOrderShippingFromShipments, which maps statuses more coarsely)
 * are not mirrored back: see whileSyncing().
 */
final class MirrorManualShopperShipping
{
    private static int $suspended = 0;

    private static ?int $scopedTargetShipmentId = null;

    /**
     * Bind an explicit target warehouse shipment id while Shopper's label
     * slide-over saves (so synchronous observers and OrderShipmentCreated
     * listeners mirror onto that exact shipment rather than guessing by carrier).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileTargeting(?int $targetShipmentId, callable $callback): mixed
    {
        $previous = self::$scopedTargetShipmentId;
        self::$scopedTargetShipmentId = $targetShipmentId;

        try {
            return $callback();
        } finally {
            self::$scopedTargetShipmentId = $previous;
        }
    }

    /**
     * Run the warehouse -> Shopper sync without mirroring its own writes back
     * (it maps picked_up / labeled to Shopper's InTransit, which would move a
     * warehouse shipment forward that no one moved).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileSyncing(callable $callback): mixed
    {
        self::$suspended++;

        try {
            return $callback();
        } finally {
            self::$suspended--;
        }
    }

    /** @var array<string, int> forward order of the stable shipment statuses */
    private const RANK = [
        NormalizeShipmentStatus::PENDING => 0,
        NormalizeShipmentStatus::LABELED => 1,
        NormalizeShipmentStatus::PICKED_UP => 2,
        NormalizeShipmentStatus::IN_TRANSIT => 3,
        NormalizeShipmentStatus::DELIVERED => 4,
    ];

    public function __construct(private readonly ShippingCapabilities $capabilities) {}

    public function handle(OrderShipping $row, ?int $targetShipmentId = null): void
    {
        if (self::$suspended > 0 || ! $row->order_id) {
            return;
        }

        $tracking = trim((string) $row->tracking_number);
        $originalTracking = $row->wasChanged('tracking_number')
            ? trim((string) $row->getOriginal('tracking_number'))
            : '';

        $shipment = $this->target($row, $tracking, $originalTracking, $targetShipmentId ?? self::$scopedTargetShipmentId);

        if (! $shipment instanceof OrderShipment) {
            $shipment = $this->createFallback($row, $tracking);
        }

        if (! $shipment instanceof OrderShipment || $this->capabilities->fulfillerFor($shipment) !== null) {
            return;
        }

        $this->syncRecoveredLinesIfBound($shipment, $row);

        $changes = [];

        if ($tracking !== '') {
            if ($originalTracking !== '' && $originalTracking !== $tracking) {
                // Staff corrected a typo on this OrderShipping row: keep the mirrored shipment AWB in sync.
                $changes['awb'] = $tracking;
                $changes['tracking_number'] = $tracking;
            } elseif (! filled($shipment->awb) && ! filled($shipment->tracking_number)) {
                $changes['awb'] = $tracking;
                $changes['tracking_number'] = $tracking;
            }
        }

        $hasAwbAfter = $tracking !== '' || filled($shipment->awb) || filled($shipment->tracking_number);

        if ($hasAwbAfter && is_array($shipment->metadata) && array_key_exists(ShipmentMetadata::FULFILLMENT_ERROR, $shipment->metadata)) {
            $metadata = $shipment->metadata;
            unset($metadata[ShipmentMetadata::FULFILLMENT_ERROR]);
            $changes['metadata'] = $metadata;
        }

        $current = strtolower(trim((string) $shipment->status));
        $next = $this->status($row);

        if ($next !== null && NormalizeShipmentStatus::shouldTransition($current, $next) && $next !== $current) {
            $changes['status'] = $next;
        }

        if ($changes !== []) {
            $shipment->forceFill($changes)->save();
        }
    }

    /**
     * The warehouse shipment this Shopper row belongs to: matched by AWB
     * (current or previous when staff edited a typo), linked order_items, or
     * unambiguous open/carrier-matching shipment.
     */
    private function target(OrderShipping $row, string $tracking, string $originalTracking = '', ?int $targetShipmentId = null): ?OrderShipment
    {
        /** @var Collection<int, OrderShipment> $shipments */
        $shipments = OrderShipment::query()
            ->where('order_id', $row->order_id)
            ->where(static function ($query): void {
                $query->whereNull('status')
                    ->orWhereRaw('lower(status) != ?', [NormalizeShipmentStatus::CANCELLED]);
            })
            ->orderBy('id')
            ->get();

        if ($targetShipmentId !== null) {
            $explicit = $shipments->firstWhere('id', $targetShipmentId);

            if (
                $explicit instanceof OrderShipment
                && $this->capabilities->fulfillerFor($explicit) === null
                && (
                    (! filled($explicit->awb) && ! filled($explicit->tracking_number))
                    || $this->matchesTracking($explicit, $tracking, $originalTracking)
                )
            ) {
                return $explicit;
            }
        }

        foreach ([$tracking, $originalTracking] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $matched = $shipments->first(
                fn (OrderShipment $shipment): bool => $this->matchesTracking($shipment, $candidate),
            );

            if ($matched instanceof OrderShipment) {
                return $matched;
            }
        }

        // Match via order_items.order_shipping_id when label has no tracking number (local delivery).
        $order = $row->order()->with('items')->first();
        if ($order !== null) {
            $linkedItems = $order->items->where('order_shipping_id', $row->id);
            if ($linkedItems->isNotEmpty()) {
                $shipments->loadMissing('lines');
                $byItem = $shipments->filter(static function (OrderShipment $s) use ($linkedItems): bool {
                    foreach ($s->lines as $line) {
                        if ($linkedItems->contains(static fn ($item): bool => (int) $item->product_id === (int) $line->purchasable_id && (string) $item->product_type === (string) $line->purchasable_type)) {
                            return true;
                        }
                    }

                    return false;
                });

                if ($byItem->count() === 1) {
                    return $byItem->first();
                }

                $manualByItem = $byItem->filter(fn (OrderShipment $s): bool => $this->capabilities->fulfillerFor($s) === null);
                if ($manualByItem->count() === 1) {
                    return $manualByItem->first();
                }
            }
        }

        $manualShipments = $shipments->filter(
            fn (OrderShipment $shipment): bool => $this->capabilities->fulfillerFor($shipment) === null,
        );

        if ($tracking === '') {
            $sameCarrier = $row->carrier_id
                ? $manualShipments->filter(static fn (OrderShipment $s): bool => (int) $s->getAttribute('carrier_id') === (int) $row->carrier_id)
                : $manualShipments;

            if ($sameCarrier->count() === 1) {
                return $sameCarrier->first();
            }

            return $manualShipments->count() === 1 ? $manualShipments->first() : null;
        }

        $open = $manualShipments->filter(static fn (OrderShipment $shipment): bool => ! filled($shipment->awb) && ! filled($shipment->tracking_number));

        if ($open->count() > 1 && $row->carrier_id) {
            $sameCarrier = $open->filter(static fn (OrderShipment $shipment): bool => (int) $shipment->getAttribute('carrier_id') === (int) $row->carrier_id);

            if ($sameCarrier->isNotEmpty()) {
                return $sameCarrier->sortBy('id')->first();
            }
        }

        return $open->count() === 1 ? $open->first() : null;
    }

    /**
     * When all existing warehouse shipments on an active order were cancelled,
     * a genuine manual recovery OrderShipping spawns a live manual
     * OrderShipment so customer views, label printing, and receipt
     * confirmation have an active parcel to act on.
     */
    private function createFallback(OrderShipping $row, string $tracking): ?OrderShipment
    {
        $existingShipments = OrderShipment::query()
            ->with('lines')
            ->where('order_id', $row->order_id)
            ->orderBy('id')
            ->get();

        if ($existingShipments->isEmpty()) {
            return null;
        }

        $hasActive = $existingShipments->contains(
            static fn (OrderShipment $s): bool => strtolower(trim((string) $s->status)) !== NormalizeShipmentStatus::CANCELLED,
        );

        if ($hasActive) {
            return null;
        }

        $order = $row->order()->with(['items', 'shippingOption.carrier'])->first();

        if (! $order instanceof Order || $order->status === OrderStatus::Cancelled) {
            return null;
        }

        if ($tracking !== '') {
            $needle = strtolower($tracking);
            $matchesCancelledAwb = $existingShipments->contains(static fn (OrderShipment $s): bool => in_array(
                $needle,
                [strtolower(trim((string) $s->awb)), strtolower(trim((string) $s->tracking_number))],
                true,
            ));

            if ($matchesCancelledAwb) {
                return null;
            }
        } elseif (! $row->items()->exists()) {
            return null;
        }

        /** @var OrderShipment|null $template */
        $template = $existingShipments->first();
        $inventoryId = $template?->inventory_id
            ?? Inventory::query()->where('is_default', true)->value('id')
            ?? Inventory::query()->value('id');

        if (! $inventoryId) {
            return null;
        }

        $carrier = $row->carrier_id ? Carrier::query()->find($row->carrier_id) : null;
        $carrierId = $carrier?->id ?? $template?->carrier_id;
        $carrierCode = $carrier !== null
            ? (EnabledCarriers::courierCode($carrier) ?: (string) $carrier->slug)
            : ($template?->carrier_code ?: 'manual');
        $carrierName = $carrier?->name ?? $template?->carrier_name ?? 'Manual';

        $shippingOption = $order->shippingOption;
        $serviceCode = $template?->service_code
            ?: ($shippingOption !== null
                ? (string) (data_get($shippingOption->metadata, 'service_code') ?: ($shippingOption->name ?: $shippingOption->id))
                : 'Reguler');
        $serviceName = $template?->service_name
            ?: ($shippingOption?->name ?? $serviceCode);

        $cost = (int) $existingShipments->sum('cost');
        if ($cost <= 0) {
            $cost = (int) ($order->shipping_total ?? $shippingOption?->price ?? 0);
        }

        $created = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventoryId,
            'driver' => 'manual',
            'carrier_id' => $carrierId,
            'carrier_code' => $carrierCode,
            'carrier_name' => $carrierName,
            'service_code' => $serviceCode,
            'service_name' => $serviceName,
            'cost' => $cost,
            'currency_code' => $order->currency_code ?: ($template?->currency_code ?: 'IDR'),
            'status' => $this->status($row) ?? NormalizeShipmentStatus::LABELED,
            'awb' => $tracking !== '' ? $tracking : null,
            'tracking_number' => $tracking !== '' ? $tracking : null,
            'metadata' => [
                'recovered_from_order_shipping_id' => (int) $row->id,
            ],
        ]);

        $this->seedFallbackLines($created, $row, $order, $existingShipments);

        return $created;
    }

    /**
     * @param  Collection<int, OrderShipment>  $cancelledShipments
     */
    private function seedFallbackLines(
        OrderShipment $shipment,
        OrderShipping $row,
        Order $order,
        Collection $cancelledShipments,
    ): void {
        $boundItems = $row->items()->get();

        if ($boundItems->isNotEmpty()) {
            $this->replaceLinesFromOrderItems($shipment, $boundItems);

            return;
        }

        $aggregated = [];

        foreach ($cancelledShipments as $cancelled) {
            foreach ($cancelled->lines as $line) {
                $key = $line->purchasable_type.'|'.$line->purchasable_id;

                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'purchasable_type' => (string) $line->purchasable_type,
                        'purchasable_id' => (int) $line->purchasable_id,
                        'qty' => 0,
                    ];
                }

                $aggregated[$key]['qty'] += max(1, (int) $line->qty);
            }
        }

        if ($aggregated !== []) {
            foreach ($aggregated as $lineData) {
                $shipment->lines()->create($lineData);
            }

            return;
        }

        $this->replaceLinesFromOrderItems($shipment, $order->items);
    }

    private function syncRecoveredLinesIfBound(OrderShipment $shipment, OrderShipping $row): void
    {
        if ((int) data_get($shipment->metadata, 'recovered_from_order_shipping_id') !== (int) $row->id) {
            return;
        }

        $boundItems = $row->items()->get();

        if ($boundItems->isEmpty()) {
            return;
        }

        $this->replaceLinesFromOrderItems($shipment, $boundItems);
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function replaceLinesFromOrderItems(OrderShipment $shipment, Collection $items): void
    {
        $aggregated = [];

        foreach ($items as $item) {
            $key = $item->product_type.'|'.$item->product_id;

            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'purchasable_type' => (string) $item->product_type,
                    'purchasable_id' => (int) $item->product_id,
                    'qty' => 0,
                ];
            }

            $aggregated[$key]['qty'] += max(1, (int) $item->quantity);
        }

        $shipment->lines()->delete();

        foreach ($aggregated as $lineData) {
            $shipment->lines()->create($lineData);
        }
    }

    private function matchesTracking(OrderShipment $shipment, string $tracking, string $originalTracking = ''): bool
    {
        $awb = strtolower(trim((string) $shipment->awb));
        $number = strtolower(trim((string) $shipment->tracking_number));

        foreach ([$tracking, $originalTracking] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (in_array(strtolower($candidate), [$awb, $number], true)) {
                return true;
            }
        }

        return false;
    }

    private function status(OrderShipping $row): ?string
    {
        $status = $row->status instanceof ShipmentStatus
            ? $row->status
            : ShipmentStatus::tryFrom((string) $row->status);

        return match ($status) {
            ShipmentStatus::Pending => NormalizeShipmentStatus::LABELED,
            ShipmentStatus::PickedUp => NormalizeShipmentStatus::PICKED_UP,
            ShipmentStatus::InTransit, ShipmentStatus::AtSortingCenter, ShipmentStatus::OutForDelivery => NormalizeShipmentStatus::IN_TRANSIT,
            ShipmentStatus::Returned => NormalizeShipmentStatus::RETURNED,
            ShipmentStatus::Delivered => NormalizeShipmentStatus::DELIVERED,
            default => null,
        };
    }
}
