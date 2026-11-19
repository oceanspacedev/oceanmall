<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Shipping\ShippingCapabilities;
use App\Shipping\ShippingQuoteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Country;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * Destination search, warehouse origin and checkout tariffs through the
 * KiriminAja driver (Http::fake, shapes from developer.kiriminaja.com).
 */
final class KiriminAjaRatesTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureKiriminAja();
        Http::preventStrayRequests();
    }

    public function test_destination_search_returns_neutral_rows_and_is_cached(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/addresses*' => Http::response(self::addressesResponse())]);
        $driver = Shipping::driver(KiriminAjaDriver::CODE);

        $rows = $driver->searchDestinations('Kesambi');
        $again = $driver->searchDestinations('  KESAMBI ');

        $this->assertSame($rows, $again);
        $this->assertCount(3, $rows);
        $this->assertSame([
            'id' => '1535:22001',
            'label' => 'Kesambi, Kesambi, Kota Cirebon, Jawa Barat, 45134',
            'postal_code' => '45134',
            'state' => 'Jawa Barat',
            'city' => 'Kota Cirebon',
            'district' => 'Kesambi',
            'subdistrict' => 'Kesambi',
            'kecamatan_id' => 1535,
            'kelurahan_id' => 22001,
        ], $rows[0]);
        $this->assertSame([], $driver->searchDestinations('ab'), 'KiriminAja needs at least 3 characters.');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::BASE_URL.'/api/mitra/v6.1/addresses?search=kesambi'
            && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY));
    }

    public function test_the_store_offers_the_kiriminaja_search_and_origin_field(): void
    {
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame(KiriminAjaDriver::CODE, $capabilities->destinationDriverCode());
        $this->assertTrue($capabilities->requiresDestination());
        $this->assertTrue($capabilities->originDrivers()->has(KiriminAjaDriver::CODE));
        $this->assertTrue($capabilities->quoteChains()[KiriminAjaDriver::CODE][0] instanceof KiriminAjaDriver);
    }

    public function test_warehouse_origin_is_resolved_from_an_exact_postal_code_match(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/addresses*' => Http::response(self::addressesResponse())]);

        $inventory = $this->warehouse(null);

        $this->assertSame(
            ['id' => '1536:22010', 'label' => 'Lemahwungkuk, Lemahwungkuk, Kota Cirebon, Jawa Barat, 45111'],
            resolve(KiriminAjaDriver::class)->resolveOrigin($inventory),
        );

        $inventory->forceFill(['postal_code' => '99999'])->save();
        $this->assertNull(resolve(KiriminAjaDriver::class)->resolveOrigin($inventory), 'No exact postal match, no guess.');
    }

    public function test_checkout_quotes_the_enabled_kiriminaja_couriers(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/shipping_price' => Http::response(self::priceResponse())]);
        [$zone, $country] = $this->indonesiaZone();
        $this->kiriminAjaCarrier('jne', $zone, ['name' => 'JNE Express']);
        $this->kiriminAjaCarrier('sicepat', $zone, ['name' => 'SiCepat']);
        $this->kiriminAjaCarrier('jnt', $zone);
        $this->kiriminAjaCarrier('tiki', $zone, ['is_enabled' => false]);
        $this->warehouse();

        $rates = $this->rates($country);

        $this->assertSame(['jne:REG23', 'sicepat:REG'], array_column($rates, 'service_code'), 'Zero-cost rows are dropped.');
        $this->assertSame([KiriminAjaDriver::CODE, KiriminAjaDriver::CODE], array_column($rates, 'driver'));
        $this->assertSame(12000, $rates[0]['amount']);
        $this->assertSame('2-3', $rates[0]['estimated_days']);
        $this->assertSame('JNE Express', $rates[0]['carrier_name']);
        $this->assertSame('REG23', $rates[0]['kiriminaja_service_type']);
        $this->assertSame(12000, $rates[0]['kiriminaja_cost']);
        // SiCepat forces insurance: the customer pays cost + insurance (whole rupiah).
        $this->assertSame(11500, $rates[1]['amount']);
        $this->assertSame(500, $rates[1]['kiriminaja_insurance']);
        $this->assertSame('1-2', $rates[1]['estimated_days']);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/api/mitra/v6.1/shipping_price')) {
                return false;
            }

            $data = $request->data();

            return $data['origin'] === 1536
                && $data['subdistrict_origin'] === 22010
                && $data['destination'] === 1535
                && $data['subdistrict_destination'] === 22001
                && $data['weight'] === 500
                && $data['item_value'] === 150000
                && $data['insurance'] === 0
                && $data['courier'] === ['jne', 'sicepat', 'jnt'];
        });

        // Same route + weight + value: served from cache.
        $this->rates($country);
        Http::assertSentCount(1);
    }

    public function test_a_store_wide_insurance_setting_adds_the_fee_to_every_rate(): void
    {
        config()->set('kiriminaja.insurance', true);
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/shipping_price' => Http::response(self::priceResponse())]);
        [$zone, $country] = $this->indonesiaZone();
        $this->kiriminAjaCarrier('jne', $zone);
        $this->warehouse();

        $rates = $this->rates($country);

        $this->assertSame(14500, $rates[0]['amount']);
        Http::assertSent(fn (Request $request): bool => ($request->data()['insurance'] ?? null) === 1);
    }

    public function test_no_quote_without_a_warehouse_origin_or_destination(): void
    {
        Http::fake();
        [$zone, $country] = $this->indonesiaZone();
        $this->kiriminAjaCarrier('jne', $zone);
        $this->warehouse(null);

        $this->assertSame([], $this->rates($country));
        // The store may try to resolve the missing origin (address search), but never quotes.
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'shipping_price'));
    }

    public function test_rate_limiting_is_reported_as_a_quota_failure(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/shipping_price' => Http::response(['message' => 'Too Many Attempts.'], 429)]);
        [$zone, $country] = $this->indonesiaZone();
        $this->kiriminAjaCarrier('jne', $zone);
        $this->warehouse();

        $this->assertSame([], $this->rates($country));
        $failure = resolve(ShippingQuoteContext::class)->lastFailure();
        $this->assertSame(KiriminAjaDriver::CODE, $failure?->driver);
        $this->assertTrue($failure?->isQuota());
    }

    public function test_an_error_envelope_is_a_provider_failure(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/shipping_price' => Http::response(['status' => false, 'text' => 'Destination not found'])]);
        [$zone, $country] = $this->indonesiaZone();
        $this->kiriminAjaCarrier('jne', $zone);
        $this->warehouse();

        $this->assertSame([], $this->rates($country));
        $this->assertStringContainsString('Destination not found', (string) resolve(ShippingQuoteContext::class)->lastFailure()?->message);
    }

    public function test_a_direct_rate_call_derives_the_destination_from_the_postal_code(): void
    {
        Http::fake([
            self::BASE_URL.'/api/mitra/v6.1/addresses*' => Http::response(self::addressesResponse()),
            self::BASE_URL.'/api/mitra/v6.1/shipping_price' => Http::response(self::priceResponse()),
        ]);
        $this->kiriminAjaCarrier('jne');
        $this->warehouse();

        $to = new Address(firstName: 'Budi', lastName: 'Santoso', street: 'Jl. Pekiringan 1', city: 'Cirebon', postalCode: '45131', state: 'Jawa Barat', country: 'ID');
        $rates = Shipping::driver(KiriminAjaDriver::CODE)->calculateRates($to, $to, [new Package(10, 10, 10, 1)]);

        $this->assertSame(['jne:REG23'], $rates->pluck('serviceCode')->all(), 'Only the couriers this driver owns.');
        $this->assertFalse(resolve(ShippingQuoteContext::class)->active(), 'The derived context is ended.');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'shipping_price')
            && $request->data()['destination'] === 1535
            && $request->data()['subdistrict_destination'] === 22002
            && $request->data()['weight'] === 1000
            && $request->data()['courier'] === ['jne']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rates(Country $country): array
    {
        return resolve(FetchDeliveryRates::class)->handle([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Kesambi Raya 5',
            'postal_code' => '45134',
            'city' => 'Kota Cirebon',
            'country_id' => $country->id,
            'destination_id' => '1535:22001',
            'destination_driver' => KiriminAjaDriver::CODE,
        ], [new Package(10, 10, 10, 0.5)], itemValue: 150000);
    }
}
