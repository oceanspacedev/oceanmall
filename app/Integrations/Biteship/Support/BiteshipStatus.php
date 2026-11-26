<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Support;

use App\Actions\Shipping\NormalizeShipmentStatus;

/**
 * Biteship order / tracking statuses (https://biteship.com/id/docs/api/trackings/status)
 * mapped onto the store's stable shipment vocabulary (NormalizeShipmentStatus::STABLE).
 *
 * The docs write them in camelCase (pickingUp, droppingOff, courierNotFound)
 * while API answers use snake_case (picking_up, dropping_off): both are
 * accepted by comparing lower-case letters only.
 *
 *   confirmed, scheduled, allocated, picking_up   -> labeled  (registered, waiting for pickup)
 *   picked                                        -> picked_up
 *   in_transit, dropping_off, on_hold,
 *   return_in_transit                             -> in_transit
 *   delivered                                     -> delivered
 *   cancelled, rejected, courier_not_found,
 *   returned, disposed                            -> cancelled (the parcel will not reach the customer)
 *
 * Anything else is unknown (null): callers keep the current status.
 */
final class BiteshipStatus
{
    /** @var array<string, string> letters-only status => stable status */
    private const MAP = [
        'confirmed' => NormalizeShipmentStatus::LABELED,
        'scheduled' => NormalizeShipmentStatus::LABELED,
        'allocated' => NormalizeShipmentStatus::LABELED,
        'pickingup' => NormalizeShipmentStatus::LABELED,
        'picked' => NormalizeShipmentStatus::PICKED_UP,
        'intransit' => NormalizeShipmentStatus::IN_TRANSIT,
        'droppingoff' => NormalizeShipmentStatus::IN_TRANSIT,
        'onhold' => NormalizeShipmentStatus::IN_TRANSIT,
        'returnintransit' => NormalizeShipmentStatus::IN_TRANSIT,
        'delivered' => NormalizeShipmentStatus::DELIVERED,
        'cancelled' => NormalizeShipmentStatus::CANCELLED,
        'canceled' => NormalizeShipmentStatus::CANCELLED,
        'rejected' => NormalizeShipmentStatus::CANCELLED,
        'couriernotfound' => NormalizeShipmentStatus::CANCELLED,
        'returned' => NormalizeShipmentStatus::RETURNED,
        'disposed' => NormalizeShipmentStatus::RETURNED,
    ];

    public static function normalize(mixed $raw): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }

        $key = preg_replace('/[^a-z]/', '', strtolower((string) $raw)) ?? '';

        return self::MAP[$key] ?? null;
    }

    /**
     * Statuses only move forward; terminal states stay terminal.
     */
    public static function shouldReplace(mixed $current, string $incoming): bool
    {
        return NormalizeShipmentStatus::shouldTransition(
            is_string($current) ? $current : null,
            $incoming,
        );
    }
}
