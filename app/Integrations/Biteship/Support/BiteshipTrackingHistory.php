<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Shopper\Shipping\DataTransferObjects\TrackingEvent;
use Throwable;

/**
 * Biteship `history[]` entries ({note, status, updated_at, service_type})
 * as the neutral metadata.tracking_history rows (App\Shipping\ShipmentMetadata)
 * and as Shopper TrackingEvents. Biteship lists history oldest first; the
 * stored timeline is newest first, like the other drivers' providers.
 */
final class BiteshipTrackingHistory
{
    /**
     * @param  array<int, mixed>  $entries
     * @return list<array{description: string, datetime: string|null, location: string|null}>
     */
    public static function fromEntries(array $entries): array
    {
        $rows = [];

        foreach (self::sorted($entries) as $entry) {
            $at = self::occurredAt($entry);

            $rows[] = [
                'description' => self::description($entry),
                'datetime' => $at?->format('Y-m-d H:i'),
                'location' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return list<TrackingEvent>
     */
    public static function events(array $entries): array
    {
        $events = [];

        foreach (array_reverse(self::sorted($entries)) as $entry) {
            $status = $entry['status'] ?? '';

            $events[] = new TrackingEvent(
                status: is_scalar($status) ? (string) $status : '',
                description: self::description($entry),
                occurredAt: self::occurredAt($entry) ?? now(),
            );
        }

        return $events;
    }

    /**
     * Valid entries, newest first.
     *
     * @param  array<int, mixed>  $entries
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry)));

        usort($valid, static function (array $a, array $b): int {
            $at = self::occurredAt($a)?->getTimestamp() ?? 0;
            $bt = self::occurredAt($b)?->getTimestamp() ?? 0;

            return $bt <=> $at;
        });

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function description(array $entry): string
    {
        foreach (['note', 'description', 'status'] as $key) {
            $value = $entry[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return 'Paket diproses';
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function occurredAt(array $entry): ?DateTimeInterface
    {
        $value = $entry['updated_at'] ?? $entry['eventDate'] ?? $entry['date'] ?? null;

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->setTimezone(config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
