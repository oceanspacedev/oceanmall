<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Shipping\ShippingQuoteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Zone;
use Shopper\Shipping\DataTransferObjects\Package;
use Tests\TestCase;

/**
 * A half-configured provider (key set, but no warehouse origin / destination),
 * an exhausted quota or a failing provider:
 *
 * - by default checkout offers NO courier and explains why (as before provider
 *   drivers existed): the flat zone rate may be priced for another route;
 * - with the store setting shopper.shipping.fallback_to_zone_options the
 *   store's own Shopper zone options (CarrierRateService) are offered instead.
 */
final class ShopperRateFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private CarrierOption $option;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $this->country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($this->country->id);

        // A provider-quoted carrier and the store's own manual carrier, as seeded.
        $jne = Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE', 'driver' => 'rajaongkir', 'is_enabled' => true]);
        $manual = Carrier::query()->create(['slug' => 'manual', 'name' => 'Kurir Toko', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach([$jne->id, $manual->id]);

        $this->option = CarrierOption::query()->create([
            'carrier_id' => $manual->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);
    }

    public function test_by_default_a_provider_without_rates_offers_no_flat_zone_rate(): void
    {
        $this->requireModules('Komerce');

        Http::preventStrayRequests();
        Http::fake();
        Inventory::factory()->create(['country_id' => $this->country->id, 'is_default' => true, 'rajaongkir_origin_id' => '501']);
        resolve(KomerceCapabilities::class)->observe('shipping_cost', 429, '{"meta":{"message":"Daily limit exceeded"}}');

        $this->assertFalse(FetchDeliveryRates::fallbackToZoneOptions());
        $this->assertSame([], $this->rates(['destination_id' => '17547', 'destination_driver' => 'rajaongkir']));

        // The quota hint stays reachable: checkout tells the shopper when to retry.
        $this->assertTrue(resolve(ShippingQuoteContext::class)->lastFailure()?->isQuota());
        Http::assertNothingSent();
    }

    public function test_by_default_a_missing_warehouse_origin_offers_no_flat_zone_rate(): void
    {
        $this->requireModules('Komerce');

        Http::fake();
        Inventory::factory()->create(['country_id' => $this->country->id, 'is_default' => true]);

        $this->assertSame([], $this->rates(['destination_id' => '17547', 'destination_driver' => 'rajaongkir']));
    }

    public function test_the_switch_parses_env_style_values(): void
    {
        config()->set('shopper.shipping.fallback_to_zone_options', 'true');
        $this->assertTrue(FetchDeliveryRates::fallbackToZoneOptions());

        config()->set('shopper.shipping.fallback_to_zone_options', '0');
        $this->assertFalse(FetchDeliveryRates::fallbackToZoneOptions());
    }

    public function test_missing_warehouse_origin_falls_back_to_shopper_zone_options(): void
    {
        config()->set('shopper.shipping.fallback_to_zone_options', true);
        Http::fake();
        // (Saving the location may try to look its origin up; it finds none.)
        Inventory::factory()->create(['country_id' => $this->country->id, 'is_default' => true]);

        $rates = $this->rates(['destination_id' => '17547', 'destination_driver' => 'rajaongkir']);

        $this->assertManualRateOnly($rates);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/calculate/'));
    }

    public function test_exhausted_quota_falls_back_to_shopper_zone_options(): void
    {
        $this->requireModules('Komerce');

        config()->set('shopper.shipping.fallback_to_zone_options', true);
        Http::preventStrayRequests();
        Http::fake();
        Inventory::factory()->create(['country_id' => $this->country->id, 'is_default' => true, 'rajaongkir_origin_id' => '501']);
        resolve(KomerceCapabilities::class)->observe('shipping_cost', 429, '{"meta":{"message":"Daily limit exceeded"}}');

        $rates = $this->rates(['destination_id' => '17547', 'destination_driver' => 'rajaongkir']);

        $this->assertManualRateOnly($rates);
        Http::assertNothingSent();

        // Checkout tells the shopper when to retry (neutral failure, no provider name).
        $failure = resolve(ShippingQuoteContext::class)->lastFailure();
        $this->assertTrue($failure?->isQuota());
        $this->assertNotNull($failure->retryAfter);
    }

    public function test_failing_provider_falls_back_to_shopper_zone_options(): void
    {
        config()->set('shopper.shipping.fallback_to_zone_options', true);
        Http::fake([
            'https://shipping.example.test/*' => Http::response(['meta' => ['code' => 500, 'message' => 'boom']], 500),
        ]);
        Inventory::factory()->create(['country_id' => $this->country->id, 'is_default' => true, 'rajaongkir_origin_id' => '501']);

        $rates = $this->rates(['destination_id' => '17547', 'destination_driver' => 'rajaongkir']);

        $this->assertManualRateOnly($rates);
    }

    public function test_provider_rates_win_when_the_provider_answers(): void
    {
        $this->requireModules('Komerce');

        Http::fake([
            'https://shipping.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'message' => 'Success'],
                'data' => [[
                    'name' => 'Jalur Nugraha Ekakurir (JNE)',
                    'code' => 'jne',
                    'service' => 'REG',
                    'cost' => 18000,
                    'etd' => '2-3',
                ]],
            ]),
        ]);
        Inventory::factory()->create(['country_id' => $this->country->id, 'is_default' => true, 'rajaongkir_origin_id' => '501']);

        $rates = $this->rates(['destination_id' => '17547', 'destination_driver' => 'rajaongkir']);

        $this->assertCount(1, $rates);
        $this->assertSame('rajaongkir', $rates[0]['driver']);
        $this->assertSame(18000, (int) $rates[0]['amount']);
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array<int, array<string, mixed>>
     */
    private function rates(array $destination): array
    {
        return resolve(FetchDeliveryRates::class)->handle([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'country_id' => $this->country->id,
            ...$destination,
        ], [new Package(10, 10, 10, 0.5)]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rates
     */
    private function assertManualRateOnly(array $rates): void
    {
        $this->assertCount(1, $rates);
        $this->assertSame($this->option->id, $rates[0]['service_code']);
        $this->assertSame(15000, (int) $rates[0]['amount']);
        $this->assertSame('manual', $rates[0]['driver']);
    }
}
