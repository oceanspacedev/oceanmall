<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier4;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Scenario7MidFlightRevocationTest extends VendorPackageTierTestCase
{
    public function test_mid_flight_entitlement_revocation_across_catalog_fulfillment_webhooks_and_local_expiry(): void
    {
        $this->applyTier('komship');

        $admin = $this->fixtures->createAdmin();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);
        ['user' => $webUser] = $this->webCustomerWithCart($product, 1, 100000);
        $apiUser = $this->apiCustomerWithCart($product, 1);

        // 1. Warm API payment catalog in full Komship (VA + QRIS active)
        $warmedCatalog = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
            ->pluck('slug')
            ->all();
        $this->assertContains('komerce-va-bca', $warmedCatalog);
        $this->assertContains('komerce-qris', $warmedCatalog);

        // 2. Payment API returns 401 mid-flight -> clear payment catalog cache via resetTierState(flushCache: false)
        //    Web home, Web checkout, and API checkout drop ALL VA methods (no first-method leak) while keeping QRISLY QRIS
        $this->resetTierState(flushCache: false);
        KomerceHttpFakeBuilder::forTier('komship')
            ->withPaymentEntitled(false, 401, 'Invalid API key')
            ->apply();

        $this->webLoadHome($webUser)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $slugs = collect($page->toArray()['props']['shop']['payment_methods'])->pluck('key')->all();
                $this->assertNotContains('komerce-va-bca', $slugs);
                $this->assertNotContains('komerce-va-bri', $slugs);
                $this->assertContains('komerce-qris', $slugs);
            });

        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webLoadCheckout($webUser, 3)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $slugs = collect($page->toArray()['props']['paymentOptions'])->pluck('slug')->all();
                $this->assertNotContains('komerce-va-bca', $slugs);
                $this->assertNotContains('komerce-va-bri', $slugs);
                $this->assertContains('komerce-qris', $slugs);
            });

        $apiCatalogAfter401 = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
            ->pluck('slug')
            ->all();
        $this->assertNotContains('komerce-va-bca', $apiCatalogAfter401);
        $this->assertNotContains('komerce-va-bri', $apiCatalogAfter401);
        $this->assertContains('komerce-qris', $apiCatalogAfter401);

        // 3. Delivery API returns 401 on Cpanel processAllDeliveryOrders -> persists fulfillment_error,
        //    then manual AWB via CreateShippingLabel clears stale metadata.komerce.fulfillment_error (M3 #10)
        ['order' => $paidOrder, 'shipment' => $paidShipment] = $this->fixtures->createOrderWithShipment($apiUser);

        KomerceHttpFakeBuilder::forTier('komship')
            ->withDeliveryEntitled(false, 401, 'Invalid API Key')
            ->apply();

        $this->cpanelOrderShipping($admin, $paidOrder)
            ->call('processAllDeliveryOrders');

        $this->assertFalse($this->komerceProductEnabled('shipping_delivery'));
        $this->assertNotNull(data_get($paidShipment->fresh()->metadata, 'komerce.fulfillment_error'));

        $this->cpanelOrderFulfillment($admin, $paidOrder)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->assertDontSee('Terbitkan Resi Komerce', false);

        $this->cpanelCreateShippingLabel($admin, $paidOrder)
            ->set('data.tracking_number', 'JNE-REVOKED-MANUAL-1')
            ->call('save')
            ->assertHasNoErrors();

        $paidShipment->refresh();
        $this->assertSame('JNE-REVOKED-MANUAL-1', $paidShipment->awb);
        $this->assertNull(data_get($paidShipment->metadata, 'komerce.fulfillment_error'));

        // 4. Delivery webhook 401 & 429 return 503 with Retry-After: 30 (never 500)
        ['order' => $webhookOrder] = $this->fixtures->createOrderWithShipment(
            $apiUser,
            [],
            ['external_reference' => 'RO-REVOKED-WH-1', 'metadata' => ['komerce' => ['order_no' => 'RO-REVOKED-WH-1']]],
        );

        foreach ([401, 429] as $status) {
            KomerceHttpFakeBuilder::forTier('komship')
                ->withDeliveryEntitled(false, $status, $status === 401 ? 'Invalid API Key' : 'Too Many Requests')
                ->apply();

            $this->postDeliveryWebhook([
                'order_no' => 'RO-REVOKED-WH-1',
                'cnote' => 'JNE-WH-1',
                'status' => 'ON_PROCESS',
            ])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '30')
                ->assertJson(['status' => 'lookup_failed']);
        }

        // 5. Switch off Komerce payment driver -> orders:expire-unpaid cancels 25h-expired & 25h-never-initiated
        //    locally with zero HTTP calls while sparing 2h-expired order inside 24h local grace window
        config()->set('shopper.payment.drivers.komerce.enabled', false);

        $productOld = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productGrace = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productNever = $this->fixtures->createStockedProduct(100000, 10, 500);

        ['order' => $expired25h] = $this->fixtures->createOrderWithShipment(
            $apiUser,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-EXP-25H', 'expiry_date' => now()->subHours(25)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-EXP-25H'],
            $productOld,
        );
        $productOld->decreaseStock($this->fixtures->inventory->id, 1);

        ['order' => $expired2h] = $this->fixtures->createOrderWithShipment(
            $apiUser,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-EXP-2H', 'expiry_date' => now()->subHours(2)->toDateTimeString()]],
            ],
            [],
            ['reference' => 'KOMPAY-EXP-2H'],
            $productGrace,
        );

        ['order' => $neverInit25h] = $this->fixtures->createOrderWithShipment(
            $apiUser,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'created_at' => now()->subHours(25),
                'metadata' => [],
            ],
            [],
            null,
            $productNever,
        );
        $productNever->decreaseStock($this->fixtures->inventory->id, 1);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $expired25h->fresh()->status);
        $this->assertSame(10, $productOld->fresh()->stockInventory($this->fixtures->inventory->id));
        $this->assertSame(OrderStatus::New, $expired2h->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $neverInit25h->fresh()->status);
        $this->assertSame(10, $productNever->fresh()->stockInventory($this->fixtures->inventory->id));

        Http::assertNothingSent();
    }
}
