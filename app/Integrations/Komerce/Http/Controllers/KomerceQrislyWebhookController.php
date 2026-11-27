<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Komerce\Actions\Payment\MarkOrderPaidFromKomerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receiver for QRISLY outbound webhooks registered in Collaborator.
 *
 * Docs expect HTTP 200 with `{ success, message }`.
 * QRISLY docs do not define a signature header. This endpoint treats the
 * webhook only as a signal; MarkOrderPaidFromKomerce independently fetches
 * the authenticated status before any order transition.
 *
 * @see https://rajaongkir.com/docs/qrisly/getting-started/webhook
 */
final class KomerceQrislyWebhookController extends Controller
{
    public function __invoke(Request $request, MarkOrderPaidFromKomerce $markOrderPaid): JsonResponse
    {
        $ack = [
            'success' => true,
            'message' => 'Webhook received successfully',
        ];

        // Disabled-product requests are acknowledged by AcknowledgeDisabledWebhook.
        $event = strtolower(trim((string) $request->input('event', '')));
        $paymentStatus = strtolower(trim((string) (
            $request->input('data.status')
            ?? $request->input('data.payment_status')
            ?? $request->input('payment_status')
            ?? ''
        )));

        $historyId = trim((string) (
            $request->input('data.history_id')
            ?? $request->input('history_id')
            ?? $request->input('data.qris_history_id')
            ?? $request->input('qris_history_id')
            ?? ''
        ));

        $isSuccessEvent = $event === 'payment.success'
            || in_array($paymentStatus, ['paid', 'success', 'succeeded'], true);

        if ($historyId !== '' && $isSuccessEvent) {
            $verdict = $markOrderPaid->handle($historyId, 'qrisly', verifyEvenIfUnentitled: true);

            // A payment of ours that could not be read back (provider
            // unreachable) is delivered again. Everything else is
            // acknowledged as before: the endpoint is unsigned, so "the
            // provider says unpaid" is an answer, not an error.
            if ($verdict === 'lookup_failed') {
                return response()->json(['status' => $verdict], 503)->header('Retry-After', '30');
            }
        }

        return response()->json($ack);
    }
}
