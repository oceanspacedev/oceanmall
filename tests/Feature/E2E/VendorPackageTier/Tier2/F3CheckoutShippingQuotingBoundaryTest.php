<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier2;

use App\Shipping\InventoryOrigins;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\CartLine;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F3CheckoutShippingQuotingBoundaryTest extends VendorPackageTierTestCase
{
    public function test_first_429_quota_response_is_immediately_classified_as_quota_hint_on_web_and_api(): void
    {
        $this->applyTier('starter')
            ->withCostQuotaExhausted(429, 'Daily request limit exceeded')
            ->apply();

        $product = $this->fixtures->createStockedProduct();

        // Web: the very first request that hits 429 must immediately show WIB quota hint
        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $hint = (string) ($page->toArray()['props']['shippingRatesHint'] ?? '');
                $this->assertStringContainsString('Batas cek ongkir harian', $hint);
                $this->assertStringContainsString('WIB', $hint);
                $this->assertSame([], $page->toArray()['props']['deliveryOptions']);
            });

        // Subsequent API request on already-exhausted quota sends zero Cost calls and returns structured quota failure
        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();

        $apiCheckout = $this->apiLoadCheckout($apiUser)->assertOk();
        $this->assertSame([], $apiCheckout->json('data.shipping_rates'));
        $this->assertStringContainsString('Batas cek ongkir harian', (string) $apiCheckout->json('data.shipping_rates_hint'));
        $this->assertSame('quota', $apiCheckout->json('data.shipping_rates_failure.reason'));
        $this->assertNotEmpty($apiCheckout->json('data.shipping_rates_failure.retry_at_wib'));

        Http::assertNothingSent();
    }

    public function test_provider_500_outage_surfaces_unavailable_hint_and_falls_back_only_when_configured(): void
    {
        $this->applyTier('starter')
            ->withCostOutage(500)
            ->apply();

        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        // Default fallback_to_zone_options = false -> empty rates + unavailable hint
        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $props = $page->toArray()['props'];
                $this->assertSame([], $props['deliveryOptions']);
                $this->assertStringContainsString('gangguan koneksi', (string) $props['shippingRatesHint']);
            });

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_rates', [])
            ->assertJsonPath('data.shipping_rates_failure.reason', 'unavailable');

        // With fallback_to_zone_options = true -> manual zone option offered
        config()->set('shopper.shipping.fallback_to_zone_options', true);

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'manual')
                ->where('shippingRatesHint', null)
            );
    }

    public function test_missing_warehouse_origin_or_allocation_failure_surfaces_actionable_hints(): void
    {
        $this->applyTier('starter');
        $outOfStockProduct = $this->fixtures->createStockedProduct(100000, 5, 500);

        // Add item to cart, then deplete stock to 0 across warehouses -> allocation failure hint
        $apiUser = $this->apiCustomerWithCart($outOfStockProduct);
        $outOfStockProduct->decreaseStock($this->fixtures->inventory->id, 5);

        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.allocation', null)
            ->assertJsonPath('data.shipping_rates', [])
            ->assertJsonPath('data.shipping_rates_failure.reason', 'no_allocation');

        $hint = (string) $this->apiLoadCheckout($apiUser)->json('data.shipping_rates_hint');
        $this->assertStringContainsString('Stok produk', $hint);

        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), KomerceHttpFakeBuilder::COST_BASE)
            || str_contains($req->url(), KomerceHttpFakeBuilder::DELIVERY_BASE));
        Http::assertNotSent(fn ($req): bool => ! str_contains($req->url(), '/api/v1/user/methods'));
    }

    public function test_partial_multi_package_empty_rate_surfaces_hint_on_web(): void
    {
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $this->applyTier('starter')
            ->withRoute(
                KomerceHttpFakeBuilder::COST_BASE.'/api/v1/calculate/domestic-cost*',
                static function ($request) {
                    $origin = (string) ($request->data()['origin'] ?? '');
                    if ($origin === '17249') {
                        return Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => []], 200);
                    }

                    return Http::response([
                        'meta' => ['code' => 200, 'status' => 'success'],
                        'data' => [
                            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Layanan Reguler', 'cost' => 18000, 'etd' => '2-3'],
                        ],
                    ], 200);
                },
            )
            ->apply();

        $productA = $this->fixtures->createStockedProduct(100000, 5, 500, $this->fixtures->inventory);
        $productB = $this->fixtures->createStockedProduct(50000, 5, 500, $secondInventory);

        ['user' => $webUser, 'cart' => $cart] = $this->webCustomerWithCart($productA);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $productB->getMorphClass(),
            'purchasable_id' => $productB->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($secondInventory): void {
                $props = $page->toArray()['props'];
                $this->assertNotEmpty($props['deliveryOptionsByShipment'][$this->fixtures->inventory->id] ?? []);
                $this->assertSame([], $props['deliveryOptionsByShipment'][$secondInventory->id] ?? []);
                $this->assertNotNull($props['shippingRatesHint']);
            });
    }

    public function test_stale_shipping_option_voided_on_address_change_or_cart_change_and_rejects_unknown_service_code(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, 'jne:REG')
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'jne:REG');

        // Reject unknown service_code with 422
        $this->apiPickShippingOption($apiUser, 'unknown:BOGUS')
            ->assertStatus(422);

        // Changing shipping address voids previously picked shipping_option
        $this->apiSaveShippingAddress($apiUser, [
            'street_address' => 'Jl. Baru No. 2',
            'destination_id' => '17547',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_option', null);

        // Re-pick then modify cart -> shipping_option voided
        $this->apiPickShippingOption($apiUser, 'jne:REG')->assertOk();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_option', null);
    }

    public function test_delivery_only_missing_origin_or_missing_destination_coordinates_fails_cleanly_without_500(): void
    {
        // 1. Missing origin in delivery_only -> SuggestAllocation yields no allocation, zero tariff calls, no 500
        resolve(InventoryOrigins::class)->forget($this->fixtures->inventory, 'rajaongkir');
        resolve(InventoryOrigins::class)->forget($this->fixtures->inventory, 'komerce');
        $this->fixtures->inventory->update(['rajaongkir_origin_id' => null]);

        $this->applyTier('delivery_only');
        $product = $this->fixtures->createStockedProduct();

        $missingOriginApi = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($missingOriginApi, [
            'destination_id' => '17601',
            'pin_point' => null,
        ])->assertOk();

        $this->apiLoadCheckout($missingOriginApi)
            ->assertOk()
            ->assertJsonPath('data.allocation', null)
            ->assertJsonPath('data.shipping_rates', []);

        ['user' => $missingOriginWeb] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($missingOriginWeb, [
            'destination_id' => '17601',
            'pin_point' => null,
        ])->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($missingOriginWeb, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $props = $page->toArray()['props'];
                $this->assertSame([], $props['deliveryOptions']);
                $this->assertStringContainsString('Stok produk', (string) ($props['shippingRatesHint'] ?? ''));
            });

        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/tariff/api/v1/calculate'));

        // 2. Valid komerce origin + missing customer pin_point + Nominatim miss ->
        //    SuggestAllocation allocates warehouse, DestinationCoordinateResolver falls back to warehouse pin,
        //    and KomerceShippingDriver::quote() executes cleanly without 500.
        resolve(InventoryOrigins::class)->put($this->fixtures->inventory, 'komerce', '17248');

        $this->applyTier('delivery_only', [], applyHttpFake: false)
            ->withRoute('https://nominatim.openstreetmap.org/*', Http::response([], 200))
            ->apply();

        $missingPinApi = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($missingPinApi, [
            'destination_id' => '17601',
            'pin_point' => null,
        ])->assertOk();

        $pinRes = $this->apiLoadCheckout($missingPinApi)->assertOk();
        $this->assertCount(1, $pinRes->json('data.allocation'));
        $this->assertNotEmpty($pinRes->json('data.shipping_rates'));

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'nominatim.openstreetmap.org/search'));
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/tariff/api/v1/calculate')
            && $req->hasHeader('x-api-key', 'delivery-key')
            && ($req->data()['destination_pin_point'] ?? null) === '-6.7366,108.5414');

        // 3. Valid komerce origin + missing warehouse coordinates AND missing customer pin_point ->
        //    SuggestAllocation allocates warehouse, KomerceShippingDriver::quote() executes and degrades to [] without 500.
        $this->fixtures->inventory->update(['latitude' => null, 'longitude' => null]);

        $this->applyTier('delivery_only', [], applyHttpFake: false)
            ->withRoute('https://nominatim.openstreetmap.org/*', Http::response([], 200))
            ->apply();

        $noCoordsApi = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($noCoordsApi, [
            'destination_id' => '17601',
            'pin_point' => null,
        ])->assertOk();

        $noCoordsRes = $this->apiLoadCheckout($noCoordsApi)->assertOk();
        $this->assertCount(1, $noCoordsRes->json('data.allocation'));
        $this->assertSame([], $noCoordsRes->json('data.shipping_rates'));

        ['user' => $noCoordsWeb] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($noCoordsWeb, [
            'destination_id' => '17601',
            'pin_point' => null,
        ])->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($noCoordsWeb, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $props = $page->toArray()['props'];
                $this->assertSame([], $props['deliveryOptions']);
                $this->assertStringContainsString('Belum ada opsi pengiriman', (string) ($props['shippingRatesHint'] ?? ''));
            });

        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/tariff/api/v1/calculate'));
    }

    public function test_delivery_only_reuses_migrated_rajaongkir_origin_for_checkout_allocation(): void
    {
        // Base fixtures seed only driver='rajaongkir' origin (simulating Starter -> Delivery-only upgrade
        // before re-saving inventories). SuggestAllocation + KomerceShippingDriver must still quote.
        $this->applyTier('delivery_only');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $options = collect($page->toArray()['props']['deliveryOptions']);
                $this->assertTrue($options->isNotEmpty());
                $this->assertSame('komerce', $options->first()['driver']);
            });

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $rates = $this->apiLoadCheckout($apiUser)->assertOk()->json('data.shipping_rates');
        $this->assertNotEmpty($rates);
        $this->assertSame('komerce', $rates[0]['driver']);
    }
}
