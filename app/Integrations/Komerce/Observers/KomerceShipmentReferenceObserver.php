<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Observers;

use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Support\KomerceShipments;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;

/**
 * Komerce code keeps its raw provider state in metadata.komerce. Mirror what
 * generic code needs onto the provider-neutral places (App\Shipping\ShipmentMetadata):
 *
 *   komerce.order_no          -> order_shipments.external_reference
 *   komerce.tracking_history  -> metadata.tracking_history
 *   komerce.fulfillment_error -> metadata.fulfillment_error (removed with it)
 *   komerce.pickup_response   -> metadata.pickup_requested_at
 *
 * order_shipments.driver stays the driver that quoted the selected rate (a
 * RajaOngkir-quoted parcel stays 'rajaongkir'; ShippingCapabilities::fulfillerFor()
 * routes it to Komerce Delivery through DelegatesFulfillment). Only a legacy
 * shipment with no driver recorded is stamped 'komerce' once registered.
 */
final class KomerceShipmentReferenceObserver
{
    public function saving(OrderShipment $shipment): void
    {
        $metadata = is_array($shipment->metadata) ? $shipment->metadata : null;
        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : null;

        // A shipment another active module owns keeps its own reference and
        // neutral metadata, whatever Komerce wrote into it.
        if ($metadata === null || $komerce === null || ! KomerceShipments::owns($shipment)) {
            return;
        }

        $orderNo = $komerce['order_no'] ?? null;
        $orderNo = is_scalar($orderNo) ? trim((string) $orderNo) : '';

        if ($orderNo !== '') {
            if ($shipment->getAttribute('external_reference') !== $orderNo) {
                $shipment->setAttribute('external_reference', $orderNo);
            }

            if (! filled($shipment->getAttribute('driver'))) {
                $shipment->setAttribute('driver', KomerceShippingDriver::CODE);
            }
        }

        $mirrored = $metadata;

        if (is_array($komerce['tracking_history'] ?? null) && $komerce['tracking_history'] !== []) {
            $mirrored[ShipmentMetadata::TRACKING_HISTORY] = array_values($komerce['tracking_history']);
        }

        $error = $komerce['fulfillment_error'] ?? null;

        if (is_string($error) && trim($error) !== '') {
            $mirrored[ShipmentMetadata::FULFILLMENT_ERROR] = $error;
        } else {
            unset($mirrored[ShipmentMetadata::FULFILLMENT_ERROR]);
        }

        if (is_array($komerce['pickup_response'] ?? null) && ! filled($mirrored[ShipmentMetadata::PICKUP_REQUESTED_AT] ?? null)) {
            $requestedAt = $komerce['pickup_requested_at'] ?? $komerce['pickup_at'] ?? null;
            $mirrored[ShipmentMetadata::PICKUP_REQUESTED_AT] = is_string($requestedAt) && $requestedAt !== ''
                ? $requestedAt
                : now()->toIso8601String();
        }

        if ($mirrored !== $metadata) {
            $shipment->setAttribute('metadata', $mirrored);
        }
    }
}
