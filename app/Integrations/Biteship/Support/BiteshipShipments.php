<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Support;

use App\Integrations\Biteship\Biteship;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;

/**
 * Whether a shipment's provider order number (order_shipments.external_reference)
 * is a Biteship order id.
 *
 * Generic code can hand this driver a shipment another provider registered:
 * ShippingCapabilities::trackerFor() falls back to the first configured
 * tracker, and a legacy shipment (driver null) is fulfilled / reconciled by
 * the only fulfilment driver configured. Such a shipment's
 * external_reference is that other provider's order number (e.g. a Komerce
 * order no) and must never be sent to the Biteship Orders / Trackings APIs,
 * cancelled there, or taken as "already registered".
 *
 * The reference is Biteship's when the shipment's driver is `biteship`, or
 * when this module recorded the same id under metadata.biteship.order_id.
 */
final class BiteshipShipments
{
    /**
     * The Biteship order id behind a shipment; null when it has none or its
     * external_reference belongs to another provider.
     */
    public static function orderId(OrderShipment $shipment): ?string
    {
        $reference = ShipmentMetadata::externalReference($shipment);
        $recorded = self::scalar(data_get($shipment->metadata, 'biteship.order_id'));

        if ($reference === null) {
            // external_reference is the canonical column; a bucket id alone
            // (written before the column was saved) still identifies it.
            return $recorded;
        }

        return self::isBiteshipDriver($shipment) || $recorded === $reference ? $reference : null;
    }

    /**
     * True when the shipment carries a provider order number that is NOT a
     * Biteship order (registered with another provider).
     */
    public static function registeredElsewhere(OrderShipment $shipment): bool
    {
        return ShipmentMetadata::externalReference($shipment) !== null && self::orderId($shipment) === null;
    }

    private static function isBiteshipDriver(OrderShipment $shipment): bool
    {
        return strtolower(trim((string) $shipment->getAttribute('driver'))) === Biteship::CODE;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
