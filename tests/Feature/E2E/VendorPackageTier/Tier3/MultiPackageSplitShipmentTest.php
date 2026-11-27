<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier3;

use App\Models\OrderShipment;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class MultiPackageSplitShipmentTest extends VendorPackageTierTestCase
{
    public function test_split_shipment_across_two_warehouses_succeeds_on_web_and_api_with_two_order_shipments(): void
    {
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/tariff/api/v1/calculate*',
                static function ($request) {
                    $pin = (string) ($request->data()['origin_pin_point'] ?? '');
                    $isBandung = str_starts_with($pin, '-6.9175');

                    return Http::response([
                        'meta' => ['code' => 200, 'status' => 'success'],
                        'data' => [
                            'calculate_reguler' => [
                                [
                                    'shipping_name' => 'JNE',
                                    'service_name' => 'JNEFlat',
                                    'weight' => 1,
                                    'is_cod' => true,
                                    'shipping_cost' => $isBandung ? 18000 : 12000,
                                    'shipping_cashback' => 1000,
                                    'shipping_cost_net' => $isBandung ? 17000 : 11000,
                                    'grandtotal' => $isBandung ? 68000 : 112000,
                                    'service_fee' => 0,
                                    'etd' => '1-2 day',
                                ],
                                [
                                    'shipping_name' => 'JNT',
                                    'service_name' => 'EZ',
                                    'weight' => 1,
                                    'is_cod' => true,
                                    'shipping_cost' => $isBandung ? 14000 : 11000,
                                    'shipping_cashback' => 0,
                                    'shipping_cost_net' => $isBandung ? 14000 : 11000,
                                    'grandtotal' => $isBandung ? 64000 : 111000,
                                    'service_fee' => 0,
                                    'etd' => '1-2 day',
                                ],
                            ],
                            'calculate_cargo' => [],
                            'calculate_instant' => [],
                        ],
                    ], 200);
                },
            )
            ->apply();

        $productCirebon = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $productBandung = $this->fixtures->createStockedProduct(50000, 10, 500, $secondInventory);

        // 1. Storefront Web: per-shipment rate selection across 2 warehouses -> 2 OrderShipments
        ['user' => $webUser, 'cart' => $webCart] = $this->webCustomerWithCart($productCirebon, 1, 100000);
        CartLine::query()->create([
            'cart_id' => $webCart->id,
            'purchasable_type' => $productBandung->getMorphClass(),
            'purchasable_id' => $productBandung->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($secondInventory): void {
                $props = $page->toArray()['props'];
                $this->assertCount(2, $props['allocation'] ?? []);
                $this->assertNotEmpty($props['deliveryOptionsByShipment'][$this->fixtures->inventory->id] ?? []);
                $this->assertNotEmpty($props['deliveryOptionsByShipment'][$secondInventory->id] ?? []);
                $this->assertNull($props['shippingRatesHint']);
            });

        // Single service_code on split shipment must be refused on Web
        $this->webPickShippingOption($webUser, 'jne:JNEFlat')
            ->assertRedirect()
            ->assertSessionHasErrors('service_code');

        // Per-shipment selection succeeds (12,000 + 14,000 = 26,000 shipping)
        $this->webPickShippingOptionsByShipment($webUser, [
            $this->fixtures->inventory->id => 'jne:JNEFlat',
            $secondInventory->id => 'jnt:EZ',
        ])->assertRedirect(route('shop.checkout.index'));

        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['manual']->id)
            ->assertRedirect();

        $webOrder = Order::query()->where('customer_id', $webUser->id)->latest('id')->firstOrFail();
        $this->assertSame(176000, (int) $webOrder->price_amount);

        $webShipments = OrderShipment::query()
            ->where('order_id', $webOrder->id)
            ->orderBy('inventory_id')
            ->get()
            ->keyBy('inventory_id');
        $this->assertCount(2, $webShipments);
        $this->assertSame(12000, (int) $webShipments[$this->fixtures->inventory->id]->cost);
        $this->assertSame('jne:JNEFlat', $webShipments[$this->fixtures->inventory->id]->service_code);
        $this->assertSame(14000, (int) $webShipments[$secondInventory->id]->cost);
        $this->assertSame('jnt:EZ', $webShipments[$secondInventory->id]->service_code);

        // 2. Customer API v1: common intersected rate list + per-shipment selection -> 2 OrderShipments
        $apiUser = $this->apiCustomerWithCart($productCirebon, 1);
        $this->apiAs($apiUser)->postJson('/api/v1/cart/items', [
            'product_id' => $productBandung->id,
            'quantity' => 1,
        ])->assertOk();

        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $checkoutRes = $this->apiLoadCheckout($apiUser)->assertOk();
        $this->assertCount(2, $checkoutRes->json('data.allocation'));
        $this->assertNotEmpty($checkoutRes->json('data.shipping_rates_by_shipment.'.$this->fixtures->inventory->id));
        $this->assertNotEmpty($checkoutRes->json('data.shipping_rates_by_shipment.'.$secondInventory->id));

        $commonRates = collect($checkoutRes->json('data.shipping_rates'))->keyBy('service_code');
        $this->assertSame(30000, (int) data_get($commonRates->get('jne:JNEFlat'), 'amount'));
        $this->assertSame(25000, (int) data_get($commonRates->get('jnt:EZ'), 'amount'));

        $this->apiPickShippingOption($apiUser, [
            $this->fixtures->inventory->id => 'jne:JNEFlat',
            $secondInventory->id => 'jnt:EZ',
        ])->assertOk();

        $placed = $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['manual']->id)
            ->assertCreated();

        $apiOrder = Order::query()->where('number', $placed->json('data.number'))->firstOrFail();
        $this->assertSame(176000, (int) $apiOrder->price_amount);
        $this->assertSame(2, OrderShipment::query()->where('order_id', $apiOrder->id)->count());
    }

    public function test_partial_empty_package_split_surfaces_hint_on_web_and_api(): void
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

        $productCirebon = $this->fixtures->createStockedProduct(100000, 5, 500, $this->fixtures->inventory);
        $productBandung = $this->fixtures->createStockedProduct(50000, 5, 500, $secondInventory);

        // Web: partial empty package must still surface shippingRatesHint (M4 #12)
        ['user' => $webUser, 'cart' => $webCart] = $this->webCustomerWithCart($productCirebon, 1, 100000);
        CartLine::query()->create([
            'cart_id' => $webCart->id,
            'purchasable_type' => $productBandung->getMorphClass(),
            'purchasable_id' => $productBandung->id,
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
                $this->assertNotSame('', (string) $props['shippingRatesHint']);
            });

        // API v1: shipping_rates is empty, shipping_rates_hint & shipping_rates_failure populated (M4 #14)
        $apiUser = $this->apiCustomerWithCart($productCirebon, 1);
        $this->apiAs($apiUser)->postJson('/api/v1/cart/items', [
            'product_id' => $productBandung->id,
            'quantity' => 1,
        ])->assertOk();

        $this->apiSaveShippingAddress($apiUser)->assertOk();

        $apiCheckout = $this->apiLoadCheckout($apiUser)->assertOk();
        $this->assertNotEmpty($apiCheckout->json('data.shipping_rates_by_shipment.'.$this->fixtures->inventory->id));
        $this->assertSame([], $apiCheckout->json('data.shipping_rates_by_shipment.'.$secondInventory->id));
        $this->assertSame([], $apiCheckout->json('data.shipping_rates'));
        $this->assertNotNull($apiCheckout->json('data.shipping_rates_hint'));
        $this->assertNotSame('', (string) $apiCheckout->json('data.shipping_rates_hint'));
        $this->assertNotNull($apiCheckout->json('data.shipping_rates_failure'));
    }
}
