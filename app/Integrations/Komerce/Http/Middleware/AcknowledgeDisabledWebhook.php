<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One answer for every provider webhook whose product is switched off by the
 * operator: HTTP 200 with `status: disabled`, before any signature check or
 * processing. A 2xx stops provider retries, nothing is read from the payload,
 * and no outbound call is made. (The `success` / `message` pair is the
 * acknowledgement shape QRISLY documents; the other providers ignore it.)
 *
 * "Switched off" means KOMERCE_ENABLED=false or the product's key is not set.
 * A product whose key IS set but which the plan probe currently reports as
 * not entitled (KomerceCapabilities learns that passively, and one false
 * negative can last until the next probe) is still processed: the callback
 * belongs to a real order, so it must not be acknowledged and dropped. The
 * controller then verifies and applies it as usual (a failure answers 5xx and
 * Komerce retries).
 *
 * Usage: ->middleware(AcknowledgeDisabledWebhook::class.':payment')
 */
final class AcknowledgeDisabledWebhook
{
    /** @var array<string, list<string>> product => config keys that must be filled */
    private const KEYS = [
        'payment' => ['komerce.payment_api_key'],
        'shipping_delivery' => ['komerce.shipping_delivery_api_key'],
        'qrisly' => ['komerce.qrisly_api_key', 'komerce.qrisly_qris_id'],
    ];

    public function handle(Request $request, Closure $next, string $product): Response
    {
        if (array_key_exists($product, self::KEYS) && ! self::switchedOn($product)) {
            return new JsonResponse([
                'status' => 'disabled',
                'success' => true,
                'message' => 'Webhook received successfully',
            ]);
        }

        return $next($request);
    }

    private static function switchedOn(string $product): bool
    {
        if (config('komerce.enabled') === false) {
            return false;
        }

        foreach (self::KEYS[$product] as $key) {
            if (trim((string) config($key, '')) === '') {
                return false;
            }
        }

        return true;
    }
}
