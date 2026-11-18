<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use App\Models\OrderShipment;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;

/**
 * For drivers whose tracking needs more than the AWB that Shopper's
 * ShippingDriver::track(string) receives (courier code, receiver phone...).
 *
 * The driver stores the customer-facing timeline under the neutral
 * metadata.tracking_history key (App\Shipping\ShipmentMetadata) and may keep
 * its raw answer in its own metadata bucket.
 */
interface TracksShipments
{
    public function trackShipment(OrderShipment $shipment): TrackingInfo;
}
