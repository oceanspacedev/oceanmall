<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

/**
 * Regressions found in mobile QA (2026-10-13).
 */
final class CustomerApiRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_quote_keeps_the_customer_cart_and_sends_its_item_value(): void
    {
        $this->fakeDelivery();
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertOk();

        $response = $this->postJson('/api/v1/checkout/shipping-address', $this->addressPayload())
            ->assertOk()
            ->assertJsonPath('data.cart.lines.0.quantity', 2);

        // Quoting must not mint a new (empty) cart that hides the real one.
        $this->assertSame(1, Cart::query()->where('customer_id', $user->id)->whereNull('completed_at')->count());
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.lines.0.quantity', 2);

        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/tariff/api/v1/calculate')
            && (int) $request['item_value'] === 2 * 399000);

        $rates = $response->json('data.shipping_rates');
        $this->assertSame(['lion:REGPACK', 'jne:REG'], array_column($rates, 'service_code'), 'cheapest first');

        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => 'jne:REG'])
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'jne:REG');
    }

    public function test_customer_cart_prefers_open_cart_with_lines_over_newer_empty_one(): void
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3])->assertOk();
        Cart::query()->create(['customer_id' => $user->id, 'currency_code' => shopper_currency()]);

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.lines.0.quantity', 3);
    }

    public function test_cart_line_is_capped_and_buy_now_replaces_quantity(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = $this->stockedProduct();

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 6])->assertOk();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 6])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Maksimal 10 per produk dalam keranjang.');

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2, 'replace' => true])
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', 2);
    }

    public function test_guest_lines_are_clamped_to_stock_instead_of_dropped_on_login(): void
    {
        $product = $this->stockedProduct(); // 10 in stock
        $user = User::factory()->create();
        $token = '6f2b3c4d-1111-4222-8333-944455566677';

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 8])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 5])
            ->assertOk();

        $login = $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/cart')
            ->assertJsonPath('data.lines.0.quantity', 10);
    }

    public function test_variant_from_another_product_is_a_validation_error(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = $this->stockedProduct();
        $other = $this->stockedProduct(['name' => 'Other', 'slug' => 'other']);
        $variant = ProductVariant::query()->create(['product_id' => $other->id, 'name' => 'Biru', 'sku' => 'OTHER-BLU']);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'variant_id' => $variant->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Varian tidak ditemukan untuk produk ini.');
    }

    public function test_non_numeric_route_ids_are_not_found_instead_of_server_errors(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/cart/items/abc', ['quantity' => 1])->assertNotFound();
        $this->patchJson('/api/v1/addresses/abc', [])->assertNotFound();
        $this->postJson('/api/v1/orders/ORD-1/shipments/abc/track')->assertNotFound();
    }

    public function test_emails_are_case_insensitive(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration('Sari@Example.COM'))
            ->assertCreated()
            ->assertJsonPath('data.email', 'sari@example.com');

        $this->postJson('/api/v1/auth/register', $this->registration('SARI@example.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', ['email' => 'sAri@EXAMPLE.com', 'password' => 'Rahasia123!'])
            ->assertOk();
    }

    public function test_address_country_must_be_a_shipping_zone_country(): void
    {
        $indonesia = Country::factory()->create(['cca2' => 'ID']);
        $haiti = Country::factory()->create(['cca2' => 'HT']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($indonesia->id);
        Sanctum::actingAs(User::factory()->create());

        $payload = [
            'first_name' => 'Budi', 'last_name' => 'Santoso', 'street_address' => 'Jl. Melawai 1',
            'postal_code' => '12220', 'city' => 'Jakarta Selatan', 'type' => 'shipping',
        ];

        $this->postJson('/api/v1/addresses', [...$payload, 'country_id' => $haiti->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('country_id');

        $id = $this->postJson('/api/v1/addresses', [...$payload, 'country_id' => $indonesia->id, 'rajaongkir_destination_id' => '17547'])
            ->assertCreated()
            ->json('data.id');

        // A partial edit keeps the stored RajaOngkir destination.
        $this->patchJson("/api/v1/addresses/{$id}", [...$payload, 'country_id' => $indonesia->id, 'street_address' => 'Jl. Melawai 2'])
            ->assertOk()
            ->assertJsonPath('data.rajaongkir_destination_id', '17547');
    }

    public function test_search_bursts_do_not_throttle_login_or_password_reset(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/v1/catalog/search?q=ab'.$i)->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertStatus(422);
    }

    public function test_product_detail_exposes_stock_for_quantity_limits(): void
    {
        $product = $this->stockedProduct(['slug' => 'stocked']);
        $phone = $this->stockedProduct(['name' => 'Phone', 'slug' => 'phone']);
        $variant = ProductVariant::query()->create(['product_id' => $phone->id, 'name' => 'Hitam', 'sku' => 'PH-STOCK']);
        $variant->mutateStock(Inventory::query()->where('is_default', true)->value('id'), 4);

        $this->getJson('/api/v1/catalog/products/stocked')->assertOk()->assertJsonPath('data.stock', 10);
        $this->getJson('/api/v1/catalog/products/phone')
            ->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.variants.0.stock', 4);
        $this->assertNotNull($product->id);
    }

    public function test_short_search_returns_empty_page(): void
    {
        $this->getJson('/api/v1/catalog/search?q=a')->assertOk()->assertJsonPath('data', []);
    }

    public function test_password_reset_link_ignores_the_request_host(): void
    {
        Notification::fake();
        config()->set('app.url', 'https://shop.example.test');
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->withHeader('Host', 'attacker.example')
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'RESET@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://shop.example.test/reset-password/') && ! str_contains($url, 'attacker');
        });
    }

    public function test_promo_and_price_sort_include_variant_priced_products(): void
    {
        $plain = $this->stockedProduct(['name' => 'Plain', 'slug' => 'plain']); // 399.000, no discount
        $phone = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'name' => 'Phone', 'slug' => 'phone']);
        $variant = ProductVariant::query()->create(['product_id' => $phone->id, 'name' => 'Hitam', 'sku' => 'PH-BLK']);
        Price::query()->create([
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
            'amount' => 4999000,
            'compare_amount' => 5499000,
            'currency_id' => $this->currency()->id,
        ]);

        $this->getJson('/api/v1/catalog/products?promo=1')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'phone')
            ->assertJsonCount(1, 'data');

        $this->assertSame(['plain', 'phone'], array_column($this->getJson('/api/v1/catalog/products?sort=price_asc')->json('data'), 'slug'));
        $this->assertSame(['phone', 'plain'], array_column($this->getJson('/api/v1/catalog/products?sort=price_desc')->json('data'), 'slug'));
        $this->assertNotNull($plain->id);
    }

    public function test_payment_method_over_its_maximum_is_rejected_before_the_order_exists(): void
    {
        $this->fakeDelivery(maxAmount: 100000);
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->addressPayload())->assertOk();
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => 'lion:REGPACK'])->assertOk();

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->paymentMethodId])
            ->assertStatus(422)
            ->assertJsonPath('message', 'QRIS hanya untuk total maksimal Rp100.000. Pilih metode lain.');

        $this->getJson('/api/v1/cart')->assertJsonPath('data.lines.0.quantity', 1);
    }

    public function test_order_is_returned_when_only_payment_creation_fails(): void
    {
        $this->fakeDelivery(paymentFails: true);
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->addressPayload())->assertOk();
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => 'lion:REGPACK'])->assertOk();

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->paymentMethodId])
            ->assertCreated()
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.can_retry_payment', true)
            ->assertJsonStructure(['data' => ['number', 'payment_error']]);
    }

    private int $paymentMethodId = 0;

    private function fakeDelivery(int $maxAmount = 999999999, bool $paymentFails = false): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');

        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);
        $method = PaymentMethod::factory()->create([
            'title' => 'QRIS',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);
        $zone->paymentMethods()->attach($method->id);
        $this->paymentMethodId = $method->id;

        Http::fake([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response([
                'meta' => ['code' => 200],
                'data' => ['calculate_reguler' => [
                    ['shipping_name' => 'JNE', 'service_name' => 'REG', 'shipping_cost' => 12000, 'etd' => '1-2 day'],
                    ['shipping_name' => 'LION', 'service_name' => 'REGPACK', 'shipping_cost' => 9000, 'etd' => '2-3 day'],
                ]],
            ]),
            'https://payment.example.test/user/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [['payment_type' => 'va', 'bank_code' => 'BCA', 'min_amount' => 10000, 'max_amount' => $maxAmount]],
            ]),
            'https://payment.example.test/user/api/v1/user/payment/create' => $paymentFails
                ? Http::response(['meta' => ['code' => 500, 'message' => 'Gateway unavailable']], 500)
                : Http::response(['meta' => ['code' => 200], 'data' => ['payment_id' => 'KPAY-1', 'va_number' => '1', 'status' => 'PENDING']]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function addressPayload(): array
    {
        return [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai 1',
            'postal_code' => '12220',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'rajaongkir_destination_id' => '17547',
            'rajaongkir_pin_point' => '-6.2380,106.7830',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function registration(string $email): array
    {
        return [
            'first_name' => 'Sari',
            'last_name' => 'Wulandari',
            'email' => $email,
            'password' => 'Rahasia123!',
            'password_confirmation' => 'Rahasia123!',
        ];
    }

    private function currency(): Currency
    {
        return Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create([
                'code' => shopper_currency(),
                'name' => shopper_currency(),
                'symbol' => shopper_currency(),
                'format' => '1,234.56',
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function stockedProduct(array $overrides = []): Product
    {
        $inventory = Inventory::query()->where('is_default', true)->first() ?? Inventory::factory()->create([
            'is_default' => true,
            'rajaongkir_origin_id' => '17248',
            'latitude' => '-6.7366',
            'longitude' => '108.5414',
        ]);
        $product = Product::factory()->standard()->create(array_merge([
            'published_at' => now()->subDay(),
            'name' => 'API Test Product',
            'weight_value' => 0.2,
            'weight_unit' => 'kg',
        ], $overrides));
        $product->mutateStock($inventory->id, 10);

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 399000,
            'currency_id' => $this->currency()->id,
        ]);

        return $product;
    }
}
