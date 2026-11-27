<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Shipping\InventoryOrigins;
use App\Shipping\ShippingQuoteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\Facades\Shipping;
use Shopper\Shipping\Services\CarrierRateService;
use Tests\TestCase;

/**
 * Shopper's CarrierRateService (or any code) calling the RajaOngkir driver
 * directly, outside checkout: the driver derives its quote context from
 * Shopper's Address DTO instead of returning nothing.
 */
final class DirectRateCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', true);

        Http::fake([
            'https://shipping.example.test/api/v1/destination/domestic-destination*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['id' => 17500, 'label' => 'KESAMBI, CIREBON', 'zip_code' => '45131'],
                    ['id' => 17547, 'label' => 'HARJAMUKTI, CIREBON', 'zip_code' => '45153'],
                ],
            ]),
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

        Inventory::factory()->create(['is_default' => true, 'rajaongkir_origin_id' => '501']);
    }

    public function test_direct_calculate_rates_derives_origin_and_destination_from_the_address(): void
    {
        $rates = Shipping::driver('rajaongkir')->calculateRates($this->origin(), $this->destination('45153'), [new Package(10, 10, 10, 0.5)]);

        $this->assertCount(1, $rates);
        $this->assertSame(18000, $rates->first()->amount);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/calculate/domestic-cost')
            && (string) $request['origin'] === '501'
            && (string) $request['destination'] === '17547');

        // The derived context is closed again: checkout state never leaks.
        $this->assertFalse(resolve(ShippingQuoteContext::class)->active());
    }

    public function test_shopper_carrier_rate_service_gets_rates_for_a_rajaongkir_carrier(): void
    {
        $carrier = Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'driver' => 'rajaongkir', 'is_enabled' => true]);

        $rates = resolve(CarrierRateService::class)->getRatesForCarrier($carrier, $this->origin(), $this->destination('45153'), [new Package(10, 10, 10, 0.5)]);

        $this->assertCount(1, $rates);
    }

    public function test_an_address_without_an_exact_postal_code_match_quotes_nothing(): void
    {
        $rates = Shipping::driver('rajaongkir')->calculateRates($this->origin(), $this->destination('99999'), [new Package(10, 10, 10, 0.5)]);

        $this->assertCount(0, $rates);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/calculate/'));
    }

    public function test_delivery_only_direct_calculate_rates_derives_komerce_origin_and_destination(): void
    {
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('shopper.shipping.drivers.komerce.enabled', true);

        $inventory = Inventory::query()->where('is_default', true)->firstOrFail();
        $inventory->forceFill(['latitude' => -6.7366, 'longitude' => 108.5414])->save();
        resolve(InventoryOrigins::class)->forget($inventory, 'rajaongkir');
        resolve(InventoryOrigins::class)->put($inventory, 'komerce', '17248');

        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['id' => 17547, 'label' => 'HARJAMUKTI, CIREBON', 'zip_code' => '45153'],
                ],
            ]),
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'calculate_reguler' => [
                        ['shipping_name' => 'JNE', 'service_name' => 'JNEFlat', 'shipping_cost' => 12000, 'shipping_cashback' => 0, 'etd' => '1-2 day'],
                    ],
                    'calculate_cargo' => [],
                    'calculate_instant' => [],
                ],
            ]),
        ]);

        $rates = Shipping::driver('komerce')->calculateRates($this->origin(), $this->destination('45153'), [new Package(10, 10, 10, 0.5)]);

        $this->assertCount(1, $rates);
        $this->assertSame(12000, $rates->first()->amount);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/tariff/api/v1/calculate')
            && (string) $request['shipper_destination_id'] === '17248'
            && (string) $request['receiver_destination_id'] === '17547');
        $this->assertFalse(resolve(ShippingQuoteContext::class)->active());
    }

    private function origin(): Address
    {
        return new Address('Toko', '', 'Jl. Gudang 1', 'Jakarta', '10110', 'DKI Jakarta', 'ID');
    }

    private function destination(string $postalCode): Address
    {
        return new Address('Budi', 'Santoso', 'Jl. Merdeka 1', 'Cirebon', $postalCode, 'Jawa Barat', 'ID');
    }
}
