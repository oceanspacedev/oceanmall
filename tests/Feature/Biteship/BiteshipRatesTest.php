<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Shipping\ShippingQuoteContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\Facades\Shipping;

final class BiteshipRatesTest extends BiteshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBiteship();
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(): array
    {
        return [
            'success' => true,
            'object' => 'courier_pricing',
            'pricing' => [
                ['company' => 'jne', 'courier_name' => 'JNE', 'courier_code' => 'jne', 'courier_service_name' => 'Reguler', 'courier_service_code' => 'reg', 'shipment_duration_range' => '1 - 2', 'shipment_duration_unit' => 'days', 'service_type' => 'standard', 'shipping_fee' => 18000, 'price' => 18000],
                ['company' => 'sicepat', 'courier_name' => 'SiCepat', 'courier_code' => 'sicepat', 'courier_service_name' => 'BEST', 'courier_service_code' => 'best', 'shipment_duration_range' => '1', 'shipment_duration_unit' => 'days', 'price' => 25000],
                // Not asked for (a stale answer): never shown.
                ['company' => 'grab', 'courier_name' => 'Grab', 'courier_code' => 'grab', 'courier_service_name' => 'Instant', 'courier_service_code' => 'instant', 'shipment_duration_range' => '1 - 3', 'shipment_duration_unit' => 'hours', 'price' => 40000],
                ['company' => 'jne', 'courier_code' => 'jne', 'courier_service_code' => 'free', 'price' => 0],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function address(array $overrides = []): array
    {
        return [
            'first_name' => 'Budi',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'destination_id' => 'IDNP6IDNC147IDND830IDZ10110',
            'destination_driver' => 'biteship',
            ...$overrides,
        ];
    }

    public function test_checkout_quotes_enabled_biteship_couriers_by_area_id_in_whole_rupiah(): void
    {
        $this->biteshipCarriers(['jne', 'sicepat']);
        $inventory = $this->warehouse();
        Http::fake([self::BASE.'/v1/rates/couriers' => Http::response($this->pricing())]);

        $rows = resolve(FetchDeliveryRates::class)->handle($this->address(), [new Package(20, 12, 8, 1.2)], $inventory->id, 150000);

        $this->assertCount(2, $rows);
        $jne = collect($rows)->firstWhere('service_code', 'jne:reg');
        $this->assertSame(18000, $jne['amount']);
        $this->assertSame('IDR', $jne['currency']);
        $this->assertSame('biteship', $jne['driver']);
        $this->assertSame('jne', $jne['courier_company']);
        $this->assertSame('reg', $jne['courier_type']);
        $this->assertSame('1-2', $jne['estimated_days']);
        $this->assertSame(Carrier::query()->where('slug', 'biteship-jne')->value('id'), $jne['carrier_id']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE.'/v1/rates/couriers'
                && $request->hasHeader('Authorization', 'biteship_test.eyJhbGciOiJIUzI1NiJ9.test')
                && $request['origin_area_id'] === 'IDNP9IDNC74IDND6IDZ45111'
                && $request['destination_area_id'] === 'IDNP6IDNC147IDND830IDZ10110'
                && $request['couriers'] === 'jne,sicepat'
                && $request['items'][0]['weight'] === 1200
                && $request['items'][0]['value'] === 150000
                && ! isset($request['origin_postal_code']);
        });
    }

    public function test_rates_are_cached_per_route_weight_and_couriers(): void
    {
        $this->biteshipCarriers(['jne']);
        $inventory = $this->warehouse();
        Http::fake([self::BASE.'/v1/rates/couriers' => Http::response($this->pricing())]);

        $rates = resolve(FetchDeliveryRates::class);
        $rates->handle($this->address(), [new Package(10, 10, 10, 1.0)], $inventory->id, 100000);
        $rates->handle($this->address(), [new Package(10, 10, 10, 1.0)], $inventory->id, 120000);
        $this->assertCount(1, Http::recorded());

        $rates->handle($this->address(), [new Package(10, 10, 10, 2.0)], $inventory->id, 100000);
        $this->assertCount(2, Http::recorded());
    }

    public function test_postal_codes_are_the_fallback_when_no_area_id_is_known(): void
    {
        $this->biteshipCarriers(['jne']);
        $inventory = $this->warehouse(null);
        Http::fake([self::BASE.'/v1/rates/couriers' => Http::response($this->pricing())]);

        // The address holds another driver's destination id: ignored.
        $rows = resolve(FetchDeliveryRates::class)->handle(
            $this->address(['destination_id' => '17601', 'destination_driver' => 'rajaongkir']),
            [new Package(10, 10, 10, 1.0)],
            $inventory->id,
            100000,
        );

        $this->assertNotEmpty($rows);
        Http::assertSent(fn (Request $request): bool => $request['origin_postal_code'] === 45111
            && $request['destination_postal_code'] === 10110
            && ! isset($request['destination_area_id']));
    }

    public function test_coordinates_are_sent_only_with_a_customer_pin(): void
    {
        $this->biteshipCarriers(['jne']);
        $inventory = $this->warehouse();
        Http::fake([self::BASE.'/v1/rates/couriers' => Http::response($this->pricing())]);

        resolve(FetchDeliveryRates::class)->handle($this->address(['pin_point' => '-6.1754,106.8272']), [new Package(10, 10, 10, 1.0)], $inventory->id, 100000);

        Http::assertSent(fn (Request $request): bool => $request['destination_latitude'] === -6.1754
            && $request['origin_longitude'] === 108.5523);
    }

    public function test_couriers_switched_off_or_instant_are_never_asked_for(): void
    {
        $this->biteshipCarriers(['jne']);
        $this->biteshipCarriers(['sicepat'], false);
        $this->biteshipCarriers(['grab']);
        $inventory = $this->warehouse();
        Http::fake([self::BASE.'/v1/rates/couriers' => Http::response($this->pricing())]);

        $rows = resolve(FetchDeliveryRates::class)->handle($this->address(), [new Package(10, 10, 10, 1.0)], $inventory->id, 100000);

        $this->assertSame(['jne:reg'], array_column($rows, 'service_code'));
        Http::assertSent(fn (Request $request): bool => $request['couriers'] === 'jne');
    }

    public function test_no_enabled_carrier_means_no_call(): void
    {
        $inventory = $this->warehouse();
        Http::fake();

        $rows = resolve(FetchDeliveryRates::class)->handle($this->address(), [new Package(10, 10, 10, 1.0)], $inventory->id, 100000);

        $this->assertSame([], $rows);
        Http::assertNothingSent();
    }

    public function test_a_quota_answer_is_reported_as_quota_and_quotes_nothing(): void
    {
        $this->biteshipCarriers(['jne']);
        $inventory = $this->warehouse();
        Http::fake([self::BASE.'/v1/rates/couriers' => Http::response(['success' => false, 'error' => 'Insufficient balance', 'code' => 40200000], 402)]);

        $rows = resolve(FetchDeliveryRates::class)->handle($this->address(), [new Package(10, 10, 10, 1.0)], $inventory->id, 100000);

        $this->assertSame([], $rows);
        $this->assertTrue(resolve(ShippingQuoteContext::class)->lastFailure()?->isQuota());
    }

    public function test_a_provider_error_is_reported_as_unavailable(): void
    {
        $this->biteshipCarriers(['jne']);
        $inventory = $this->warehouse();
        Http::fake([self::BASE.'/v1/rates/couriers' => Http::response(['success' => false, 'error' => 'Internal error'], 500)]);

        $rows = resolve(FetchDeliveryRates::class)->handle($this->address(), [new Package(10, 10, 10, 1.0)], $inventory->id, 100000);

        $this->assertSame([], $rows);
        $failure = resolve(ShippingQuoteContext::class)->lastFailure();
        $this->assertSame('biteship', $failure?->driver);
        $this->assertFalse($failure->isQuota());
    }

    public function test_direct_calculate_rates_derives_the_context_from_the_address(): void
    {
        $this->biteshipCarriers(['jne']);
        $this->warehouse();
        Http::fake([
            self::BASE.'/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [
                ['id' => 'IDNP6IDNC147IDND830IDZ10110', 'name' => 'Gambir, Jakarta Pusat, DKI Jakarta. 10110', 'postal_code' => 10110, 'administrative_division_level_1_name' => 'DKI Jakarta', 'administrative_division_level_2_name' => 'Jakarta Pusat', 'administrative_division_level_3_name' => 'Gambir'],
            ]]),
            self::BASE.'/v1/rates/couriers' => Http::response($this->pricing()),
        ]);

        $to = new Address(firstName: 'Budi', lastName: '', street: 'Jl. Merdeka 1', city: 'Jakarta', postalCode: '10110', state: 'DKI Jakarta', country: 'ID');
        $rates = Shipping::driver('biteship')->calculateRates($to, $to, [new Package(10, 10, 10, 1.0)]);

        $this->assertSame(['jne:reg'], $rates->pluck('serviceCode')->all());
        $this->assertFalse(resolve(ShippingQuoteContext::class)->active(), 'The derived context is ended');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/v1/rates/couriers'
            && $request['destination_area_id'] === 'IDNP6IDNC147IDND830IDZ10110');
    }

    public function test_destination_search_maps_areas_to_neutral_rows_and_is_cached(): void
    {
        Http::fake([self::BASE.'/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [
            ['id' => 'IDNP6IDNC147IDND830IDZ10110', 'name' => 'Gambir, Jakarta Pusat, DKI Jakarta. 10110', 'country_code' => 'ID', 'postal_code' => 10110, 'administrative_division_level_1_name' => 'DKI Jakarta', 'administrative_division_level_2_name' => 'Jakarta Pusat', 'administrative_division_level_3_name' => 'Gambir'],
            ['id' => '', 'name' => 'broken'],
        ]])]);

        $driver = Shipping::driver('biteship');
        $rows = $driver->searchDestinations('Gambir');
        $driver->searchDestinations('  gambir ');

        $this->assertSame([[
            'id' => 'IDNP6IDNC147IDND830IDZ10110',
            'label' => 'Gambir, Jakarta Pusat, DKI Jakarta. 10110',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'district' => 'Gambir',
        ]], $rows);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['countries'] === 'ID'
            && $request['type'] === 'single'
            && $request['input'] === 'gambir');
    }

    public function test_warehouse_origin_resolves_from_an_exact_postal_code_match(): void
    {
        $inventory = $this->warehouse(null);
        Http::fake([self::BASE.'/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [
            ['id' => 'AREA-A', 'name' => 'Kejaksan, Cirebon', 'postal_code' => 45111, 'administrative_division_level_2_name' => 'Cirebon', 'administrative_division_level_3_name' => 'Kejaksan'],
            ['id' => 'AREA-B', 'name' => 'Other, Kuningan', 'postal_code' => 45111, 'administrative_division_level_2_name' => 'Kuningan'],
            ['id' => 'AREA-C', 'name' => 'Elsewhere', 'postal_code' => 45112, 'administrative_division_level_2_name' => 'Cirebon'],
        ]])]);

        $this->assertSame(['id' => 'AREA-A', 'label' => 'Kejaksan, Cirebon'], Shipping::driver('biteship')->resolveOrigin($inventory));
    }

    public function test_normalize_selected_rate_keeps_the_courier_for_fulfilment(): void
    {
        $normalized = Shipping::driver('biteship')->normalizeSelectedRate(['service_code' => 'sicepat:best', 'amount' => 25000], 25000);

        $this->assertSame('sicepat', $normalized['courier_company']);
        $this->assertSame('best', $normalized['courier_type']);
        $this->assertSame(25000, $normalized['shipping_cost']);
        $this->assertSame('biteship', $normalized['provider']);
    }
}
