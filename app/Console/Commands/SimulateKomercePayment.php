<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Checkout\MarkOrderPaidFromKomerce;
use App\Actions\Checkout\ResolveKomercePaymentInstructions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Order;

/**
 * QA helper: mark a sandbox order as paid without a real payer.
 *
 * Komerce's sandbox payment page cannot be completed and its webhooks cannot
 * reach a local machine, so paid-order flows were untestable locally. This
 * runs the real MarkOrderPaidFromKomerce path (amount check, transaction,
 * customer notification, delivery dispatch) and only stubs the provider's
 * "payment status" answer as PAID. Refuses outside the local environment and
 * against non-sandbox payment hosts.
 */
final class SimulateKomercePayment extends Command
{
    protected $signature = 'komerce:simulate-payment {order : Order number, e.g. ORD-20261013-000006}';

    protected $description = 'QA only: mark a sandbox Komerce order as paid (local environment + sandbox keys)';

    public function handle(MarkOrderPaidFromKomerce $markOrderPaid, ResolveKomercePaymentInstructions $instructions): int
    {
        if (! app()->environment('local')) {
            $this->error('Only available when APP_ENV=local.');

            return self::FAILURE;
        }

        $paymentHost = (string) config('komerce.payment_base_url');
        if (! str_contains($paymentHost, 'sandbox')) {
            $this->error("Refusing: KOMERCE_PAYMENT_BASE_URL is not a sandbox host ({$paymentHost}).");

            return self::FAILURE;
        }

        $order = Order::query()->where('number', (string) $this->argument('order'))->first();
        if (! $order instanceof Order) {
            $this->error('Order not found.');

            return self::FAILURE;
        }

        $payment = $instructions->handle($order);
        $paymentId = is_array($payment) ? (string) ($payment['payment_id'] ?? '') : '';
        if ($paymentId === '') {
            $this->error('Order has no Komerce payment yet (create or retry the payment first).');

            return self::FAILURE;
        }

        $amount = (int) ($payment['amount'] ?? $order->price_amount);
        $paid = ['status' => 'PAID', 'payment_status' => 'PAID', 'amount' => $amount, 'final_amount' => $amount];

        Cache::forget('komerce:payment-status:'.hash('sha256', $paymentId));
        // Only the status lookups are stubbed; every other request (e.g. Store
        // Order for delivery) still reaches the real sandbox.
        Http::fake([
            '*/api/v1/user/payment/status/*' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => $paid]),
            '*/api/v1/qrisly/payment-status/*' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => $paid]),
        ]);

        $result = $markOrderPaid->handle($paymentId);
        $order->refresh();

        $this->line("Result: {$result}");
        $this->line("Order {$order->number}: payment={$order->payment_status->value}, status={$order->status->value}");

        return in_array($result, ['handled', 'already_processed'], true) ? self::SUCCESS : self::FAILURE;
    }
}
