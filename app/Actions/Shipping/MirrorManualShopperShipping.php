<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use Illuminate\Support\Collection;
use Shopper\Core\Enum\ShipmentStatus;
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

    public function handle(OrderShipping $row): void
    {
        if (self::$suspended > 0 || ! $row->order_id) {
            return;
        }

        $tracking = trim((string) $row->tracking_number);
        $originalTracking = $row->wasChanged('tracking_number')
            ? trim((string) $row->getOriginal('tracking_number'))
            : '';

        $shipment = $this->target($row, $tracking, $originalTracking);

        if (! $shipment instanceof OrderShipment || $this->capabilities->fulfillerFor($shipment) !== null) {
            return;
        }

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
    private function target(OrderShipping $row, string $tracking, string $originalTracking = ''): ?OrderShipment
    {
        /** @var Collection<int, OrderShipment> $shipments */
        $shipments = OrderShipment::query()
            ->where('order_id', $row->order_id)
            ->orderBy('id')
            ->get();

        foreach ([$tracking, $originalTracking] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $needle = strtolower($candidate);
            $matched = $shipments->first(static fn (OrderShipment $shipment): bool => in_array(
                $needle,
                [strtolower(trim((string) $shipment->awb)), strtolower(trim((string) $shipment->tracking_number))],
                true,
            ));

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
            }
        }

        if ($tracking === '') {
            $sameCarrier = $row->carrier_id
                ? $shipments->filter(static fn (OrderShipment $s): bool => (int) $s->getAttribute('carrier_id') === (int) $row->carrier_id)
                : $shipments;

            if ($sameCarrier->count() === 1) {
                return $sameCarrier->first();
            }

            return $shipments->count() === 1 ? $shipments->first() : null;
        }

        $open = $shipments->filter(static fn (OrderShipment $shipment): bool => ! filled($shipment->awb) && ! filled($shipment->tracking_number));

        if ($open->count() > 1 && $row->carrier_id) {
            $sameCarrier = $open->filter(static fn (OrderShipment $shipment): bool => (int) $shipment->getAttribute('carrier_id') === (int) $row->carrier_id);

            if ($sameCarrier->isNotEmpty()) {
                return $sameCarrier->sortBy('id')->first();
            }
        }

        return $open->count() === 1 ? $open->first() : null;
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
