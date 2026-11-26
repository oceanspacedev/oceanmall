<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\RecordPaymentDispute;
use App\Models\PaymentDispute;
use App\Payment\Drivers\ConfirmFirstStripeDriver;
use App\Support\OperatorSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
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

        $driver = Payment::driver(self::DRIVER);
        $usesStripeMinorUnits = $driver instanceof ConfirmFirstStripeDriver;

        try {
            $result = $driver->handleWebhook(
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

        $expectedStripeAmount = $usesStripeMinorUnits
            ? ConfirmFirstStripeDriver::toStripeAmount(
                (int) $order->price_amount,
                (string) $order->currency_code,
            )
            : (int) $order->price_amount;

        if (in_array($result->action, ['authorized', 'captured'], strict: true)
            && $result->amount !== null
            && $result->amount !== $expectedStripeAmount
        ) {
            report(new RuntimeException(sprintf(
                'Stripe webhook amount mismatch: order #%d expected %d (%s), received %d',
                $order->id,
                $expectedStripeAmount,
                $order->currency_code,
                $result->amount,
            )));

            $storeReceived = $usesStripeMinorUnits
                ? ConfirmFirstStripeDriver::fromStripeAmount($result->amount, (string) $order->currency_code)
                : $result->amount;
            resolve(RecordPaymentDispute::class)->handle(
                order: $order,
                driver: self::DRIVER,
                type: PaymentDispute::TYPE_AMOUNT_MISMATCH,
                reference: (string) $result->reference,
                amount: $storeReceived,
                expectedAmount: (int) $order->price_amount,
                currencyCode: (string) $order->currency_code,
                reason: 'Nominal webhook Stripe tidak sesuai dengan total order.',
            );

            return response()->json(['status' => 'amount_mismatch']);
        }

        // A capture is a payment confirmation like every other gateway's: it
        // goes through MarkOrderPaid, which fires Shopper's OrderPaid once on
        // the unpaid -> paid transition (fulfilment, notification listeners).
        if ($result->action === 'captured') {
            $storeAmount = $result->amount !== null
                ? ($usesStripeMinorUnits
                    ? ConfirmFirstStripeDriver::fromStripeAmount($result->amount, (string) $order->currency_code)
                    : $result->amount)
                : null;

            $outcome = resolve(MarkOrderPaid::class)->handle(
                $order,
                self::DRIVER,
                $result->reference,
                $storeAmount,
            );

            if ($outcome === MarkOrderPaid::CANCELLED_ORDER) {
                report(new RuntimeException(sprintf(
                    'Stripe payment %s captured after order %s was cancelled — refund or restore manually.',
                    $result->reference,
                    $order->number,
                )));

                resolve(RecordPaymentDispute::class)->handle(
                    order: $order,
                    driver: self::DRIVER,
                    type: PaymentDispute::TYPE_PAID_AFTER_CANCELLATION,
                    reference: (string) $result->reference,
                    amount: $storeAmount,
                    expectedAmount: (int) $order->price_amount,
                    currencyCode: (string) $order->currency_code,
                    reason: 'Stripe PaymentIntent tertagih setelah pesanan dibatalkan.',
                );
            } elseif ($outcome === MarkOrderPaid::ALREADY_PROCESSED) {
                $otherCapture = PaymentTransaction::query()
                    ->where('order_id', $order->id)
                    ->where('driver', self::DRIVER)
                    ->where('type', TransactionType::Capture)
                    ->where('status', TransactionStatus::Success)
                    ->where('reference', '!=', (string) $result->reference)
                    ->exists();

                if ($otherCapture) {
                    resolve(RecordPaymentDispute::class)->handle(
                        order: $order,
                        driver: self::DRIVER,
                        type: PaymentDispute::TYPE_DOUBLE_PAYMENT,
                        reference: (string) $result->reference,
                        amount: $storeAmount,
                        expectedAmount: (int) $order->price_amount,
                        currencyCode: (string) $order->currency_code,
                        reason: 'PaymentIntent Stripe kedua tertagih untuk order yang sudah lunas.',
                    );
                }
            }

            return response()->json(['status' => $outcome]);
        }

        if ($result->action === 'authorized') {
            $status = DB::transaction(static function () use ($order): string {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if (! $locked instanceof Order) {
                    return 'no_order';
                }

                if ($locked->status === OrderStatus::Cancelled
                    || in_array($locked->payment_status, [
                        PaymentStatus::Paid,
                        PaymentStatus::PartiallyRefunded,
                        PaymentStatus::Refunded,
                        PaymentStatus::Voided,
                    ], true)) {
                    return 'already_processed';
                }

                if ($locked->payment_status === PaymentStatus::Authorized) {
                    return 'already_processed';
                }

                $locked->update(['payment_status' => PaymentStatus::Authorized]);

                return 'handled';
            });

            return response()->json(['status' => $status]);
        }

        if (in_array($result->action, ['failed', 'canceled'], strict: true)) {
            $shouldCancel = DB::transaction(static function () use ($order, $result): string {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if (! $locked instanceof Order) {
                    return 'no_order';
                }

                if (in_array($locked->payment_status, [
                    PaymentStatus::Paid,
                    PaymentStatus::PartiallyRefunded,
                    PaymentStatus::Refunded,
                ], true)) {
                    return 'already_processed';
                }

                PaymentTransaction::query()
                    ->where('order_id', $locked->id)
                    ->where('driver', self::DRIVER)
                    ->where('reference', $result->reference)
                    ->where('status', TransactionStatus::Pending)
                    ->update(['status' => TransactionStatus::Failed]);

                if ($locked->status === OrderStatus::Cancelled) {
                    return 'already_processed';
                }

                return 'cancel';
            });

            if ($shouldCancel !== 'cancel') {
                return response()->json(['status' => $shouldCancel]);
            }

            resolve(CancelOrderWithProviders::class)->handle($order, throughPaymentProvider: false);

            return response()->json(['status' => 'handled']);
        }

        if ($result->action === 'refunded') {
            $refundedStoreAmount = $result->amount !== null
                ? ($usesStripeMinorUnits
                    ? ConfirmFirstStripeDriver::fromStripeAmount($result->amount, (string) $order->currency_code)
                    : $result->amount)
                : null;
            $isPartial = $refundedStoreAmount !== null
                && $refundedStoreAmount > 0
                && $refundedStoreAmount < (int) $order->price_amount;

            $outcome = DB::transaction(static function () use ($order, $isPartial): string {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if (! $locked instanceof Order) {
                    return 'no_order';
                }

                if ($isPartial) {
                    if ($locked->payment_status === PaymentStatus::Refunded) {
                        return 'already_processed';
                    }

                    if ($locked->payment_status === PaymentStatus::PartiallyRefunded) {
                        return 'already_processed';
                    }

                    $locked->update(['payment_status' => PaymentStatus::PartiallyRefunded]);

                    return 'handled';
                }

                if ($locked->payment_status === PaymentStatus::Refunded && $locked->status === OrderStatus::Cancelled) {
                    return 'already_processed';
                }

                $locked->update(['payment_status' => PaymentStatus::Refunded]);

                return $locked->status === OrderStatus::Cancelled ? 'handled' : 'cancel';
            });

            if ($outcome === 'cancel') {
                resolve(CancelOrderWithProviders::class)->handle($order->refresh(), throughPaymentProvider: false);

                return response()->json(['status' => 'handled']);
            }

            return response()->json(['status' => $outcome]);
        }

        return response()->json(['status' => 'unhandled_action']);
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
