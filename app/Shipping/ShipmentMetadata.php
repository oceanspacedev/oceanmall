<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Models\OrderShipment;
use Illuminate\Support\Collection;

/**
 * Provider-neutral keys of order_shipments.metadata that generic code
 * (customer pages, mobile API, cpanel presenter, allocation lock) reads.
 * Shipping drivers keep their raw provider answers in their own bucket
 * (metadata.<driver>) and write these neutral keys next to it:
 *
 *   tracking_history      list<{description, datetime, location}>, newest first or as the provider orders it
 *   fulfillment_error     last error registering the shipment with the provider (removed on success)
 *   pickup_requested_at   ISO-8601 time the provider accepted a pickup request
 *
 * The provider order number lives in the order_shipments.external_reference
 * column, never in metadata.
 */
final class ShipmentMetadata
{
    public const TRACKING_HISTORY = 'tracking_history';

    public const FULFILLMENT_ERROR = 'fulfillment_error';

    public const PICKUP_REQUESTED_AT = 'pickup_requested_at';

    public const RESHIPPED_TO_SHIPMENT_ID = 'reshipped_to_shipment_id';

    public const string RESHIP_OF_SHIPMENT_ID = 'reship_of_shipment_id';

    /**
     * Whether a shipment was superseded by a replacement shipment on the same order.
     *
     * @param  Collection<int, OrderShipment>  $orderShipments
     */
    public static function isSupersededByReship(OrderShipment $shipment, Collection $orderShipments): bool
    {
        $replacementId = data_get($shipment->metadata, self::RESHIPPED_TO_SHIPMENT_ID);

        if (! is_numeric($replacementId)) {
            return false;
        }

        $targetId = (int) $replacementId;

        return $targetId !== (int) $shipment->id
            && $orderShipments->contains(static fn (OrderShipment $candidate): bool => (int) $candidate->id === $targetId);
    }

    /**
     * @return list<array{description: string, datetime: string|null, location: string|null}>
     */
    public static function trackingHistory(OrderShipment $shipment): array
    {
        $raw = data_get($shipment->metadata, self::TRACKING_HISTORY);

        if (! is_array($raw)) {
            return [];
        }

        $history = [];

        foreach ($raw as $event) {
            if (! is_array($event)) {
                continue;
            }

            $description = data_get($event, 'description') ?? data_get($event, 'desc');
            $datetime = data_get($event, 'datetime') ?? data_get($event, 'date');
            $location = data_get($event, 'location');

            $history[] = [
                'description' => is_scalar($description) && trim((string) $description) !== ''
                    ? (string) $description
                    : 'Paket diproses',
                'datetime' => is_scalar($datetime) && trim((string) $datetime) !== '' ? (string) $datetime : null,
                'location' => is_scalar($location) && trim((string) $location) !== '' ? (string) $location : null,
            ];
        }

        return $history;
    }

    public static function fulfillmentError(OrderShipment $shipment): ?string
    {
        $error = data_get($shipment->metadata, self::FULFILLMENT_ERROR);

        return is_string($error) && trim($error) !== '' ? $error : null;
    }

    public static function pickupRequested(OrderShipment $shipment): bool
    {
        return filled(data_get($shipment->metadata, self::PICKUP_REQUESTED_AT));
    }

    /**
     * Provider order number (order_shipments.external_reference), whatever the driver.
     */
    public static function externalReference(OrderShipment $shipment): ?string
    {
        $reference = $shipment->getAttribute('external_reference');

        return is_scalar($reference) && trim((string) $reference) !== '' ? trim((string) $reference) : null;
    }
}
