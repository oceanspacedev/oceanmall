<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Midtrans\Actions\SyncMidtransPayment;
use App\Integrations\Midtrans\Midtrans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shopper\Payment\Exceptions\PaymentException;
use Shopper\Payment\Facades\Payment;

/**
 * Midtrans HTTP(S) notification: POST webhooks/midtrans/notification
 * (Dashboard > Settings > Payment > Notification URL).
 *
 * 1. signature_key = SHA512(order_id + status_code + gross_amount + server key),
 *    compared in constant time (MidtransDriver::handleWebhook); a mismatch
 *    answers 401 and changes nothing;
 * 2. the body is then only a signal: SyncMidtransPayment re-reads the
 *    attempt's status from the Core API and applies it idempotently, so
 *    duplicate or out-of-order notifications are harmless;
 * 3. 200 for every decided outcome (including unknown order ids, e.g. the
 *    dashboard's test notification); 503 when Midtrans could not be asked,
 *    so Midtrans retries the notification later.
 */
final class MidtransNotificationController extends Controller
{
    public function __invoke(Request $request, SyncMidtransPayment $sync): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            $payload = $request->all();
        }

        try {
            $result = Payment::driver(Midtrans::CODE)->handleWebhook($payload);
        } catch (PaymentException) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $orderId = trim((string) $result->reference);

        if ($orderId === '') {
            return response()->json(['status' => 'ignored']);
        }

        $verdict = $sync->handle($orderId);

        if ($verdict === SyncMidtransPayment::LOOKUP_FAILED) {
            return response()->json(['status' => $verdict], 503);
        }

        return response()->json(['status' => $verdict === SyncMidtransPayment::NO_ORDER ? 'ignored' : $verdict]);
    }
}
