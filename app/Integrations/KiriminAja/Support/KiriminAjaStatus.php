<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Support;

use App\Actions\Shipping\NormalizeShipmentStatus;

/**
 * KiriminAja package status codes (docs "Status Code") mapped onto the
 * store's stable shipment vocabulary (NormalizeShipmentStatus).
 *
 * Returned-to-sender and "problem" states have no stable equivalent: they map
 * to self::RETURNED / self::PROBLEM, which NormalizeShipmentStatus does not
 * recognise, so the generic tracking refresh keeps the current status while
 * the timeline shows the provider's text.
 */
final class KiriminAjaStatus
{
    public const RETURNED = 'returned';

    public const PROBLEM = 'problem';

    /**
     * Tracking answers use the "Status Code" table (200 delivered, 300
     * cancelled by client, 400 returned...). The booleans of the tracking
     * `details` win over the code: delivered / refunded, and a shipped_at
     * turns an entry code into "in transit".
     *
     * @param  bool  $hasAwb  An AWB exists (entry states then mean "labeled")
     * @param  bool  $shipped  The courier has the parcel (details.shipped_at)
     */
    public static function stable(mixed $code, bool $delivered = false, bool $refunded = false, bool $hasAwb = false, ?string $text = null, bool $shipped = false): string
    {
        if ($delivered) {
            return NormalizeShipmentStatus::DELIVERED;
        }

        if ($refunded) {
            return self::RETURNED;
        }

        $code = is_numeric($code) ? (int) $code : null;

        // Docs "Status Code": entry 100-105 (+ 110 pending direct pickup),
        // transit 106/107/152/210/211, refusals 301/310/311 and the return
        // flow 400-406, problems 333/350/500/555/7xx/9xx, cancelled 300/302.
        $mapped = match (true) {
            $code === null => null,
            $code === 200 => NormalizeShipmentStatus::DELIVERED,
            in_array($code, [300, 302], true) => NormalizeShipmentStatus::CANCELLED,
            in_array($code, [301, 310, 311], true) || ($code >= 400 && $code <= 406) => self::RETURNED,
            in_array($code, [333, 350, 500, 555], true) || ($code >= 700 && $code <= 799) || ($code >= 900 && $code <= 999) => self::PROBLEM,
            in_array($code, [103, 104], true) => NormalizeShipmentStatus::PICKED_UP,
            (($code >= 100 && $code <= 105) || $code === 110) && $shipped => NormalizeShipmentStatus::IN_TRANSIT,
            ($code >= 100 && $code <= 105) || $code === 110 => $hasAwb ? NormalizeShipmentStatus::LABELED : NormalizeShipmentStatus::PENDING,
            $code >= 106 && $code < 300 => NormalizeShipmentStatus::IN_TRANSIT,
            default => null,
        };

        if ($mapped !== null) {
            return $mapped;
        }

        // Without a usable code only an explicit cancellation is read from the
        // provider text (the generic normalizer defaults unknown text to
        // "in transit", which would be wrong for "Paket dibuat").
        if (resolve(NormalizeShipmentStatus::class)->handle($text, null) === NormalizeShipmentStatus::CANCELLED) {
            return NormalizeShipmentStatus::CANCELLED;
        }

        return match (true) {
            $shipped => NormalizeShipmentStatus::IN_TRANSIT,
            $hasAwb => NormalizeShipmentStatus::LABELED,
            default => NormalizeShipmentStatus::PENDING,
        };
    }
}
