<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Biteship\Actions\ApplyBiteshipWebhook;
use App\Integrations\Biteship\Biteship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receiver for Biteship webhooks (dashboard → Integrations → Webhook):
 * `order.status`, `order.waybill_id` and `order.price`, all on one URL
 * (POST {APP_URL}/webhooks/biteship).
 *
 * - Installation: when the URL is saved Biteship sends a test request
 *   without an event and expects HTTP 200; it is answered with an empty 200
 *   body and nothing is processed.
 * - Authentication: Biteship lets the merchant add a custom header to every
 *   webhook. The store requires header `BITESHIP_WEBHOOK_HEADER`
 *   (default X-Biteship-Secret) to equal BITESHIP_WEBHOOK_SECRET
 *   (constant-time compare). Without a configured secret events are refused,
 *   so a public URL can never drive provider calls anonymously.
 * - The payload is never trusted: ApplyBiteshipWebhook re-reads the order
 *   from the Biteship API before changing anything.
 *
 * A disabled module is acknowledged earlier by AcknowledgeDisabledBiteshipWebhook.
 */
final class BiteshipWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyBiteshipWebhook $apply): JsonResponse|Response
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || ! array_key_exists('event', $payload)) {
            return response('', 200);
        }

        if (! $this->authentic($request)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        try {
            $result = $apply->handle($payload);
        } catch (Throwable $e) {
            report($e);

            // 5xx: Biteship retries; the order is only changed after a
            // successful API re-read.
            return response()->json(['status' => 'error'], 503);
        }

        return response()->json(['status' => $result]);
    }

    private function authentic(Request $request): bool
    {
        $secret = Biteship::webhookSecret();

        if ($secret === '') {
            Log::warning('Biteship webhook refused: BITESHIP_WEBHOOK_SECRET is not configured.');

            return false;
        }

        $given = $request->header(Biteship::webhookHeader());

        return is_string($given) && hash_equals($secret, $given);
    }
}
