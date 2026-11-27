<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier3;

use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class WebhookResilienceAndLocalExpiryTest extends VendorPackageTierTestCase
{
    public function test_signed_and_unsigned_webhooks_enforce_hmac_503_retry_after_and_disabled_ack(): void
    {
        $customer = $this->fixtures->createVerifiedCustomer();

        // 1. Keyless / disabled: all 3 webhooks return 200 {status: disabled} with zero outbound calls
        $this->applyTier('keyless');
        $this->postSignedKomercePaymentWebhook(['payment_id' => 'KOMPAY-1', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');
        $this->postDeliveryWebhook(['order_no' => 'RO-1', 'status' => 'ON_PROCESS'])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');
        $this->postQrislyWebhook(['event' => 'payment.success', 'data' => ['history_id' => '9001']])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');
        Http::assertNothingSent();

        // 2. Komship with lookup failures across Payment (500), QRISLY (500), and Delivery (401 & 429)
        $this->applyTier('komship')
            ->withPaymentStatus('KOMPAY-503', 'PAID', 112000, 500)
            ->withQrislyStatus('9503', 'paid', 25001, 500)
            ->withDeliveryEntitled(false, 401, 'Invalid API Key')
            ->apply();

        ['order' => $paymentOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-503']],
            ],
            [
                'external_reference' => 'RO-WH-RES-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-WH-RES-1']],
            ],
            ['amount' => 112000, 'reference' => 'KOMPAY-503'],
        );

        ['order' => $qrislyOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 25000,
                'metadata' => ['komerce' => ['payment_type' => 'qris', 'provider' => 'qrisly', 'payment_ref' => '9503']],
            ],
            [],
            ['amount' => 25000, 'reference' => '9503'],
        );

        // Invalid HMAC -> 401
        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-503',
            'order_id' => $paymentOrder->number,
            'status' => 'PAID',
            'amount' => 112000,
        ], 'webhook-secret', 'invalid-sig')
            ->assertStatus(401)
            ->assertJson(['status' => 'invalid_secret']);

        // Valid HMAC + Payment status 500 -> 503 + Retry-After: 30
        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-503',
            'order_id' => $paymentOrder->number,
            'status' => 'PAID',
            'amount' => 112000,
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        // QRISLY status 500 -> 503 + Retry-After: 30
        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => ['history_id' => '9503', 'status' => 'paid'],
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);
        $this->assertSame(PaymentStatus::Pending, $qrislyOrder->fresh()->payment_status);

        // Delivery webhook (POST on 401, retry while passively unentitled, and PUT on 429) -> 503 + Retry-After: 30, never 500 or 200
        $this->postDeliveryWebhook([
            'order_no' => 'RO-WH-RES-1',
            'cnote' => 'JNE-RES-1',
            'status' => 'ON_PROCESS',
        ], 'POST')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        // Passive 401 revocation from the prior Delivery API call marked shipping_delivery unentitled;
        // without resetting KomerceCapabilities, a subsequent Delivery webhook must still return 503 (never 200)
        $this->assertFalse($this->komerceProductEnabled('shipping_delivery'));

        $this->postDeliveryWebhook([
            'order_no' => 'RO-WH-RES-1',
            'cnote' => 'JNE-RES-1',
            'status' => 'ON_PROCESS',
        ], 'PUT')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        $this->applyTier('komship')
            ->withDeliveryEntitled(false, 429, 'Too Many Requests')
            ->apply();

        $this->postDeliveryWebhook([
            'order_no' => 'RO-WH-RES-1',
            'cnote' => 'JNE-RES-1',
            'status' => 'ON_PROCESS',
        ], 'PUT')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);
    }

    public function test_orders_expire_unpaid_cancels_24h_local_grace_and_never_initiated_on_revoked_or_disabled_driver(): void
    {
        $customer = $this->fixtures->createVerifiedCustomer();

        // Case A: Revoked 401 Payment API driver (komerce.enabled=true, key present, payment.entitled=false)
        $this->applyTier('komship')
            ->withPaymentEntitled(false, 401, 'Invalid API key')
            ->apply();
        $this->observeVendorCapability('payment', 401, 'Invalid API key');

        $productExpired = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productGrace = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productNeverInit = $this->fixtures->createStockedProduct(100000, 10, 500);

        ['order' => $revokedExpired] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-REV-OLD', 'expiry_date' => now()->subHours(25)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-REV-OLD'],
            $productExpired,
        );
        $productExpired->decreaseStock($this->fixtures->inventory->id, 1);

        ['order' => $revokedGrace] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-REV-NEW', 'expiry_date' => now()->subHours(2)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-REV-NEW'],
            $productGrace,
        );

        ['order' => $revokedNeverInit] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'created_at' => now()->subHours(25),
                'metadata' => [],
            ],
            [],
            null,
            $productNeverInit,
        );
        $productNeverInit->decreaseStock($this->fixtures->inventory->id, 1);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $revokedExpired->fresh()->status);
        $this->assertSame(10, $productExpired->fresh()->stockInventory($this->fixtures->inventory->id));
        $this->assertSame(OrderStatus::New, $revokedGrace->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $revokedNeverInit->fresh()->status);
        $this->assertSame(10, $productNeverInit->fresh()->stockInventory($this->fixtures->inventory->id));
        Http::assertNothingSent();

        // Case B: Driver switched off completely (shopper.payment.drivers.komerce.enabled = false)
        $this->applyTier('starter', [
            'shopper.payment.drivers.komerce.enabled' => false,
        ]);

        $productOffOld = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productOffGrace = $this->fixtures->createStockedProduct(100000, 10, 500);

        ['order' => $offExpired] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-OFF-OLD', 'expiry_date' => now()->subHours(25)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-OFF-OLD'],
            $productOffOld,
        );
        $productOffOld->decreaseStock($this->fixtures->inventory->id, 1);

        ['order' => $offGrace] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-OFF-GRACE', 'expiry_date' => now()->subHours(2)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-OFF-GRACE'],
            $productOffGrace,
        );

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $offExpired->fresh()->status);
        $this->assertSame(10, $productOffOld->fresh()->stockInventory($this->fixtures->inventory->id));
        $this->assertSame(OrderStatus::New, $offGrace->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_delivery_webhook_returns_503_after_prior_delivery_api_passive_401_revocation(): void
    {
        $this->applyTier('komship')
            ->withDeliveryEntitled(false, 401, 'Invalid API Key')
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $fulfillOrder] = $this->fixtures->createOrderWithShipment($customer);
        ['order' => $webhookOrder, 'shipment' => $webhookShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'external_reference' => 'RO-WH-PASSIVE-401',
                'metadata' => ['komerce' => ['order_no' => 'RO-WH-PASSIVE-401']],
            ],
        );

        // Prior Delivery API call (Cpanel fulfillment) receives 401 and passively revokes shipping_delivery
        $this->cpanelOrderShipping($admin, $fulfillOrder)
            ->call('processAllDeliveryOrders');

        $this->assertFalse($this->komerceProductEnabled('shipping_delivery'));

        // Without resetting KomerceCapabilities via applyTier(), Delivery webhook must still return 503 (never 200)
        $this->postDeliveryWebhook([
            'order_no' => 'RO-WH-PASSIVE-401',
            'cnote' => 'JNE-PASSIVE-401',
            'status' => 'ON_PROCESS',
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        $this->assertNull($webhookShipment->fresh()->awb);
    }

    public function test_unauthenticated_delivery_webhook_does_not_poison_external_reference_before_detail_verification(): void
    {
        $this->applyTier('komship')
            ->withDeliveryEntitled(false, 401, 'Invalid API Key')
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $fulfillOrder] = $this->fixtures->createOrderWithShipment($customer);
        ['order' => $starterOrder, 'shipment' => $starterShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'rajaongkir',
                'external_reference' => null,
                'awb' => 'JNE-STARTER-MANUAL',
                'tracking_number' => 'JNE-STARTER-MANUAL',
                'status' => 'labeled',
                'metadata' => [],
            ],
        );

        // Passively revoke shipping_delivery via a failed Cpanel call
        $this->cpanelOrderShipping($admin, $fulfillOrder)
            ->call('processAllDeliveryOrders');
        $this->assertFalse($this->komerceProductEnabled('shipping_delivery'));

        // Spoofed Delivery webhook matching manual AWB must return 503 AND leave external_reference & metadata.komerce.order_no null
        $this->postDeliveryWebhook([
            'order_no' => 'RO-SPOOF-999',
            'cnote' => 'JNE-STARTER-MANUAL',
            'status' => 'ON_PROCESS',
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        $starterShipment->refresh();
        $this->assertNull($starterShipment->external_reference);
        $this->assertNull(data_get($starterShipment->metadata, 'komerce.order_no'));

        $this->cpanelOrderShipping($admin, $starterOrder)
            ->assertOk()
            ->assertDontSee('RO-SPOOF-999', false)
            ->assertDontSee('Terdaftar', false);

        // Even when shipping_delivery IS entitled, upstream 404 or mismatched AWB on detailOrder must not poison external_reference
        $this->applyTier('komship')
            ->withDeliveryDetailResponse(['order_no' => 'RO-OTHER', 'airway_bill' => 'JNE-OTHER-AWB', 'awb' => 'JNE-OTHER-AWB'])
            ->apply();

        $this->postDeliveryWebhook([
            'order_no' => 'RO-SPOOF-OTHER',
            'cnote' => 'JNE-STARTER-MANUAL',
            'status' => 'ON_PROCESS',
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        $starterShipment->refresh();
        $this->assertNull($starterShipment->external_reference);
        $this->assertNull(data_get($starterShipment->metadata, 'komerce.order_no'));
    }
}
