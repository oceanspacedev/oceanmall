<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

final class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_and_fetch_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Sari',
            'last_name' => 'Wulandari',
            'email' => 'sari@oceanmall.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'sari@oceanmall.test')
            ->assertJsonStructure(['token', 'token_type', 'data' => ['id', 'email']]);

        $token = $response->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'sari@oceanmall.test');
    }

    public function test_catalog_is_public(): void
    {
        Product::factory()->standard()->create([
            'name' => 'Realme Buds API',
            'slug' => 'realme-buds-api',
        ]);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->getJson('/api/v1/catalog/home')->assertOk();
        $this->getJson('/api/v1/catalog/categories')->assertOk();
        $this->getJson('/api/v1/catalog/brands')->assertOk();
        $this->getJson('/api/v1/catalog/collections')->assertOk();
        $this->getJson('/api/v1/catalog/featured')->assertOk();
        $this->getJson('/api/v1/catalog/search?q=Realme')->assertOk();
        $this->getJson('/api/v1/catalog/products/realme-buds-api')
            ->assertOk()
            ->assertJsonStructure(['data' => ['slug', 'reviews', 'related_products']]);
    }

    public function test_product_includes_related_products(): void
    {
        $product = Product::factory()->standard()->create([
            'name' => 'Related Parent',
            'slug' => 'related-parent',
            'published_at' => now()->subDay(),
        ]);
        $related = Product::factory()->standard()->create([
            'name' => 'Related Child',
            'slug' => 'related-child',
            'published_at' => now()->subDay(),
        ]);
        $product->relatedProducts()->sync([$related->id]);

        $this->getJson('/api/v1/catalog/products/related-parent')
            ->assertOk()
            ->assertJsonPath('data.related_products.0.slug', 'related-child');
    }

    public function test_catalog_products_filter_featured_and_promo(): void
    {
        $this->stockedProduct([
            'name' => 'Plain Item',
            'slug' => 'plain-item',
            'featured' => false,
        ]);
        $this->stockedProduct([
            'name' => 'Featured Item',
            'slug' => 'featured-item',
            'featured' => true,
        ]);
        $promo = $this->stockedProduct([
            'name' => 'Promo Item',
            'slug' => 'promo-item',
            'featured' => false,
        ]);
        $promo->prices()->update(['compare_amount' => 500000]);

        $featured = collect($this->getJson('/api/v1/catalog/products?featured=1')->json('data'))->pluck('slug');
        $this->assertTrue($featured->contains('featured-item'));
        $this->assertFalse($featured->contains('plain-item'));

        $onPromo = collect($this->getJson('/api/v1/catalog/products?promo=1')->json('data'))->pluck('slug');
        $this->assertTrue($onPromo->contains('promo-item'));
        $this->assertFalse($onPromo->contains('plain-item'));
    }

    public function test_customer_can_manage_address_book_and_profile(): void
    {
        $user = User::factory()->create();
        $country = Country::factory()->create(['cca2' => 'ID']);
        // Addresses only accept countries the store ships to.
        Zone::factory()->create(['is_enabled' => true])->countries()->attach($country->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/addresses', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai 1',
            'postal_code' => '12220',
            'city' => 'Jakarta Selatan',
            'phone_number' => '081234567890',
            'country_id' => $country->id,
            'type' => 'shipping',
            'rajaongkir_destination_id' => '17547',
        ])->assertCreated()->assertJsonPath('data.city', 'Jakarta Selatan');

        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonPath('data.0.postal_code', '12220');

        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonStructure(['data', 'meta']);

        $this->patchJson('/api/v1/auth/profile', [
            'first_name' => 'Budi',
            'last_name' => 'Updated',
            'email' => $user->email,
        ])->assertOk()->assertJsonPath('data.last_name', 'Updated');
    }

    public function test_checkout_requires_auth_but_cart_is_public(): void
    {
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.lines', []);

        $this->postJson('/api/v1/checkout/place-order', [
            'payment_method_id' => 1,
        ])->assertUnauthorized();
    }

    public function test_guest_can_add_to_cart_with_device_token(): void
    {
        $product = $this->stockedProduct();
        $token = (string) Str::uuid();

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.purchasable_id', $product->id)
            ->assertJsonPath('data.lines.0.slug', $product->slug)
            ->assertJsonPath('data.totals.total', 399000);

        $this->withHeader('X-Cart-Token', $token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', 1);
    }

    public function test_login_merges_guest_cart(): void
    {
        $product = $this->stockedProduct();
        $token = (string) Str::uuid();
        $user = User::factory()->create();

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $login = $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.lines.0.purchasable_id', $product->id)
            ->assertJsonPath('data.lines.0.quantity', 2);
    }

    public function test_authenticated_customer_can_add_to_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.purchasable_id', $product->id)
            ->assertJsonPath('data.lines.0.slug', $product->slug)
            ->assertJsonPath('data.totals.total', 399000);
    }

    public function test_variant_product_requires_variant_id(): void
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => 'Hitam',
            'sku' => 'API-BLK',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih varian produk sebelum menambah ke keranjang.');

        $this->assertNotNull($variant->id);
    }

    public function test_cart_variant_line_uses_product_name(): void
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct([
            'name' => 'Realme 13 Pro 5G',
            'slug' => 'realme-13-pro-5g',
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => 'Hitam / 256 GB',
            'sku' => 'API-BLK-256',
        ]);
        $inventory = Inventory::query()->where('is_default', true)->firstOrFail();
        $variant->mutateStock($inventory->id, 10);
        $currency = Currency::query()->where('code', shopper_currency())->firstOrFail();
        Price::query()->create([
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
            'amount' => 399000,
            'currency_id' => $currency->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.name', 'Realme 13 Pro 5G')
            ->assertJsonPath('data.lines.0.variant', 'Hitam / 256 GB')
            ->assertJsonPath('data.lines.0.slug', 'realme-13-pro-5g');
    }

    public function test_place_order_creates_komerce_payment_instructions(): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');

        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $paymentMethod = PaymentMethod::factory()->create([
            'title' => 'BCA Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode([
                'channel_code' => 'BCA',
                'payment_type' => 'bank_transfer',
            ]),
        ]);
        $zone->paymentMethods()->attach($paymentMethod->id);

        Http::fake([
            'https://payment.example.test/user/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [[
                    'payment_type' => 'va',
                    'bank_code' => 'BCA',
                    'min_amount' => 10000,
                    'max_amount' => 999999999,
                ]],
            ]),
            'https://shipping.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [[
                    'name' => 'J&T Express',
                    'code' => 'jnt',
                    'service' => 'EZ',
                    'cost' => 11000,
                    'etd' => '1-2',
                ]],
            ]),
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'payment_id' => 'KPAY-API-1',
                    'va_number' => '1234567890',
                    'bank_code' => 'BCA',
                    'amount' => 50000,
                    'status' => 'PENDING',
                    'payment_url' => 'https://pay-sandbox.komerce.id/token',
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $product = $this->stockedProduct([
            'weight_value' => 0.05,
            'weight_unit' => 'kg',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai 1',
            'postal_code' => '12220',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'rajaongkir_destination_id' => '17547',
            'rajaongkir_pin_point' => '-6.2380,106.7830',
        ])->assertOk();

        $rates = $this->getJson('/api/v1/checkout')->json('data.shipping_rates');
        $serviceCode = is_array($rates) && $rates !== [] ? $rates[0]['service_code'] : 'jnt:EZ';

        $this->postJson('/api/v1/checkout/shipping-option', [
            'service_code' => $serviceCode,
        ])->assertOk();

        $this->postJson('/api/v1/checkout/place-order', [
            'payment_method_id' => $paymentMethod->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment.payment_id', 'KPAY-API-1')
            ->assertJsonPath('data.payment.virtual_account_number', '1234567890');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function stockedProduct(array $overrides = []): Product
    {
        $inventory = Inventory::factory()->create([
            'is_default' => true,
            'rajaongkir_origin_id' => '17248',
            'latitude' => '-6.7366',
            'longitude' => '108.5414',
        ]);
        $product = Product::factory()->standard()->create(array_merge([
            'published_at' => now()->subDay(),
            'name' => 'API Test Product',
        ], $overrides));
        $product->mutateStock($inventory->id, 10);

        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create([
                'code' => shopper_currency(),
                'name' => shopper_currency(),
                'symbol' => shopper_currency(),
                'format' => '1,234.56',
            ]);
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 399000,
            'currency_id' => $currency->id,
        ]);

        return $product;
    }
}
