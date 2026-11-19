<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\KiriminAja\KiriminAja;
use App\Integrations\KiriminAja\Services\KiriminAjaTracker;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Models\OrderShipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receiver for KiriminAja express callbacks
 * (`processed_packages`, `shipped_packages`, `canceled_packages`,
 * `finished_packages`, `returned_packages`, `problem_packages`, ...):
 * `{method, data: [{order_id, awb, ...}], payment?, packages?}`.
 *
 * Authenticity: KiriminAja sends `Authorization: Bearer {api_key}` with the
 * account's own key, compared in constant time. When
 * KIRIMINAJA_WEBHOOK_SECRET is set, `kiriminaja:set-callback` registers the
 * URL with `?token=<secret>` and a matching token is accepted as well.
 *
 * The body is only a signal: each parcel is re-read from the Tracking API
 * with the store's key (KiriminAjaTracker::reconcile) before the shipment
 * changes, so a replayed or forged body cannot move a shipment. Duplicate
 * deliveries of the same event are skipped (and re-reading is idempotent
 * anyway). A failed re-read answers 500 so KiriminAja may retry; the hourly
 * `shipping:refresh-tracking` reconciles regardless.
 */
final class KiriminAjaWebhookController extends Controller
{
    public function __invoke(Request $request, KiriminAjaTracker $tracker): JsonResponse
    {
        if (! self::authentic($request)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $method = is_scalar($request->input('method')) ? trim((string) $request->input('method')) : '';
        $items = $request->input('data');

        if (! is_array($items) || ! array_is_list($items)) {
            return response()->json(['status' => 'ignored']);
        }

        $handled = 0;
        $failed = 0;

        foreach (array_slice($items, 0, 100) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $orderId = is_scalar($item['order_id'] ?? null) ? trim((string) $item['order_id']) : '';
            $awb = is_scalar($item['awb'] ?? null) ? trim((string) $item['awb']) : '';
            $shipment = self::shipment($orderId, $awb);

            if ($shipment === null) {
                continue;
            }

            $key = 'kiriminaja:callback:'.sha1(implode('|', [
                $method,
                $orderId,
                $awb,
                ...array_map(
                    static fn (string $field): string => is_scalar($item[$field] ?? null) ? (string) $item[$field] : '',
                    ['date', 'shipped_at', 'finished_at', 'returned_at', 'rejected_at', 'reason'],
                ),
            ]));

            if (Cache::has($key)) {
                $handled++;

                continue;
            }

            try {
                $shipment = $tracker->reconcile($shipment);
                $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
                $bucket = is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : [];
                $bucket['callback_method'] = $method;
                $bucket['callback_received_at'] = now()->toIso8601String();
                $metadata['kiriminaja'] = $bucket;
                $shipment->forceFill(['metadata' => $metadata])->save();

                Cache::put($key, true, now()->addDays(7));
                $handled++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        if ($failed > 0) {
            Log::warning('KiriminAja callback could not be verified through the Tracking API.', ['method' => $method, 'failed' => $failed]);

            return response()->json(['status' => 'retry', 'handled' => $handled], 500);
        }

        return response()->json(['status' => $handled > 0 ? 'handled' : 'ignored', 'handled' => $handled]);
    }

    private static function authentic(Request $request): bool
    {
        $key = KiriminAja::apiKey();
        $bearer = (string) $request->bearerToken();

        if ($key !== '' && $bearer !== '' && hash_equals($key, $bearer)) {
            return true;
        }

        $secret = KiriminAja::webhookSecret();
        $token = $request->query('token');

        return $secret !== '' && is_string($token) && $token !== '' && hash_equals($secret, $token);
    }

    /**
     * The shipment this store registered with KiriminAja: by partner order id
     * (order_shipments.external_reference), then by AWB.
     */
    private static function shipment(string $orderId, string $awb): ?OrderShipment
    {
        if ($orderId !== '') {
            $shipment = OrderShipment::query()
                ->where('driver', KiriminAjaDriver::CODE)
                ->where('external_reference', $orderId)
                ->orderByDesc('id')
                ->first()
                ?? OrderShipment::query()
                    ->where('driver', KiriminAjaDriver::CODE)
                    ->where('metadata->kiriminaja->order_id', $orderId)
                    ->orderByDesc('id')
                    ->first();

            if ($shipment !== null) {
                return $shipment;
            }
        }

        if ($awb !== '') {
            return OrderShipment::query()
                ->where('driver', KiriminAjaDriver::CODE)
                ->where(static function ($query) use ($awb): void {
                    $query->where('awb', $awb)->orWhere('tracking_number', $awb);
                })
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }
}
