<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\CheckoutSession;
use App\Models\Product;
use App\Models\User;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingQuoteContext;
use App\Shipping\ShippingQuoteFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

final class WebCheckoutParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manifest = public_path('build/manifest.json');
        $this->withHeader(
            'X-Inertia-Version',
            file_exists($manifest) ? hash_file('xxh128', $manifest) : '',
        );
    }

    public function test_web_destination_search_returns_429_with_wib_copy_on_quota_and_502_on_outage(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'test-komerce-key');
        config()->set('komerce.enabled', true);
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');

        $user = User::factory()->create();

        Http::fake([
            'https://shipping.example.test/api/v1/destination/domestic-destination*' => Http::sequence()
                ->push(['meta' => ['code' => 500, 'message' => 'Internal server error']], 500)
                ->push(['meta' => ['code' => 429, 'message' => 'Daily limit exceeded']], 429),
        ]);

        $this->actingAs($user)
            ->getJson(route('shop.checkout.destinations', ['q' => 'surabaya']))
            ->assertStatus(502)
            ->assertJsonPath('data', []);

        $quotaResponse = $this->actingAs($user)
            ->getJson(route('shop.checkout.destinations', ['q' => 'bandung']))
            ->assertStatus(429)
            ->assertJsonPath('data', []);

        $this->assertMatchesRegularExpression('/kuota|batas/i', (string) $quotaResponse->json('message'));
        $this->assertStringContainsString('WIB', (string) $quotaResponse->json('message'));
        $this->assertNotEmpty($quotaResponse->json('retry_at_wib'));
    }

    public function test_web_checkout_surfaces_hint_when_any_multi_warehouse_package_has_no_rates_and_resets_stale_failure(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'test-komerce-key');
        config()->set('komerce.enabled', true);
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        $carrier = Carrier::query()->create([
            'slug' => 'jne',
            'name' => 'JNE Express',
            'driver' => 'rajaongkir',
            'is_enabled' => true,
        ]);
        $zone->carriers()->attach($carrier->id);

        $invA = Inventory::factory()->create([
            'name' => 'Gudang Jakarta',
            'is_default' => true,
            'country_id' => $country->id,
            'rajaongkir_origin_id' => '1001',
        ]);
        $invB = Inventory::factory()->create([
            'name' => 'Gudang Surabaya',
            'is_default' => false,
            'country_id' => $country->id,
            'rajaongkir_origin_id' => '2002',
        ]);

        $productA = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'weight_value' => 1.0, 'weight_unit' => 'kg']);
        $productB = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'weight_value' => 1.0, 'weight_unit' => 'kg']);
        $productA->mutateStock($invA->id, 5);
        $productB->mutateStock($invB->id, 5);

        foreach ([$productA, $productB] as $product) {
            Price::query()->create([
                'priceable_type' => $product->getMorphClass(),
                'priceable_id' => $product->id,
                'amount' => 100000,
                'currency_id' => $currency->id,
            ]);
        }

        $user = User::factory()->create();
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $productA->getMorphClass(),
            'purchasable_id' => $productA->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $productB->getMorphClass(),
            'purchasable_id' => $productB->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);

        // Seed a stale quota failure from an earlier request to confirm index() clears it.
        resolve(ShippingQuoteContext::class)->markFailure('rajaongkir', ShippingQuoteFailure::QUOTA, 'Stale quota');

        Http::fake([
            'https://shipping.example.test/api/v1/calculate/domestic-cost' => Http::sequence()
                ->push([
                    'meta' => ['code' => 200, 'message' => 'Success'],
                    'data' => [
                        [
                            'name' => 'JNE',
                            'code' => 'jne',
                            'service' => 'REG',
                            'description' => 'Reguler',
                            'cost' => 15000,
                            'etd' => '2-3',
                        ],
                    ],
                ])
                ->push([
                    'meta' => ['code' => 500, 'message' => 'Gateway down'],
                ], 500),
        ]);

        $this->actingAs($user)
            ->withSession([
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'zone_country_code' => 'ID',
                CheckoutSession::SHIPPING_ADDRESS => [
                    'first_name' => 'Budi',
                    'last_name' => 'Santoso',
                    'street_address' => 'Jl. Melawai 1',
                    'city' => 'Jakarta Selatan',
                    'postal_code' => '12190',
                    'state' => 'DKI Jakarta',
                    'country_id' => $country->id,
                    AddressDestination::ID => '152',
                    AddressDestination::LABEL => 'Senayan, Kebayoran Baru',
                    AddressDestination::DRIVER => 'rajaongkir',
                ],
            ])
            ->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shop/checkout')
                ->where('shippingRatesHint', fn (?string $hint): bool => is_string($hint) && str_contains($hint, 'gangguan koneksi'))
            );
    }

    public function test_web_checkout_vue_sources_include_payment_limits_and_cod_rate_indicators(): void
    {
        $checkoutVue = file_get_contents(resource_path('js/pages/shop/checkout.vue'));
        $pickerVue = file_get_contents(resource_path('js/components/shop/shipment-rate-picker.vue'));
        $typesTs = file_get_contents(resource_path('js/types/shop.ts'));

        $this->assertIsString($checkoutVue);
        $this->assertStringContainsString('paymentLimitNote', $checkoutVue);
        $this->assertStringContainsString('paymentDisabledReason', $checkoutVue);
        $this->assertStringContainsString('Bisa COD', $checkoutVue);
        $this->assertStringContainsString('Tidak bisa COD', $checkoutVue);
        $this->assertStringContainsString('payload.message', $checkoutVue);

        $this->assertIsString($pickerVue);
        $this->assertStringContainsString('Bisa COD', $pickerVue);
        $this->assertStringContainsString('Tidak bisa COD', $pickerVue);

        $this->assertIsString($typesTs);
        $this->assertStringContainsString('is_cod?: boolean | null', $typesTs);
    }
}
