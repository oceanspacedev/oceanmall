<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier2;

use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F5PaymentLifecycleBoundaryTest extends VendorPackageTierTestCase
{
    public function test_payment_webhook_rejects_bad_or_plain_secret_hmac_and_returns_503_on_status_lookup_failure(): void
    {
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-WH-ERR', 'PAID', 112000, 500)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-WH-ERR']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-WH-ERR'],
        );

        $payload = [
            'payment_id' => 'KOMPAY-WH-ERR',
            'order_id' => $order->number,
            'status' => 'PAID',
            'amount' => 112000,
        ];

        // Invalid HMAC rejected with 401
        $this->postSignedKomercePaymentWebhook($payload, 'webhook-secret', 'bad-hmac')
            ->assertStatus(401)
            ->assertJson(['status' => 'invalid_secret']);

        // Plain secret (not HMAC-SHA256) rejected with 401
        $this->postSignedKomercePaymentWebhook($payload, 'webhook-secret', 'webhook-secret')
            ->assertStatus(401)
            ->assertJson(['status' => 'invalid_secret']);

        // Valid HMAC when status API fails (500) -> 503 with Retry-After: 30 so Komerce retries
        $this->postSignedKomercePaymentWebhook($payload)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_payment_webhook_ignores_amount_mismatch_without_marking_paid(): void
    {
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-MISMATCH', 'PAID', 1000)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-MISMATCH']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-MISMATCH'],
        );

        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-MISMATCH',
            'order_id' => $order->number,
            'status' => 'PAID',
            'amount' => 1000,
        ])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_qrisly_webhook_returns_503_on_upstream_error_and_ignores_spoofed_unpaid_signal(): void
    {
        $this->applyTier('qrisly_only')
            ->withQrislyStatus('9003', 'paid', 50001, 500)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 50000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => '9003',
                    ],
                ],
            ],
            [],
            ['amount' => 50000, 'reference' => '9003'],
        );

        // Upstream 500 -> 503 with Retry-After: 30
        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => ['history_id' => '9003', 'status' => 'paid'],
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        // Spoofed payment.success when QRISLY status API still says 'unpaid' -> 200 ack, order stays Pending
        $this->applyTier('qrisly_only')
            ->withQrislyStatus('9003', 'unpaid', 50001)
            ->apply();

        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => ['history_id' => '9003', 'status' => 'paid'],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_disabled_or_keyless_webhooks_return_200_disabled_while_revoked_key_still_verifies(): void
    {
        $this->applyTier('keyless');

        $this->postSignedKomercePaymentWebhook(['payment_id' => 'KOMPAY-1', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');

        $this->postDeliveryWebhook(['order_no' => 'RO-1', 'status' => 'DELIVERED'])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');

        $this->postQrislyWebhook(['event' => 'payment.success', 'data' => ['history_id' => '9001']])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');

        Http::assertNothingSent();

        // Key is configured, but KomerceCapabilities observed 401 -> webhook must STILL verify HMAC and attempt status lookup
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-REVOKED', 'PAID', 112000)
            ->apply();

        $this->observeVendorCapability('payment', 401, 'Invalid API key');

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-REVOKED']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-REVOKED'],
        );

        // Invalid HMAC still rejected with 401 (AcknowledgeDisabledWebhook did NOT drop it as disabled)
        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-REVOKED',
            'order_id' => $order->number,
            'status' => 'PAID',
            'amount' => 112000,
        ], 'webhook-secret', 'wrong-signature')
            ->assertStatus(401)
            ->assertJson(['status' => 'invalid_secret']);

        // Valid HMAC attempts status verification (returns 503 lookup_failed rather than silently dropping with 200 disabled)
        $webhookRes = $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-REVOKED',
            'order_id' => $order->number,
            'status' => 'PAID',
            'amount' => 112000,
        ]);
        $this->assertNotSame('disabled', $webhookRes->json('status'));
        $this->assertContains($webhookRes->getStatusCode(), [200, 503]);
    }

    public function test_cross_provider_qris_retry_succeeds_when_qrisly_revoked_and_payment_api_active(): void
    {
        $this->applyTier('payment_and_qrisly')
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-QRIS-FALLBACK',
                'qris_string' => '000201-PAYMENT-API-QRIS',
                'amount' => 25000,
            ])
            ->apply();

        // Mark qrisly unentitled (e.g. revoked mid-flight) while Payment API remains active
        $this->observeVendorCapability('qrisly', 401, 'Invalid API Key');

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 25000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => '9005',
                    ],
                ],
            ],
            [],
            ['amount' => 25000, 'reference' => '9005'],
        );

        $this->apiShowOrder($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.can_retry_payment', true);

        $this->apiRetryPayment($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.payment_action.kind', 'qr')
            ->assertJsonPath('data.payment_action.data.provider', 'payment_api')
            ->assertJsonPath('data.payment_action.data.payment_id', 'KOMPAY-QRIS-FALLBACK');
    }

    public function test_web_and_api_retry_payment_map_already_paid_and_not_eligible_to_localized_errors(): void
    {
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-ALREADY-PAID', 'PAID', 112000)
            ->apply();
        $customer = $this->fixtures->createVerifiedCustomer();

        // Pending locally while upstream Payment API already marked PAID -> PaymentRetryRefused::ALREADY_PAID
        ['order' => $alreadyPaidUpstream] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-ALREADY-PAID']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-ALREADY-PAID'],
        );

        // API ALREADY_PAID -> 422 Indonesian message
        $this->apiRetryPayment($customer, $alreadyPaidUpstream->number)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pesanan ini sudah dibayar. Tarik untuk memperbarui status.');

        // Web ALREADY_PAID -> redirect back with 'payment' session error mentioning 'sudah dibayar'
        $this->webRetryPayment($customer, $alreadyPaidUpstream)
            ->assertRedirect(route('account.orders.show', $alreadyPaidUpstream))
            ->assertSessionHasErrors('payment');
        $this->assertStringContainsString('sudah dibayar', (string) session('errors')->first('payment'));

        // Manual / non-retriable order -> NOT_ELIGIBLE mapped cleanly
        ['order' => $manualOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
            ],
            [],
            null,
        );

        $this->apiRetryPayment($customer, $manualOrder->number)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran pesanan ini tidak bisa diulang.');

        $this->webRetryPayment($customer, $manualOrder)
            ->assertRedirect(route('account.orders.show', $manualOrder))
            ->assertSessionHasErrors('payment');
        $this->assertStringContainsString('tidak bisa diulang', (string) session('errors')->first('payment'));
    }

    public function test_orders_expire_unpaid_cancels_after_24h_grace_when_driver_disabled_and_never_initiated_orders(): void
    {
        // Driver switched off (cannot verify remotely)
        $this->applyTier('keyless', [
            'shopper.payment.drivers.komerce.enabled' => false,
        ]);

        $customer = $this->fixtures->createVerifiedCustomer();
        $productA = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productB = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productC = $this->fixtures->createStockedProduct(100000, 10, 500);

        // 1. Expired 25h ago (past 24h local grace) -> cancelled locally, stock restored
        ['order' => $graceExpired] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-GRACE-OLD', 'expiry_date' => now()->subHours(25)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-GRACE-OLD'],
            $productA,
        );
        $productA->decreaseStock($this->fixtures->inventory->id, 1);

        // 2. Expired 2h ago (inside 24h local grace) -> kept New
        ['order' => $withinGrace] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-GRACE-NEW', 'expiry_date' => now()->subHours(2)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-GRACE-NEW'],
            $productB,
        );

        // 3. Never-initiated order (no PaymentTransaction) created 25h ago -> cancelled locally
        ['order' => $neverInitiated] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'created_at' => now()->subHours(25),
                'metadata' => [],
            ],
            [],
            null,
            $productC,
        );
        $productC->decreaseStock($this->fixtures->inventory->id, 1);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $graceExpired->fresh()->status);
        $this->assertSame(10, $productA->fresh()->stockInventory($this->fixtures->inventory->id));

        $this->assertSame(OrderStatus::New, $withinGrace->fresh()->status);

        $this->assertSame(OrderStatus::Cancelled, $neverInitiated->fresh()->status);
        $this->assertSame(10, $productC->fresh()->stockInventory($this->fixtures->inventory->id));

        Http::assertNothingSent();
    }
}
