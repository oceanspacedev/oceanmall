<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingQuoteContext;
use App\Shipping\ShippingQuoteFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

final class CustomerParityM4ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_checkout_show_exposes_shipping_rates_hint_and_failure_states(): void
    {
        $this->requireModules('Komerce');

        $currencyCode = shopper_currency();
        $currency = Currency::query()->where('code', $currencyCode)->first()
            ?? Currency::factory()->create(['code' => $currencyCode, 'name' => $currencyCode, 'symbol' => 'Rp', 'format' => 'Rp1.234']);
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

        $inventory = Inventory::factory()->create([
            'is_default' => true,
            'country_id' => $country->id,
            'rajaongkir_origin_id' => '1001',
        ]);
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'weight_value' => 1.0, 'weight_unit' => 'kg']);
        $product->mutateStock($inventory->id, 10);
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 150000,
            'currency_id' => $currency->id,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

        // 1. no_allocation: drain stock after adding to cart
        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', true);
        config()->set('shopper.shipping.provider', null);

        $product->mutateStock($inventory->id, -10);

        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Sudirman 1',
            'postal_code' => '12190',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'destination_id' => '152',
            'destination_label' => 'Senayan, Kebayoran Baru',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_rates_failure.reason', 'no_allocation')
            ->assertJsonPath('data.shipping_rates_failure.retry_at_wib', null);

        $this->assertStringContainsString('gudang toko', (string) $this->getJson('/api/v1/checkout')->json('data.shipping_rates_hint'));

        // Restore stock and test quota -> 429 failure reason + retry_at_wib
        $product->mutateStock($inventory->id, 10);
        Http::fake([
            'https://shipping.example.test/api/v1/calculate/domestic-cost' => Http::sequence()
                ->push(['meta' => ['code' => 429, 'message' => 'Limit reached']], 429)
                ->push(['meta' => ['code' => 500, 'message' => 'Server error']], 500)
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
                ]),
        ]);

        $quotaCheckout = $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_rates_failure.reason', ShippingQuoteFailure::QUOTA);
        $this->assertNotEmpty($quotaCheckout->json('data.shipping_rates_failure.retry_at_wib'));
        $this->assertStringContainsString('WIB', (string) $quotaCheckout->json('data.shipping_rates_hint'));

        // Clear quota memo in cache + scoped instances (without wiping customer-checkout state)
        Cache::forget('komerce:capabilities:v1');
        $this->app->forgetScopedInstances();

        // Seed stale quota failure to confirm quoteCart() resets it before quoting
        resolve(ShippingQuoteContext::class)->markFailure('rajaongkir', ShippingQuoteFailure::QUOTA, 'Stale');

        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_rates_failure.reason', ShippingQuoteFailure::UNAVAILABLE)
            ->assertJsonPath('data.shipping_rates_failure.retry_at_wib', null);

        // Next call succeeds cleanly with null hint & failure
        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_rates_hint', null)
            ->assertJsonPath('data.shipping_rates_failure', null);
    }

    public function test_api_checkout_show_flags_partial_multi_package_empty_rates_and_aggregates_is_cod_false(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('shopper.shipping.drivers.komerce.enabled', true);
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', true);
        config()->set('shopper.shipping.provider', 'komerce');

        $currencyCode = shopper_currency();
        $currency = Currency::query()->where('code', $currencyCode)->first()
            ?? Currency::factory()->create(['code' => $currencyCode, 'name' => $currencyCode, 'symbol' => 'Rp', 'format' => 'Rp1.234']);
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
            'name' => 'Gudang A',
            'is_default' => true,
            'country_id' => $country->id,
            'rajaongkir_origin_id' => '1001',
            'latitude' => '-6.2',
            'longitude' => '106.8',
        ]);
        $invB = Inventory::factory()->create([
            'name' => 'Gudang B',
            'is_default' => false,
            'country_id' => $country->id,
            'rajaongkir_origin_id' => '2002',
            'latitude' => '-7.2',
            'longitude' => '112.7',
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
        $cart = Cart::query()->create(['currency_code' => $currencyCode, 'customer_id' => $user->id]);
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

        Sanctum::actingAs($user);

        Http::fake([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::sequence()
                // First call: pkg A has JNE REG (is_cod: true), pkg B has JNE REG (is_cod: false)
                ->push([
                    'meta' => ['code' => 200],
                    'data' => [
                        'calculate_reguler' => [
                            [
                                'shipping_name' => 'JNE',
                                'service_name' => 'REG',
                                'shipping_cost' => 15000,
                                'etd' => '2-3',
                                'is_cod' => true,
                            ],
                        ],
                    ],
                ])
                ->push([
                    'meta' => ['code' => 200],
                    'data' => [
                        'calculate_reguler' => [
                            [
                                'shipping_name' => 'JNE',
                                'service_name' => 'REG',
                                'shipping_cost' => 20000,
                                'etd' => '3-4',
                                'is_cod' => false,
                            ],
                        ],
                    ],
                ])
                // Second call: pkg A is served from delivery rate cache, pkg B returns 500
                ->push(['meta' => ['code' => 500, 'message' => 'Down']], 500),
        ]);

        $mixedCod = $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Sudirman 1',
            'postal_code' => '12190',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'destination_id' => '152',
            'destination_label' => 'Senayan, Kebayoran Baru',
            AddressDestination::DRIVER => 'komerce',
            AddressDestination::PIN_POINT => '-6.22,106.80',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_rates.0.service_code', 'jne:REG')
            ->assertJsonPath('data.shipping_rates.0.amount', 35000)
            ->assertJsonPath('data.shipping_rates.0.is_cod', false)
            ->assertJsonPath('data.shipping_rates_hint', null);

        $this->assertNotEmpty($mixedCod->json('data.shipping_rates_by_shipment'));

        // Bump cart item quantity for pkg B so delivery cache key misses and hits the 500 response
        $this->postJson('/api/v1/cart/items', ['product_id' => $productB->id, 'quantity' => 1])->assertOk();

        // Partial empty multi-package: pkg A has rates, pkg B fails -> hint & failure populated
        $partial = $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_rates_failure.reason', ShippingQuoteFailure::UNAVAILABLE);

        $this->assertStringContainsString('gangguan koneksi', (string) $partial->json('data.shipping_rates_hint'));
    }

    public function test_api_destinations_distinguishes_429_quota_from_502_outage_and_keeps_warm_cache(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'https://shipping.example.test/api/v1/destination/domestic-destination*' => Http::sequence()
                ->push([
                    'meta' => ['code' => 200],
                    'data' => [
                        [
                            'id' => 152,
                            'label' => 'Senayan, Kebayoran Baru',
                            'province_name' => 'DKI Jakarta',
                            'city_name' => 'Jakarta Selatan',
                            'zip_code' => '12190',
                        ],
                    ],
                ])
                ->push(['meta' => ['code' => 429, 'message' => 'Daily limit exceeded']], 429),
        ]);

        // Warm cache for "senayan"
        $this->getJson('/api/v1/checkout/destinations?q=senayan')
            ->assertOk()
            ->assertJsonPath('data.0.id', '152');

        // Uncached "bandung" hits 429 quota
        $quota = $this->getJson('/api/v1/checkout/destinations?q=bandung')
            ->assertStatus(429)
            ->assertJsonPath('data', []);
        $this->assertMatchesRegularExpression('/kuota|batas/i', (string) $quota->json('message'));
        $this->assertStringContainsString('WIB', (string) $quota->json('message'));
        $this->assertNotEmpty($quota->json('retry_at_wib'));

        // Warm cache still returns 200 despite exhausted quota
        $this->getJson('/api/v1/checkout/destinations?q=senayan')
            ->assertOk()
            ->assertJsonPath('data.0.id', '152');

        // Fresh outage (clear capabilities cache + scoped memo without wiping warm destination cache)
        Cache::forget('komerce:capabilities:v1');
        $this->app->forgetScopedInstances();
        Http::fake([
            'https://shipping.example.test/api/v1/destination/domestic-destination*' => Http::response([
                'meta' => ['code' => 500, 'message' => 'Internal server error'],
            ], 500),
        ]);

        $this->getJson('/api/v1/checkout/destinations?q=surabaya')
            ->assertStatus(502)
            ->assertJsonPath('data', []);
    }

    public function test_api_orders_can_track_awb_guard_indonesian_track_errors_and_paid_retry(): void
    {
        $this->requireModules('Komerce');

        Http::preventStrayRequests();
        Http::fake();

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        config()->set('komerce.payment_api_key', 'test-pay-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', true);
        config()->set('shopper.shipping.provider', null);

        $user = User::factory()->create();
        $inventory = Inventory::factory()->create(['rajaongkir_origin_id' => '17248']);
        $payMethod = PaymentMethod::factory()->create([
            'driver' => 'komerce',
            'slug' => 'va-bri',
            'is_enabled' => true,
        ]);

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $payMethod->id,
            'currency_code' => 'IDR',
            'price_amount' => 100000,
        ]);

        $blankShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'awb' => null,
            'tracking_number' => null,
        ]);

        $awbShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'shipped',
            'awb' => 'JNE-001',
            'tracking_number' => 'JNE-001',
        ]);

        Sanctum::actingAs($user);

        // can_track requires non-empty AWB
        $this->getJson("/api/v1/orders/{$order->number}")
            ->assertOk()
            ->assertJsonPath('data.shipments.0.can_track', false)
            ->assertJsonPath('data.shipments.1.can_track', true);

        // Retry on DB-Paid order returns ALREADY_PAID copy
        $this->postJson("/api/v1/orders/{$order->number}/retry-payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pesanan ini sudah dibayar. Tarik untuk memperbarui status.');

        // track() returns Indonesian even under English locale
        app()->setLocale('en');
        $blankTrack = $this->postJson("/api/v1/orders/{$order->number}/shipments/{$blankShipment->id}/track")
            ->assertStatus(422);
        $this->assertSame('Nomor resi belum tersedia untuk paket ini.', $blankTrack->json('message'));

        // No tracker under English locale also normalizes to Indonesian
        config()->set('komerce.enabled', false);
        $noTrackerShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'unknown_driver',
            'carrier_code' => 'unknown',
            'carrier_name' => 'Unknown',
            'service_code' => 'STD',
            'service_name' => 'Standard',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'shipped',
            'awb' => 'UNK-001',
            'tracking_number' => 'UNK-001',
            'external_reference' => 'EXT-UNK-001',
        ]);
        $noTrackerTrack = $this->postJson("/api/v1/orders/{$order->number}/shipments/{$noTrackerShipment->id}/track")
            ->assertStatus(422);
        $this->assertSame(
            'Pelacakan otomatis tidak tersedia untuk pengiriman ini. Riwayat pengiriman diperbarui oleh toko.',
            $noTrackerTrack->json('message'),
        );

        // Unfiltered /api/v1/orders includes COD orders whereas ?status=unpaid excludes them
        $codMethod = PaymentMethod::factory()->create([
            'driver' => 'manual',
            'slug' => 'cod',
            'is_enabled' => true,
        ]);
        $codOrder = Order::factory()->create([
            'number' => 'ORD-COD-M4-1',
            'customer_id' => $user->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $codMethod->id,
            'currency_code' => 'IDR',
            'price_amount' => 50000,
        ]);

        $unfiltered = $this->getJson('/api/v1/orders')->assertOk()->json('data');
        $this->assertContains('ORD-COD-M4-1', array_column($unfiltered, 'number'));

        $unpaid = $this->getJson('/api/v1/orders?status=unpaid')->assertOk()->json('data');
        $this->assertNotContains('ORD-COD-M4-1', array_column($unpaid, 'number'));
        $this->assertNotNull($awbShipment->fresh());
    }
}
