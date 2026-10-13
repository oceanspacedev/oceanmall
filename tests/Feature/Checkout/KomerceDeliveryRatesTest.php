<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Support\RajaOngkirQuoteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Package;
use Tests\TestCase;

final class KomerceDeliveryRatesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $address = [
        'country_id' => 1,
        'first_name' => 'Budi',
        'last_name' => 'Santoso',
        'street_address' => 'Jl. Tuparev No. 10',
        'city' => 'JAKARTA PUSAT',
        'state' => 'DKI JAKARTA',
        'postal_code' => '10110',
        'rajaongkir_destination_id' => '17601',
        'rajaongkir_destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
        'rajaongkir_pin_point' => '-6.1754,106.8272',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        Http::preventStrayRequests();

        Inventory::factory()->create([
            'is_default' => true,
            'rajaongkir_origin_id' => '17248',
            'latitude' => -6.7366,
            'longitude' => 108.5414,
        ]);
    }

    public function test_checkout_quotes_from_komerce_delivery_tariff_and_keeps_the_official_row(): void
    {
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'calculate_reguler' => [
                        ['shipping_name' => 'JNE', 'service_name' => 'JNEFlat', 'weight' => 1, 'is_cod' => true, 'shipping_cost' => 10900, 'shipping_cashback' => 0, 'shipping_cost_net' => 10900, 'grandtotal' => 110900, 'service_fee' => 0, 'etd' => '1-2 day'],
                        ['shipping_name' => 'IDEXPRESS', 'service_name' => 'IDFLAT', 'weight' => 1, 'is_cod' => true, 'shipping_cost' => 10500, 'shipping_cashback' => 2625, 'shipping_cost_net' => 7875, 'grandtotal' => 110500, 'service_fee' => 0, 'etd' => '2-3 day'],
                    ],
                    'calculate_cargo' => [],
                    'calculate_instant' => [
                        ['shipping_name' => 'GOSEND', 'service_name' => 'Instant', 'weight' => 1, 'is_cod' => false, 'shipping_cost' => 25000, 'shipping_cashback' => 0, 'shipping_cost_net' => 25000, 'grandtotal' => 125000, 'service_fee' => 0, 'etd' => '-'],
                    ],
                ],
            ]),
        ]);

        $options = resolve(FetchDeliveryRates::class)->handle($this->address, [new Package(10, 10, 10, 1.0)]);

        $this->assertCount(3, $options);
        $this->assertSame('jne:JNEFlat', $options[0]['service_code']);
        $this->assertSame('shipping_delivery', $options[0]['provider']);
        $this->assertSame(10900, $options[0]['amount']);
        $this->assertSame('JNE', $options[0]['shipping_name']);
        $this->assertSame('JNEFlat', $options[0]['service_name']);
        $this->assertSame('1-2', $options[0]['estimated_days']);
        $this->assertSame('reguler', $options[0]['group']);

        $this->assertSame('ide:IDFLAT', $options[1]['service_code']);
        $this->assertSame(2625, $options[1]['shipping_cashback']);
        $this->assertSame(7875, $options[1]['shipping_cost_net']);

        $this->assertSame('gosend:Instant', $options[2]['service_code']);
        $this->assertSame('instant', $options[2]['group']);
        $this->assertNull($options[2]['estimated_days']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/tariff/api/v1/calculate')
            && $request['shipper_destination_id'] == 17248
            && $request['receiver_destination_id'] == 17601
            && $request['origin_pin_point'] === '-6.7366,108.5414'
            && $request['destination_pin_point'] === '-6.1754,106.8272');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'domestic-cost'));
    }

    public function test_couriers_disabled_in_cpanel_are_hidden_even_when_komerce_offers_them(): void
    {
        Carrier::query()->create(['slug' => 'ide', 'name' => 'IDexpress', 'driver' => 'rajaongkir', 'is_enabled' => false]);
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'calculate_reguler' => [
                        ['shipping_name' => 'JNE', 'service_name' => 'JNEFlat', 'shipping_cost' => 10900, 'shipping_cashback' => 0],
                        ['shipping_name' => 'IDEXPRESS', 'service_name' => 'IDFLAT', 'shipping_cost' => 10500, 'shipping_cashback' => 2625],
                    ],
                    'calculate_cargo' => [],
                    'calculate_instant' => [],
                ],
            ]),
        ]);

        $options = resolve(FetchDeliveryRates::class)->handle($this->address, [new Package(10, 10, 10, 1.0)]);

        $this->assertSame(['jne:JNEFlat'], array_column($options, 'service_code'));
    }

    public function test_falls_back_to_rajaongkir_cost_when_delivery_is_not_configured(): void
    {
        config()->set('komerce.shipping_delivery_api_key', '');
        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE Express', 'driver' => 'rajaongkir', 'is_enabled' => true]);
        Http::fake([
            'https://cost.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [['code' => 'jne', 'name' => 'JNE', 'service' => 'REG', 'cost' => 12000, 'etd' => '2 day']],
            ]),
        ]);

        $options = resolve(FetchDeliveryRates::class)->handle($this->address, [new Package(10, 10, 10, 1.0)]);

        $this->assertSame('jne:REG', $options[0]['service_code']);
        $this->assertArrayNotHasKey('provider', $options[0]);
    }

    public function test_delivery_outage_is_reported_as_transport_failure_not_as_no_couriers(): void
    {
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response(['meta' => ['code' => 500, 'status' => 'error']], 500),
        ]);

        $options = resolve(FetchDeliveryRates::class)->handle($this->address, [new Package(10, 10, 10, 1.0)]);

        $this->assertSame([], $options);
        $this->assertNotNull(resolve(RajaOngkirQuoteContext::class)->lastFailure());
    }
}
