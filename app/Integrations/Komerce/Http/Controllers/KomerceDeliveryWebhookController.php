<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Komerce\Actions\Shipping\ApplyDeliveryWebhookStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Receiver for Komerce Shipping Delivery webhooks registered at
 * https://collaborator.komerce.id/webhook (Add Webhook URL).
 *
 * Official endpoint table lists merchant webhook as PUT. This controller
 * is registered for POST and PUT. Payload: { order_no, cnote, status }
 *
 * The official Delivery webhook contract does not define a signature header.
 * Its payload is treated only as a refresh signal; the action verifies the
 * resulting state through the authenticated Shipping Delivery tracking API.
 */
final class KomerceDeliveryWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyDeliveryWebhookStatus $apply): JsonResponse
    {
        // Disabled-product requests are acknowledged by AcknowledgeDisabledWebhook.
        $payload = $request->validate([
            'order_no' => ['required', 'string'],
            'cnote' => ['nullable', 'string'],
            'status' => ['required', 'string'],
        ]);

        $orderNo = trim((string) ($payload['order_no'] ?? ''));
        $cnote = isset($payload['cnote']) ? trim((string) $payload['cnote']) : null;
        $status = isset($payload['status']) ? trim((string) $payload['status']) : null;

        try {
            $apply->handle($orderNo, $cnote, $status);
        } catch (RequestException|ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json(['status' => 'lookup_failed'], 503)
                ->header('Retry-After', '30');
        }

        return response()->json(['status' => 'handled']);
    }
}
