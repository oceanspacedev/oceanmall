<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier3;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Shipping\InventoryOrigins;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class CodCompatibilityAndPaymentFilterTest extends VendorPackageTierTestCase
{
    public function test_cod_compatibility_across_starter_delivery_only_and_komship_rates_on_web_and_api(): void
    {
        $product = $this->fixtures->createStockedProduct(100000, 20, 500);

        // 1. Starter (Cost quotes omit is_cod) -> COD order allowed
        $this->applyTier('starter');
        $starterApiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($starterApiUser)->assertOk();
        $this->apiPickShippingOption($starterApiUser, 'jne:REG')->assertOk();
        $starterPlaced = $this->apiPlaceOrder($starterApiUser, $this->fixtures->paymentMethods['cod']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'none');

        $this->apiShowOrder($starterApiUser, (string) $starterPlaced->json('data.number'))
            ->assertOk()
            ->assertJsonPath('data.is_cod', true);

        // 2. Delivery-Only: jne:JNEFlat (is_cod: true) -> COD allowed; spx:SPXSTD (is_cod: false) -> COD rejected pre-order
        $this->applyTier('delivery_only');
        resolve(InventoryOrigins::class)->put($this->fixtures->inventory, 'komerce', '17248');

        ['user' => $deliveryWebUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($deliveryWebUser)->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($deliveryWebUser, 'spx:SPXSTD')->assertRedirect(route('shop.checkout.index'));

        $ordersBefore = Order::query()->count();
        $this->webPlaceOrder($deliveryWebUser, $this->fixtures->paymentMethods['cod']->id)
            ->assertRedirect()
            ->assertSessionHasErrors('payment');
        $this->assertSame($ordersBefore, Order::query()->count());

        $deliveryApiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($deliveryApiUser)->assertOk();
        $this->apiPickShippingOption($deliveryApiUser, 'spx:SPXSTD')->assertOk();
        $this->apiPlaceOrder($deliveryApiUser, $this->fixtures->paymentMethods['cod']->id)->assertStatus(422);
        $this->assertSame($ordersBefore, Order::query()->count());

        // 3. Komship: jne:JNEFlat (is_cod: true) -> COD order placed & Cpanel fulfillment sends payment_method=COD
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();

        $komshipApiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($komshipApiUser)->assertOk();
        $this->apiPickShippingOption($komshipApiUser, 'jne:JNEFlat')->assertOk();

        $codPlaced = $this->apiPlaceOrder($komshipApiUser, $this->fixtures->paymentMethods['cod']->id)
            ->assertCreated()
            ->assertJsonPath('data.can_retry_payment', false);

        $codOrderNumber = (string) $codPlaced->json('data.number');
        $this->apiShowOrder($komshipApiUser, $codOrderNumber)
            ->assertOk()
            ->assertJsonPath('data.is_cod', true);

        $codOrder = Order::query()->where('number', $codOrderNumber)->firstOrFail();
        $this->assertSame(OrderStatus::New, $codOrder->status);
        $this->assertSame(PaymentStatus::Pending, $codOrder->payment_status);

        $this->cpanelOrderShipping($admin, $codOrder)
            ->assertSee('RO-E2E-1', false);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/store')
            && ($req->data()['payment_method'] ?? null) === 'COD'
            && (int) ($req->data()['cod_value'] ?? 0) === 112000);
    }

    public function test_split_shipment_mixed_cod_rejected_and_payment_catalog_filtered_across_tiers(): void
    {
        $secondInventory = $this->fixtures->ensureSecondInventory();
        $productA = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $productB = $this->fixtures->createStockedProduct(50000, 10, 500, $secondInventory);

        // 1. Mixed COD in split shipment (jne:JNEFlat is_cod=true + spx:SPXSTD is_cod=false) -> rejected pre-order
        $this->applyTier('komship');

        ['user' => $webUser, 'cart' => $webCart] = $this->webCustomerWithCart($productA, 1, 100000);
        CartLine::query()->create([
            'cart_id' => $webCart->id,
            'purchasable_type' => $productB->getMorphClass(),
            'purchasable_id' => $productB->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOptionsByShipment($webUser, [
            $this->fixtures->inventory->id => 'jne:JNEFlat',
            $secondInventory->id => 'spx:SPXSTD',
        ])->assertRedirect(route('shop.checkout.index'));

        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['cod']->id)
            ->assertRedirect()
            ->assertSessionHasErrors('payment');
        $this->assertSame(0, Order::query()->count());

        $apiUser = $this->apiCustomerWithCart($productA, 1);
        $this->apiAs($apiUser)->postJson('/api/v1/cart/items', [
            'product_id' => $productB->id,
            'quantity' => 1,
        ])->assertOk();
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, [
            $this->fixtures->inventory->id => 'jne:JNEFlat',
            $secondInventory->id => 'spx:SPXSTD',
        ])->assertOk();

        $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['cod']->id)->assertStatus(422);
        $this->assertSame(0, Order::query()->count());

        // 2. Payment catalog filtering across Starter, Delivery-Only, and Komship
        $this->applyTier('starter');
        $starterCatalog = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
            ->keyBy('slug');
        $this->assertTrue($starterCatalog->has('komerce-va-bca'));
        $this->assertFalse($starterCatalog->has('komerce-va-bri')); // not in Starter catalog mock
        $this->assertTrue($starterCatalog->has('komerce-qris'));
        $this->assertSame(10000, (int) data_get($starterCatalog->get('komerce-qris'), 'min_amount'));

        $this->applyTier('delivery_only');
        // Mark payment & qrisly unentitled (same as real 401 probe on delivery_only)
        $this->observeVendorCapability('payment', 401, 'Invalid API key');
        $deliveryCatalog = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'));
        $this->assertNotContains('komerce', $deliveryCatalog->pluck('driver')->all());
        $this->assertContains('manual', $deliveryCatalog->pluck('driver')->all());

        $this->applyTier('komship');
        $komshipCatalog = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
            ->keyBy('slug');
        $this->assertTrue($komshipCatalog->has('komerce-va-bca'));
        $this->assertTrue($komshipCatalog->has('komerce-qris'));
        $this->assertSame(1000, (int) data_get($komshipCatalog->get('komerce-qris'), 'min_amount'));
        $this->assertSame(100000000, (int) data_get($komshipCatalog->get('komerce-qris'), 'max_amount'));

        // Web checkout step 3 reflects identical QRIS bounds & COD option
        $this->webLoadCheckout($webUser, 3)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $options = collect($page->toArray()['props']['paymentOptions'])->keyBy('slug');
                $this->assertTrue($options->has('komerce-va-bca'));
                $this->assertTrue($options->has('komerce-qris'));
                $this->assertSame(1000, (int) data_get($options->get('komerce-qris'), 'min_amount'));
            });
    }

    public function test_split_shipment_cod_order_renders_cpanel_and_prorates_order_level_discount_without_over_collecting(): void
    {
        $secondInventory = $this->fixtures->ensureSecondInventory();
        $productA = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $productB = $this->fixtures->createStockedProduct(50000, 10, 500, $secondInventory);

        Discount::query()->create([
            'code' => 'HEMAT30K',
            'is_active' => true,
            'type' => DiscountType::FixedAmount,
            'value' => 30000,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::None->value,
            'eligibility' => DiscountEligibility::Everyone->value,
            'usage_limit' => 10,
            'usage_limit_per_user' => false,
            'total_use' => 0,
            'start_at' => now()->subDay(),
            'end_at' => now()->addYear(),
        ]);

        $storeSeq = 0;

        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/tariff/api/v1/calculate*',
                static function (Request $request) {
                    $originId = (string) ($request['shipper_destination_id'] ?? '');
                    $isFirst = $originId === '17248';
                    $cost = $isFirst ? 12000 : 18000;
                    $grandTotal = $isFirst ? 112000 : 68000;

                    return Http::response([
                        'meta' => ['code' => 200, 'status' => 'success'],
                        'data' => [
                            'calculate_reguler' => [[
                                'shipping_name' => 'JNE',
                                'service_name' => 'JNEFlat',
                                'weight' => 1,
                                'is_cod' => true,
                                'shipping_cost' => $cost,
                                'shipping_cashback' => 1000,
                                'shipping_cost_net' => $cost - 1000,
                                'grandtotal' => $grandTotal,
                                'service_fee' => 0,
                                'etd' => '1-2 day',
                            ]],
                            'calculate_cargo' => [],
                            'calculate_instant' => [],
                        ],
                    ], 200);
                },
            )
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/store*',
                static function () use (&$storeSeq) {
                    $storeSeq++;

                    return Http::response([
                        'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Create New Order'],
                        'data' => ['order_id' => 30000 + $storeSeq, 'order_no' => "RO-SPLIT-COD-{$storeSeq}"],
                    ], 200);
                },
            )
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/pickup/request*',
                static function (Request $request) {
                    $orderNo = (string) data_get($request->data(), 'orders.0.order_no', 'RO-SPLIT-COD-1');

                    return Http::response([
                        'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Request Pickup'],
                        'data' => [[
                            'status' => 'success',
                            'order_no' => $orderNo,
                            'awb' => "AWB-{$orderNo}",
                        ]],
                    ], 200);
                },
            )
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $apiUser = $this->apiCustomerWithCart($productA, 1);
        $this->apiAs($apiUser)->postJson('/api/v1/cart/items', [
            'product_id' => $productB->id,
            'quantity' => 1,
        ])->assertOk();
        $this->apiAs($apiUser)->postJson('/api/v1/cart/coupon', ['code' => 'HEMAT30K'])->assertOk();
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, [
            $this->fixtures->inventory->id => 'jne:JNEFlat',
            $secondInventory->id => 'jne:JNEFlat',
        ])->assertOk();

        $placed = $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['cod']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'none');

        $order = Order::query()->where('number', (string) $placed->json('data.number'))->firstOrFail();
        // 100,000 + 50,000 - 30,000 coupon + 12,000 + 18,000 = 150,000
        $this->assertSame(150000, (int) $order->price_amount);

        // Cpanel presenter must not crash with TypeError and must prorate cod_amount to order->price_amount
        $presentedShipments = resolve(OrderShipmentOpsPresenter::class)->shipments($order);
        $this->assertCount(2, $presentedShipments);
        $this->assertSame([93333, 56667], array_column($presentedShipments, 'cod_amount'));
        $this->assertSame(150000, array_sum(array_column($presentedShipments, 'cod_amount')));

        // Cpanel KomerceOrderShipping mounts and renders cleanly, showing both fulfilled COD parcels
        $this->cpanelOrderShipping($admin, $order)
            ->assertOk()
            ->assertSee('RO-SPLIT-COD-1', false)
            ->assertSee('RO-SPLIT-COD-2', false);

        $storeRequests = collect(Http::recorded())
            ->map(static fn (array $pair): Request => $pair[0])
            ->filter(static fn (Request $req): bool => str_contains($req->url(), '/order/api/v1/orders/store'))
            ->values();

        $this->assertCount(2, $storeRequests);
        $codValues = $storeRequests->map(static fn (Request $req): int => (int) ($req->data()['cod_value'] ?? -1))->all();
        $this->assertSame([93333, 56667], $codValues);
        $this->assertSame((int) $order->price_amount, array_sum($codValues));
        foreach ($storeRequests as $req) {
            $this->assertSame('COD', $req->data()['payment_method'] ?? null);
        }

        // Cancelling one shipment reassigns full remaining payable COD amount to the single active parcel
        $firstShipment = OrderShipment::query()->where('order_id', $order->id)->orderBy('id')->firstOrFail();
        $firstShipment->forceFill(['status' => NormalizeShipmentStatus::CANCELLED])->save();

        $afterCancel = resolve(OrderShipmentOpsPresenter::class)->shipments($order);
        $this->assertSame(0, $afterCancel[0]['cod_amount']);
        $this->assertSame(150000, $afterCancel[1]['cod_amount']);
    }
}
