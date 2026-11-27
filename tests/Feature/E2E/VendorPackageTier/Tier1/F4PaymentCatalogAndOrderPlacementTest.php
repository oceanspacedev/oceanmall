<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier1;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F4PaymentCatalogAndOrderPlacementTest extends VendorPackageTierTestCase
{
    public function test_footer_and_checkout_mirror_official_komerce_catalog_channels(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        // Footer shared props mirror official catalog (BCA + QRIS + COD + manual; omits BRI)
        $this->webLoadHome($webUser)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $footerMethods = collect($page->toArray()['props']['shop']['payment_methods']);
                $codes = $footerMethods->pluck('key')->all();
                $this->assertContains('komerce-va-bca', $codes);
                $this->assertContains('komerce-qris', $codes);
                $this->assertContains('cod', $codes);
                $this->assertNotContains('komerce-va-bri', $codes);
                $this->assertSame('Bank Central Asia', $footerMethods->firstWhere('key', 'komerce-va-bca')['title'] ?? null);
            });

        // Checkout Web paymentOptions & API payment_methods mirror official catalog + bounds
        $this->webLoadCheckout($webUser, 3)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $options = collect($page->toArray()['props']['paymentOptions']);
                $bca = $options->firstWhere('slug', 'komerce-va-bca');
                $this->assertNotNull($bca);
                $this->assertSame('https://example.test/bca.png', $bca['logo']);
                $this->assertSame(10000, $bca['min_amount']);
                $this->assertNull($options->firstWhere('slug', 'komerce-va-bri'));
            });

        $apiUser = $this->apiCustomerWithCart($product);
        $methods = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'));
        $this->assertNotNull($methods->firstWhere('slug', 'komerce-va-bca'));
        $this->assertNull($methods->firstWhere('slug', 'komerce-va-bri'));
    }

    public function test_qrisly_only_tier_exposes_qris_with_1k_to_100m_bounds_and_places_5000_idr_order(): void
    {
        $this->applyTier('qrisly_only');
        $product = $this->fixtures->createStockedProduct(5000, 10, 500);
        // Set manual shipping option free so order total is Rp 5,000
        $this->fixtures->manualOption->update(['price' => 0]);

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($apiUser, $this->fixtures->manualOption->id)->assertOk();

        $methods = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'));
        $qris = $methods->firstWhere('slug', 'komerce-qris');
        $this->assertNotNull($qris);
        $this->assertNull($methods->firstWhere('slug', 'komerce-va-bca'));
        $this->assertSame(1000, $qris['min_amount']);
        $this->assertSame(100000000, $qris['max_amount']);

        $placed = $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['qris']->id)
            ->assertCreated()
            ->assertJsonPath('data.amount', 5000)
            ->assertJsonPath('data.payment_action.kind', 'qr')
            ->assertJsonPath('data.payment_action.data.payment_id', '9001');

        $order = Order::query()->where('number', $placed->json('data.number'))->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/qrisly/generate-qris')
            && $req->hasHeader('X-API-Key', 'qrisly-key')
            && (int) ($req->data()['amount'] ?? 0) === 5000
            && (string) ($req->data()['qris_id'] ?? '') === '18');
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/user/payment/create'));
    }

    public function test_payment_and_qrisly_tier_allows_5000_idr_qris_via_qrisly_while_va_requires_10000(): void
    {
        $this->applyTier('payment_and_qrisly')
            ->withPaymentMethods([
                [
                    'payment_type' => 'va',
                    'display_name' => 'Bank Central Asia',
                    'bank_code' => 'BCA',
                    'logo_url' => 'https://example.test/bca.png',
                    'min_amount' => 10000,
                    'max_amount' => 999999999,
                ],
                [
                    'payment_type' => 'qris',
                    'display_name' => 'QRIS',
                    'bank_code' => '',
                    'logo_url' => 'https://example.test/qris.png',
                    'min_amount' => 10000,
                    'max_amount' => 10000000,
                ],
            ])
            ->apply();

        $this->fixtures->manualOption->update(['price' => 0]);
        $cheapProduct = $this->fixtures->createStockedProduct(5000, 10, 500);

        $apiUser = $this->apiCustomerWithCart($cheapProduct);
        $this->apiSaveShippingAddress($apiUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($apiUser, $this->fixtures->manualOption->id)->assertOk();

        $methods = collect($this->apiLoadCheckout($apiUser)->assertOk()->json('data.payment_methods'));
        $qris = $methods->firstWhere('slug', 'komerce-qris');
        $bca = $methods->firstWhere('slug', 'komerce-va-bca');

        $this->assertNotNull($qris);
        $this->assertSame(1000, $qris['min_amount']);
        $this->assertSame(100000000, $qris['max_amount']);
        $this->assertSame(10000, $bca['min_amount']);

        // Rp 5,000 QRIS order succeeds via QRISLY
        $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['qris']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'qr')
            ->assertJsonPath('data.payment_action.data.provider', 'qrisly');

        // Rp 15,000 BCA VA order succeeds via Payment API
        $vaProduct = $this->fixtures->createStockedProduct(15000, 10, 500);
        $vaUser = $this->apiCustomerWithCart($vaProduct);
        $this->apiSaveShippingAddress($vaUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($vaUser, $this->fixtures->manualOption->id)->assertOk();

        $this->apiPlaceOrder($vaUser, $this->fixtures->paymentMethods['bca']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'instructions')
            ->assertJsonPath('data.payment_action.data.virtual_account_number', '8808001234567890');
    }

    public function test_web_and_api_place_order_create_va_payment_and_return_instructions(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        // Web order placement
        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));

        $webResponse = $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['bca']->id);
        $webOrder = Order::query()->where('customer_id', $webUser->id)->latest('id')->firstOrFail();
        $webResponse->assertRedirect(route('shop.checkout.success', ['order' => $webOrder->id]));

        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $webOrder->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-VA-1',
            'status' => TransactionStatus::Pending->value,
        ]);

        // API order placement
        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, 'jne:REG')->assertOk();

        $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['bca']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_status', PaymentStatus::Pending->value)
            ->assertJsonPath('data.payment_action.kind', 'instructions')
            ->assertJsonPath('data.payment_action.data.virtual_account_number', '8808001234567890');
    }

    public function test_cod_order_with_cod_supporting_courier_skips_online_payment_and_exposes_cod_flags(): void
    {
        $this->applyTier('komship');
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, 'jne:JNEFlat')->assertOk();

        $placed = $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['cod']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'none');

        $orderNumber = (string) $placed->json('data.number');

        $this->apiShowOrder($apiUser, $orderNumber)
            ->assertOk()
            ->assertJsonPath('data.is_cod', true)
            ->assertJsonPath('data.can_retry_payment', false);

        $order = Order::query()->where('number', $orderNumber)->firstOrFail();
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/user/payment/create'));
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/qrisly/generate-qris'));
    }
}
