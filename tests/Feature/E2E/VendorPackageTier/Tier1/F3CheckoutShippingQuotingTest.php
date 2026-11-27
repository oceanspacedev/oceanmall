<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier1;

use App\Shipping\InventoryOrigins;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\VendorPackageTier\Support\StoreFixtureFactory;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F3CheckoutShippingQuotingTest extends VendorPackageTierTestCase
{
    public function test_keyless_tier_quotes_manual_zone_options_and_selects_option_on_web_and_api(): void
    {
        $this->applyTier('keyless');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser, [
            'destination_id' => null,
            'destination_label' => null,
            'destination_driver' => null,
        ])->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shippingRatesHint', null)
                ->where('deliveryOptions.0.driver', 'manual')
                ->where('deliveryOptions.0.service_code', $this->fixtures->manualOption->id)
                ->where('deliveryOptions.0.amount', 15000)
            );

        $this->webPickShippingOption($webUser, $this->fixtures->manualOption->id)
            ->assertRedirect(route('shop.checkout.index'));

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser, [
            'destination_id' => null,
            'destination_label' => null,
        ])->assertOk();

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_rates.0.driver', 'manual')
            ->assertJsonPath('data.shipping_rates.0.service_code', $this->fixtures->manualOption->id);

        $this->apiPickShippingOption($apiUser, $this->fixtures->manualOption->id)
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', $this->fixtures->manualOption->id);

        Http::assertNothingSent();
    }

    public function test_starter_tier_quotes_rajaongkir_cost_and_excludes_disabled_cpanel_carriers(): void
    {
        $this->fixtures->carriers['spx']->update(['is_enabled' => false]);

        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $options = collect($page->toArray()['props']['deliveryOptions']);
                $this->assertTrue($options->contains('carrier_code', 'jne'));
                $this->assertFalse($options->contains('carrier_code', 'spx'));
                $this->assertSame('rajaongkir', $options->firstWhere('carrier_code', 'jne')['driver'] ?? null);
            });

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $rates = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.shipping_rates'));
        $this->assertTrue($rates->contains('carrier_code', 'jne'));
        $this->assertFalse($rates->contains('carrier_code', 'spx'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/calculate/domestic-cost')
            && $request->hasHeader('key', 'cost-key')
            && $request->method() === 'POST'
            && (int) ($request->data()['weight'] ?? 0) === 500
            && (string) ($request->data()['origin'] ?? '') === '17248'
            && (string) ($request->data()['destination'] ?? '') === '17601');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/tariff/api/v1/calculate'));
    }

    public function test_komship_tier_prefers_delivery_tariff_over_cost_and_exposes_cod_and_cashback(): void
    {
        $this->applyTier('komship');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $options = collect($page->toArray()['props']['deliveryOptions']);
                $jne = $options->firstWhere('service_code', 'jne:JNEFlat');
                $this->assertNotNull($jne);
                $this->assertSame('komerce', $jne['driver']);
                $this->assertSame('shipping_delivery', $jne['provider']);
                $this->assertTrue((bool) $jne['is_cod']);
                $this->assertSame(1000, $jne['shipping_cashback']);
            });

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $rates = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.shipping_rates'));
        $apiJne = $rates->firstWhere('service_code', 'jne:JNEFlat');
        $this->assertNotNull($apiJne);
        $this->assertSame('komerce', $apiJne['driver']);
        $this->assertSame('shipping_delivery', $apiJne['provider']);
        $this->assertTrue((bool) $apiJne['is_cod']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/tariff/api/v1/calculate')
            && $request->hasHeader('x-api-key', 'delivery-key')
            && $request->method() === 'GET'
            && (float) ($request->data()['weight'] ?? 0) === 0.5
            && (string) ($request->data()['shipper_destination_id'] ?? '') === '17248'
            && (string) ($request->data()['receiver_destination_id'] ?? '') === '17601');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v1/calculate/domestic-cost'));
    }

    public function test_delivery_only_tier_quotes_and_selects_rates_without_shipping_cost_key(): void
    {
        resolve(InventoryOrigins::class)->put($this->fixtures->inventory, 'komerce', '17248');

        $this->applyTier('delivery_only');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $props = $page->toArray()['props'];
                $options = collect($props['deliveryOptions']);
                $this->assertTrue($options->isNotEmpty());
                $this->assertSame('komerce', $options->first()['driver']);
                $this->assertSame('17601', $props['shippingAddress']['destination_id'] ?? null);
                $this->assertSame('komerce', $props['shippingAddress']['destination_driver'] ?? null);
                $this->assertNull($props['shippingRatesHint']);
            });

        $this->webPickShippingOption($webUser, 'jne:JNEFlat')
            ->assertRedirect(route('shop.checkout.index'));

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $rates = $this->apiLoadCheckout($apiUser)->assertOk()->json('data.shipping_rates');
        $this->assertNotEmpty($rates);
        $this->assertSame('komerce', $rates[0]['driver']);

        $this->apiPickShippingOption($apiUser, 'jne:JNEFlat')
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'jne:JNEFlat');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/tariff/api/v1/calculate')
            && $request->hasHeader('x-api-key', 'delivery-key')
            && (float) ($request->data()['weight'] ?? 0) === 0.5);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v1/calculate/domestic-cost'));
    }

    public function test_combine_zone_options_and_multi_warehouse_split_shipments_quote_and_select_rates(): void
    {
        $this->fixtures = StoreFixtureFactory::seedBaseStore(
            withManualCarrier: true,
            withSecondInventory: true,
        );

        $this->applyTier('starter', [
            'shopper.shipping.combine_zone_options' => true,
        ]);

        $productA = $this->fixtures->createStockedProduct(100000, 5, 500, $this->fixtures->inventory);
        $productB = $this->fixtures->createStockedProduct(50000, 5, 500, $this->fixtures->secondInventory);

        // First test combine_zone_options on single-warehouse cart
        ['user' => $singleUser] = $this->webCustomerWithCart($productA);
        $this->webSaveShippingAddress($singleUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($singleUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $options = collect($page->toArray()['props']['deliveryOptions']);
                $this->assertTrue($options->contains('driver', 'rajaongkir'));
                $this->assertTrue($options->contains('driver', 'manual'));
            });

        // Multi-warehouse split shipment via API
        $apiUser = $this->apiCustomerWithCart($productA);
        $this->postJson('/api/v1/cart/items', ['product_id' => $productB->id, 'quantity' => 1])->assertOk();
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $checkout = $this->apiLoadCheckout($apiUser)->assertOk()->json('data');
        $this->assertCount(2, $checkout['allocation']);
        $this->assertArrayHasKey((string) $this->fixtures->inventory->id, $checkout['shipping_rates_by_shipment']);
        $this->assertArrayHasKey((string) $this->fixtures->secondInventory->id, $checkout['shipping_rates_by_shipment']);

        $this->apiPickShippingOption($apiUser, [
            $this->fixtures->inventory->id => 'jne:REG',
            $this->fixtures->secondInventory->id => 'jnt:EZ',
        ])
            ->assertOk()
            ->assertJsonPath("data.shipping_options_by_shipment.{$this->fixtures->inventory->id}", 'jne:REG')
            ->assertJsonPath("data.shipping_options_by_shipment.{$this->fixtures->secondInventory->id}", 'jnt:EZ');
    }
}
