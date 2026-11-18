<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use App\Models\OrderShipment;

/**
 * A driver that registers a warehouse shipment with the provider (store
 * order, pickup, AWB). The driver owns the shipment's provider state and sets
 * `order_shipments.external_reference` / `awb`. Generic code (allocation
 * lock, cpanel cards, retry cron) reads only those columns and the neutral
 * metadata keys of App\Shipping\ShipmentMetadata (fulfillment_error,
 * pickup_requested_at, tracking_history).
 *
 * Named fulfillShipment() because Shopper's ShippingDriver already defines
 * createShipment(Address, Address, array, string).
 */
interface FulfillsShipments
{
    public function fulfillShipment(OrderShipment $shipment): void;
}
