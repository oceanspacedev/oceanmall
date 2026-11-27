<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Komerce\Actions\Payment\MarkOrderPaidFromKomerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Shopper\Payment\Exceptions\PaymentException;
use Shopper\Payment\Facades\Payment;

final class KomercePaymentWebhookController extends Controller
{
    public function __invoke(Request $request, MarkOrderPaidFromKomerce $markOrderPaid): JsonResponse
    {
        try {
            $result = Payment::driver('komerce')->handleWebhook(
                payload: ['_raw_body' => $request->getContent()],
                headers: [
                    'x-callback-api-key' => (string) $request->header('X-Callback-Api-Key', ''),
                ],
            );
        } catch (PaymentException) {
            return response()->json(['status' => 'invalid_secret'], 401);
        }

        // Disabled-product requests never get here (AcknowledgeDisabledWebhook).
        if ($result->isIgnored() || $result->action !== 'captured') {
            return response()->json(['status' => 'handled']);
        }

        $payload = $request->validate([
            'payment_id' => ['required', 'string'],
            'order_id' => ['nullable', 'string'],
            'status' => ['required', 'string'],
            'amount' => ['nullable', 'integer'],
        ]);

        $paymentId = $result->reference ?? $payload['payment_id'];

        // This signed callback says PAID: the status is read back fresh, not
        // from the short status cache (a "pending" answer cached a moment
        // before the customer paid must not decide it).
        Cache::forget('komerce:payment-status:'.hash('sha256', trim((string) $paymentId)));

        $verdict = $markOrderPaid->handle($paymentId, verifyEvenIfUnentitled: true);

        // The read-back failed, or does not say paid yet: answer with an
        // error so Komerce delivers the callback again, instead of
        // acknowledging a payment that was not applied.
        if (in_array($verdict, ['lookup_failed', 'not_paid'], true)) {
            return response()->json(['status' => $verdict], 503)->header('Retry-After', '30');
        }

        return response()->json(['status' => 'handled']);
    }
}
