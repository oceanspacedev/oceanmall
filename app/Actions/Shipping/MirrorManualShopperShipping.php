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
        if (self::$suspended > 0) {
            return;
        }

        $tracking = trim((string) $row->tracking_number);

        if ($tracking === '' || ! $row->order_id) {
            return;
        }

        $shipment = $this->target($row, $tracking);

        if (! $shipment instanceof OrderShipment || $this->capabilities->fulfillerFor($shipment) !== null) {
            return;
        }

        $changes = [];

        if (! filled($shipment->awb) && ! filled($shipment->tracking_number)) {
            $changes['awb'] = $tracking;
            $changes['tracking_number'] = $tracking;
        }

        $current = strtolower(trim((string) $shipment->status));
        $next = $this->status($row);

        if (
            $next !== null
            && isset(self::RANK[$current])
            && self::RANK[$next] > self::RANK[$current]
        ) {
            $changes['status'] = $next;
        }

        if ($changes !== []) {
            $shipment->forceFill($changes)->save();
        }
    }

    /**
     * The warehouse shipment this Shopper row belongs to: the one already
     * carrying its AWB; otherwise the only one without an AWB (preferring the
     * same carrier when an order has several). Null when ambiguous.
     */
    private function target(OrderShipping $row, string $tracking): ?OrderShipment
    {
        /** @var Collection<int, OrderShipment> $shipments */
        $shipments = OrderShipment::query()
            ->where('order_id', $row->order_id)
            ->orderBy('id')
            ->get();

        $needle = strtolower($tracking);
        $matched = $shipments->first(static fn (OrderShipment $shipment): bool => in_array(
            $needle,
            [strtolower(trim((string) $shipment->awb)), strtolower(trim((string) $shipment->tracking_number))],
            true,
        ));

        if ($matched instanceof OrderShipment) {
            return $matched;
        }

        $open = $shipments->filter(static fn (OrderShipment $shipment): bool => ! filled($shipment->awb) && ! filled($shipment->tracking_number));

        if ($open->count() > 1 && $row->carrier_id) {
            $sameCarrier = $open->filter(static fn (OrderShipment $shipment): bool => (int) $shipment->getAttribute('carrier_id') === (int) $row->carrier_id);

            if ($sameCarrier->isNotEmpty()) {
                $open = $sameCarrier;
            }
        }

        return $open->count() === 1 ? $open->first() : null;
    }

    /**
     * Shopper's shipment status as a stable warehouse shipment status; null
     * for states with no forward equivalent (failed attempt, returned).
     */
    private function status(OrderShipping $row): ?string
    {
        $status = $row->status instanceof ShipmentStatus
            ? $row->status
            : ShipmentStatus::tryFrom((string) $row->status);

        return match ($status) {
            ShipmentStatus::Pending => NormalizeShipmentStatus::LABELED,
            ShipmentStatus::PickedUp => NormalizeShipmentStatus::PICKED_UP,
            ShipmentStatus::InTransit, ShipmentStatus::AtSortingCenter, ShipmentStatus::OutForDelivery => NormalizeShipmentStatus::IN_TRANSIT,
            ShipmentStatus::Delivered => NormalizeShipmentStatus::DELIVERED,
            default => null,
        };
    }
}
