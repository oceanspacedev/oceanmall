<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\MarkOrderPaid;
use App\Support\OperatorSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Exceptions\PaymentException;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;

/**
 * Stripe payment events. Accepted only while the Stripe driver is switched on
 * (PAYMENT_STRIPE_ENABLED; the switch alone, so payments Stripe already took
 * are still settled after the store moved to another gateway) AND a webhook signing secret is configured: the
 * Stripe-Signature check is an HMAC keyed by that secret, so with an empty
 * secret anyone could sign a forged event. Every event must carry a valid
 * Stripe-Signature, and an event only ever touches Stripe's own transactions.
 */
final class StripeWebhookController extends Controller
{
    private const DRIVER = 'stripe';

    public function __invoke(Request $request): JsonResponse
    {
        if (! OperatorSwitch::switchedOn(OperatorSwitch::PAYMENT, self::DRIVER) || self::webhookSecret() === '') {
            Log::warning('Stripe webhook rejected: Stripe is switched off or has no webhook secret.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'disabled'], 404);
        }

        $signature = $request->header('Stripe-Signature');

        if (! is_string($signature) || trim($signature) === '') {
            Log::warning('Stripe webhook rejected: missing Stripe-Signature header.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'invalid_signature'], 400);
        }

        try {
            $result = Payment::driver(self::DRIVER)->handleWebhook(
                payload: ['_raw_body' => $request->getContent()],
                headers: ['stripe-signature' => $signature],
            );
        } catch (PaymentException $e) {
            Log::warning('Stripe webhook rejected: invalid payload or signature.', [
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'invalid_signature'], 400);
        }

        if ($result->isIgnored()) {
            return response()->json(['status' => 'ignored']);
        }

        // A Stripe event settles Stripe payments only: another gateway's
        // reference (e.g. an order number) never resolves here.
        $transaction = PaymentTransaction::query()
            ->where('driver', self::DRIVER)
            ->where('reference', $result->reference)
            ->first();

        if (! $transaction) {
            return response()->json(['status' => 'no_transaction'], 404);
        }

        $order = Order::query()->find($transaction->order_id);

        if (! $order) {
            return response()->json(['status' => 'no_order'], 404);
        }

        if (in_array($result->action, ['authorized', 'captured'], strict: true)
            && $result->amount !== null
            && $result->amount !== (int) $order->price_amount
        ) {
            report(new \RuntimeException(sprintf(
                'Stripe webhook amount mismatch: order #%d expected %d, received %d',
                $order->id,
                $order->price_amount,
                $result->amount,
            )));

            return response()->json(['status' => 'amount_mismatch']);
        }

        // A capture is a payment confirmation like every other gateway's: it
        // goes through MarkOrderPaid, which fires Shopper's OrderPaid once on
        // the unpaid -> paid transition (fulfilment, notification listeners).
        if ($result->action === 'captured') {
            $outcome = resolve(MarkOrderPaid::class)->handle(
                $order,
                self::DRIVER,
                $result->reference,
                $result->amount,
            );

            return response()->json(['status' => $outcome]);
        }

        $targetState = match ($result->action) {
            'authorized' => ['payment_status' => PaymentStatus::Authorized],
            'failed', 'canceled' => ['status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Voided],
            'refunded' => ['status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Refunded],
            default => null,
        };

        if (! $targetState) {
            return response()->json(['status' => 'unhandled_action']);
        }

        if (
            isset($targetState['payment_status'])
            && $order->payment_status === $targetState['payment_status']
        ) {
            return response()->json(['status' => 'already_processed']);
        }

        $order->update($targetState);

        return response()->json(['status' => 'handled']);
    }

    /**
     * Same lookup as the Stripe driver binding in AppServiceProvider.
     */
    private static function webhookSecret(): string
    {
        $secret = config('shopper.stripe.webhook_secret') ?: config('shopper.payment.drivers.stripe.credentials.webhook_secret');

        return is_string($secret) ? trim($secret) : '';
    }
}
