<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Support;

use Illuminate\Support\Facades\Cache;

/**
 * One-shot hand-over of a fresh tracking answer.
 *
 * The generic `shipping:refresh-tracking` reconciles a registered shipment
 * (ReconcilesShipments) and then refreshes its tracking (TracksShipments)
 * right after: the second step takes the answer the first one just fetched
 * instead of spending another call on KiriminAja's rate-limited Tracking API.
 * An answer is taken at most once and lives at most 30 seconds, so any later
 * refresh reads the API again. Kept in the cache store (not a scoped
 * instance): a sync-queued job between the two steps resets scoped state.
 */
final class KiriminAjaTrackingMemo
{
    private const TTL_SECONDS = 30;

    /**
     * @param  array<string, mixed>  $response
     */
    public function put(string $identifier, array $response): void
    {
        Cache::put(self::key($identifier), $response, now()->addSeconds(self::TTL_SECONDS));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function take(string $identifier): ?array
    {
        $response = Cache::pull(self::key($identifier));

        return is_array($response) ? $response : null;
    }

    private static function key(string $identifier): string
    {
        return 'kiriminaja:tracking-memo:'.sha1(strtolower(trim($identifier)));
    }
}
