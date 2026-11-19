<?php

declare(strict_types=1);

namespace Tests\Feature\Midtrans;

use App\Integrations\Midtrans\Support\MidtransSignature;
use Illuminate\Testing\TestResponse;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;

/**
 * Shared Midtrans test setup: hermetic module config (a local .env key must
 * never leak into a test), sandbox hosts, orders paid through a Midtrans
 * method, and signed notifications.
 */
trait InteractsWithMidtrans
{
    protected const SERVER_KEY = 'SB-Mid-server-test-key';

    protected const SNAP_URL = 'https://app.sandbox.midtrans.com/snap/v1/transactions';

    protected const API = 'https://api.sandbox.midtrans.com/v2/';

    protected function withoutMidtrans(): void
    {
        config()->set('midtrans.server_key', '');
        config()->set('midtrans.client_key', '');
        config()->set('midtrans.is_production', null);
        config()->set('midtrans.finish_url', '');
        config()->set('midtrans.expiry_minutes', 1440);
        config()->set('shopper.payment.drivers.midtrans.enabled', true);
    }

    protected function withMidtrans(): void
    {
        $this->withoutMidtrans();
        config()->set('midtrans.server_key', self::SERVER_KEY);
        config()->set('midtrans.is_production', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function midtransOrder(string $channel = 'bca_va', array $attributes = []): Order
    {
        $method = PaymentMethod::query()->create([
            'title' => 'Midtrans '.$channel,
            'slug' => 'midtrans-test-'.str_replace('_', '-', $channel).'-'.uniqid(),
            'driver' => 'midtrans',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => $channel]),
        ]);

        return Order::factory()->create([
            'number' => 'ORD-2026-0001',
            'currency_code' => 'IDR',
            'price_amount' => 150000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $method->id,
            ...$attributes,
        ]);
    }

    /**
     * An order whose attempt was created (as afterPaymentInitiated leaves it).
     */
    protected function initiatedOrder(string $midtransOrderId = 'ORD-2026-0001', ?string $expiresAt = null, array $attributes = []): Order
    {
        $order = $this->midtransOrder('bca_va', $attributes);

        $order->forceFill(['metadata' => json_encode(['midtrans' => [
            'order_id' => $midtransOrderId,
            'attempt' => 1,
            'token' => 'snap-token-1',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-1',
            'channel_code' => 'bca_va',
            'expires_at' => $expiresAt ?? now()->addDay()->toIso8601String(),
        ]])])->save();

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $order->payment_method_id,
            'driver' => 'midtrans',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => (int) $order->price_amount,
            'currency_code' => 'IDR',
            'reference' => $midtransOrderId,
        ]);

        return $order->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusBody(string $orderId, string $transactionStatus, string $grossAmount = '150000.00', array $extra = []): array
    {
        return [
            'status_code' => in_array($transactionStatus, ['pending'], true) ? '201' : '200',
            'status_message' => 'Success, transaction is found',
            'transaction_id' => 'trx-'.$orderId,
            'order_id' => $orderId,
            'gross_amount' => $grossAmount,
            'currency' => 'IDR',
            'payment_type' => 'bank_transfer',
            'transaction_status' => $transactionStatus,
            'fraud_status' => 'accept',
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notFoundBody(): array
    {
        return ['status_code' => '404', 'status_message' => "Transaction doesn't exist.", 'id' => 'x'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function postNotification(array $body, ?string $signature = null): TestResponse
    {
        $body['signature_key'] = $signature ?? MidtransSignature::expected(
            (string) $body['order_id'],
            (string) $body['status_code'],
            (string) $body['gross_amount'],
            self::SERVER_KEY,
        );

        return $this->postJson('/webhooks/midtrans/notification', $body);
    }
}
