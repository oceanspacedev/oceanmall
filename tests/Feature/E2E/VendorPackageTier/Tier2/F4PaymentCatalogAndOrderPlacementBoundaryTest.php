<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F4PaymentCatalogAndOrderPlacementBoundaryTest extends VendorPackageTierTestCase
{
    public function test_payment_api_401_and_403_revoke_all_va_methods_without_leaking_first_method(): void
    {
        foreach ([401, 403] as $status) {
            $this->applyTier('starter')
                ->withPaymentEntitled(false, $status, 'Invalid API key')
                ->apply();

            $product = $this->fixtures->createStockedProduct();
            ['user' => $webUser] = $this->webCustomerWithCart($product);
            $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

            // Footer must NOT leak the first Komerce VA method when /user/methods returns 401/403
            $this->webLoadHome($webUser)
                ->assertOk()
                ->assertInertia(function (Assert $page) use ($status): void {
                    $drivers = collect($page->toArray()['props']['shop']['payment_methods'])->pluck('driver')->all();
                    $this->assertNotContains('komerce', $drivers, "Footer leaked komerce method on HTTP {$status}");
                });

            // Checkout Web paymentOptions must NOT leak any Komerce VA method
            $this->webLoadCheckout($webUser, 3)
                ->assertOk()
                ->assertInertia(function (Assert $page) use ($status): void {
                    $drivers = collect($page->toArray()['props']['paymentOptions'])->pluck('driver')->all();
                    $this->assertNotContains('komerce', $drivers, "Checkout leaked komerce method on HTTP {$status}");
                });

            // API payment_methods must NOT leak any Komerce VA method
            $this->resetTierState();
            $this->applyTier('starter')
                ->withPaymentEntitled(false, $status, 'Invalid API key')
                ->apply();

            $apiUser = $this->apiCustomerWithCart($product);
            $drivers = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
                ->pluck('driver')
                ->all();
            $this->assertNotContains('komerce', $drivers, "API checkout leaked komerce method on HTTP {$status}");
        }
    }

    public function test_qris_and_va_amount_boundaries_are_enforced_pre_order_on_web_and_api(): void
    {
        $this->fixtures->manualOption->update(['price' => 0]);

        // QRISLY-only bounds: min Rp 1,000, max Rp 100,000,000
        $this->applyTier('qrisly_only');

        // Below minimum (Rp 999) -> rejected pre-order
        $tooCheap = $this->fixtures->createStockedProduct(999, 10, 500);
        $apiUser = $this->apiCustomerWithCart($tooCheap);
        $this->apiSaveShippingAddress($apiUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($apiUser, $this->fixtures->manualOption->id)->assertOk();
        $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['qris']->id)->assertStatus(422);
        $this->assertSame(0, Order::query()->count());

        // Above maximum (Rp 100,000,001) -> rejected pre-order
        $tooExpensive = $this->fixtures->createStockedProduct(100000001, 10, 500);
        $maxUser = $this->apiCustomerWithCart($tooExpensive);
        $this->apiSaveShippingAddress($maxUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($maxUser, $this->fixtures->manualOption->id)->assertOk();
        $this->apiPlaceOrder($maxUser, $this->fixtures->paymentMethods['qris']->id)->assertStatus(422);
        $this->assertSame(0, Order::query()->count());

        // Exact lower boundary (Rp 1,000) and upper boundary (Rp 100,000,000) -> accepted
        $exactMin = $this->fixtures->createStockedProduct(1000, 10, 500);
        $minUser = $this->apiCustomerWithCart($exactMin);
        $this->apiSaveShippingAddress($minUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($minUser, $this->fixtures->manualOption->id)->assertOk();
        $this->apiPlaceOrder($minUser, $this->fixtures->paymentMethods['qris']->id)->assertCreated();

        $exactMax = $this->fixtures->createStockedProduct(100000000, 10, 500);
        $upperUser = $this->apiCustomerWithCart($exactMax);
        $this->apiSaveShippingAddress($upperUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($upperUser, $this->fixtures->manualOption->id)->assertOk();
        $this->apiPlaceOrder($upperUser, $this->fixtures->paymentMethods['qris']->id)->assertCreated();

        // Payment API VA bounds: Rp 9,999 rejected pre-order on Web & API, Rp 10,000 accepted
        // Set cost quote to 0 so payable matches exact product price
        $this->applyTier('starter')
            ->withCostQuotes([
                ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 0, 'etd' => '2-3'],
            ])
            ->apply();

        $belowVa = $this->fixtures->createStockedProduct(9999, 10, 500);
        ['user' => $webUser] = $this->webCustomerWithCart($belowVa);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));

        $ordersBefore = Order::query()->count();
        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['bca']->id)
            ->assertRedirect()
            ->assertSessionHasErrors('payment');
        $this->assertSame($ordersBefore, Order::query()->count());

        $belowVaApi = $this->apiCustomerWithCart($belowVa);
        $this->apiSaveShippingAddress($belowVaApi)->assertOk();
        $this->apiPickShippingOption($belowVaApi, 'jne:REG')->assertOk();
        $this->apiPlaceOrder($belowVaApi, $this->fixtures->paymentMethods['bca']->id)->assertStatus(422);
        $this->assertSame($ordersBefore, Order::query()->count());

        // Exact lower VA boundary (Rp 10,000) accepted on both API and Web
        $exactVa = $this->fixtures->createStockedProduct(10000, 10, 500);
        $exactVaApi = $this->apiCustomerWithCart($exactVa);
        $this->apiSaveShippingAddress($exactVaApi)->assertOk();
        $this->apiPickShippingOption($exactVaApi, 'jne:REG')->assertOk();
        $this->apiPlaceOrder($exactVaApi, $this->fixtures->paymentMethods['bca']->id)
            ->assertCreated()
            ->assertJsonPath('data.amount', 10000)
            ->assertJsonPath('data.payment_action.kind', 'instructions');

        ['user' => $exactVaWeb] = $this->webCustomerWithCart($exactVa);
        $this->webSaveShippingAddress($exactVaWeb)->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($exactVaWeb, 'jne:REG')->assertRedirect(route('shop.checkout.index'));
        $webVaRes = $this->webPlaceOrder($exactVaWeb, $this->fixtures->paymentMethods['bca']->id);
        $webVaOrder = Order::query()->where('customer_id', $exactVaWeb->id)->latest('id')->firstOrFail();
        $webVaRes->assertRedirect(route('shop.checkout.success', ['order' => $webVaOrder->id]));
    }

    public function test_cod_rejected_pre_order_when_selected_shipping_rate_has_is_cod_false(): void
    {
        $this->applyTier('komship');
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        // SPX tariff has is_cod => false in our Komship preset
        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, 'spx:SPXSTD')->assertOk();

        $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['cod']->id)
            ->assertStatus(422);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_qrisly_401_falls_back_to_payment_api_qris_when_both_configured(): void
    {
        $this->applyTier('payment_and_qrisly')
            ->withQrislyEntitled(false, 401, 'Invalid API Key')
            ->apply();

        $this->fixtures->manualOption->update(['price' => 0]);
        $product = $this->fixtures->createStockedProduct(25000, 10, 500);

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($apiUser, $this->fixtures->manualOption->id)->assertOk();

        $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['qris']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'qr')
            ->assertJsonPath('data.payment_action.data.provider', 'payment_api')
            ->assertJsonPath('data.payment_action.data.payment_id', 'KOMPAY-QRIS-1');

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/qrisly/generate-qris')
            && $req->hasHeader('X-API-Key', 'qrisly-key')
            && (int) ($req->data()['amount'] ?? 0) === 25000);
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/user/payment/create')
            && $req->hasHeader('x-api-key', 'payment-key')
            && ($req->data()['payment_type'] ?? null) === 'qris'
            && (int) ($req->data()['amount'] ?? 0) === 25000);
    }

    public function test_post_order_payment_gateway_500_leaves_order_recoverable_on_web_and_api(): void
    {
        $this->applyTier('starter')
            ->withPaymentCreate([], 500)
            ->apply();

        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        // API returns 201 with order + payment_error + can_retry_payment: true
        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, 'jne:REG')->assertOk();

        $placed = $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['bca']->id)
            ->assertCreated()
            ->assertJsonPath('data.can_retry_payment', true);

        $this->assertNotNull($placed->json('data.payment_error'));

        // Web redirects to order detail with flash error (never 500)
        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));

        $webOrder = $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['bca']->id);
        $createdOrder = Order::query()->where('customer_id', $webUser->id)->latest('id')->firstOrFail();
        $webOrder->assertRedirect(route('account.orders.show', $createdOrder))
            ->assertSessionHas('error');
    }

    public function test_modifying_cart_after_shipping_quote_refuses_place_order_with_422(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        ['user' => $webUser, 'cart' => $cart] = $this->webCustomerWithCart($product, 1);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webLoadCheckout($webUser, 2)->assertOk();
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));

        // Mutate cart behind checkout session's back without re-quoting
        CartLine::query()->where('cart_id', $cart->id)->update(['quantity' => 2]);

        $ordersBefore = Order::query()->count();

        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['bca']->id)
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame($ordersBefore, Order::query()->count());
    }

    public function test_http_200_500_envelope_does_not_poison_payment_catalog_cache_and_recovers(): void
    {
        $this->applyTier('starter', applyHttpFake: false);
        Http::fake([
            KomerceHttpFakeBuilder::PAYMENT_BASE.'/api/v1/user/methods' => Http::sequence()
                ->push([
                    'success' => false,
                    'meta' => ['code' => 500, 'status' => 'error', 'message' => 'Internal server error'],
                    'data' => [],
                ], 200)
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success'],
                    'data' => [
                        ['payment_type' => 'va', 'bank_code' => 'BCA', 'display_name' => 'BCA Virtual Account', 'min_amount' => 10000, 'max_amount' => 100000000],
                    ],
                ], 200),
        ]);

        $product = $this->fixtures->createStockedProduct(50000, 10, 500);
        $apiUser = $this->apiCustomerWithCart($product);

        // Local VA remains offered while catalog is transiently unavailable (never poisoned with [])
        $slugs = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
            ->pluck('slug')
            ->all();
        $this->assertContains('komerce-va-bca', $slugs);
        $this->assertFalse(Cache::has('komerce:payment-methods'));

        // After 45s circuit breaker expires, next request recovers official catalog
        $this->travel(46)->seconds();
        $recoveredSlugs = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
            ->pluck('slug')
            ->all();
        $this->assertContains('komerce-va-bca', $recoveredSlugs);
        $this->assertTrue(Cache::has('komerce:payment-methods'));
    }

    public function test_http_200_401_envelope_disables_payment_api_without_poisoning_catalog_cache(): void
    {
        $this->applyTier('starter', applyHttpFake: false);
        Http::fake([
            KomerceHttpFakeBuilder::PAYMENT_BASE.'/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 401, 'status' => 'failed', 'message' => 'Invalid API Key'],
                'data' => [],
            ], 200),
        ]);

        $product = $this->fixtures->createStockedProduct(50000, 10, 500);
        $apiUser = $this->apiCustomerWithCart($product);

        $revokedDrivers = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'))
            ->pluck('driver')
            ->all();
        $this->assertNotContains('komerce', $revokedDrivers);
        $this->assertFalse(Cache::has('komerce:payment-methods'));
    }

    public function test_http_200_401_on_qrisly_generate_falls_back_to_payment_api_qris(): void
    {
        $this->applyTier('payment_and_qrisly', applyHttpFake: false);
        Http::fake([
            KomerceHttpFakeBuilder::PAYMENT_BASE.'/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['payment_type' => 'qris', 'bank_code' => '', 'display_name' => 'QRIS', 'min_amount' => 10000, 'max_amount' => 10000000],
                ],
            ], 200),
            KomerceHttpFakeBuilder::QRISLY_BASE.'/api/v1/qrisly/generate-qris' => Http::response([
                'meta' => ['code' => 401, 'status' => 'failed', 'message' => 'Invalid API Key'],
            ], 200),
            KomerceHttpFakeBuilder::PAYMENT_BASE.'/api/v1/user/payment/create' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'payment_id' => 'KOMPAY-QRIS-200-FALLBACK',
                    'payment_type' => 'qris',
                    'qr_string' => '000201010212FALLBACK',
                    'amount' => 50000,
                ],
            ], 200),
        ]);

        $this->fixtures->manualOption->update(['price' => 0]);
        $product = $this->fixtures->createStockedProduct(50000, 10, 500);
        $fallbackUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($fallbackUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($fallbackUser, $this->fixtures->manualOption->id)->assertOk();
        $this->apiPlaceOrder($fallbackUser, $this->fixtures->paymentMethods['qris']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.data.provider', 'payment_api')
            ->assertJsonPath('data.payment_action.data.payment_id', 'KOMPAY-QRIS-200-FALLBACK');
    }
}
