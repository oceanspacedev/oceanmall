<?php

declare(strict_types=1);

namespace Tests\Unit\Komerce\Shipping;

use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Integrations\Komerce\Support\KomerceTrackingContext;
use App\Shipping\Contracts\PresentsCarriers;
use App\Shipping\Contracts\RequiresOriginId;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\ValidatesOriginIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\Exceptions\ShippingException;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

final class KomerceShippingDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
    }

    public function test_shopper_registers_configured_komerce_shipping_driver(): void
    {
        $this->assertContains('komerce', Shipping::availableDrivers());
        $this->assertTrue(Shipping::isConfigured('komerce'));

        $driver = Shipping::driver('komerce');

        $this->assertInstanceOf(KomerceShippingDriver::class, $driver);
        // Checkout quotes the Delivery tariff through calculateRates().
        $this->assertTrue($driver->supportsRealTimeRates());
        $this->assertTrue($driver->supportsLabels());
        $this->assertTrue($driver->supportsTracking());
    }

    public function test_track_requires_courier_context_and_calls_delivery_history_endpoint(): void
    {
        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'airway_bill' => 'JNE123',
                    'last_status' => 'ON_PROCESS',
                    'history' => [
                        ['desc' => 'Paket dijemput', 'date' => '2026-08-12', 'status' => 'PICKED'],
                    ],
                ],
            ]),
        ]);

        resolve(KomerceTrackingContext::class)->setCourier('JNE');

        $info = Shipping::driver('komerce')->track('JNE123');

        $this->assertSame('JNE123', $info->trackingNumber);
        $this->assertSame('in_transit', $info->status);
        $this->assertSame('ON_PROCESS', $info->statusDescription);
        $this->assertNotEmpty($info->events);

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://delivery.example.test/order/api/v1/orders/history-airway-bill')
                && $request['shipping'] === 'JNE'
                && $request['airway_bill'] === 'JNE123';
        });
    }

    public function test_create_shipment_is_not_supported_use_fulfill_shipment(): void
    {
        $this->expectException(ShippingException::class);

        Shipping::driver('komerce')->createShipment(
            new Address(
                firstName: 'A',
                lastName: 'B',
                street: 'Jl 1',
                city: 'Jakarta',
                postalCode: '10110',
                state: 'DKI',
                country: 'ID',
            ),
            new Address(
                firstName: 'C',
                lastName: 'D',
                street: 'Jl 2',
                city: 'Bandung',
                postalCode: '40111',
                state: 'Jabar',
                country: 'ID',
            ),
            [],
            'jne:REG',
        );
    }

    public function test_track_without_courier_throws(): void
    {
        $this->expectException(ShippingException::class);

        Shipping::driver('komerce')->track('JNE123');
    }

    public function test_komerce_driver_implements_destination_origin_carrier_and_sync_contracts(): void
    {
        $driver = Shipping::driver('komerce');

        $this->assertInstanceOf(ResolvesDestinations::class, $driver);
        $this->assertInstanceOf(RequiresOriginId::class, $driver);
        $this->assertInstanceOf(ValidatesOriginIds::class, $driver);
        $this->assertInstanceOf(PresentsCarriers::class, $driver);
        $this->assertInstanceOf(SyncsCarriers::class, $driver);
        $this->assertSame(['nullable', 'integer', 'min:1'], $driver->originIdRules());
    }

    public function test_search_destinations_uses_delivery_endpoint_caches_results_and_normalizes_keys(): void
    {
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'id' => 17601,
                        'label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
                        'province_name' => 'DKI JAKARTA',
                        'city_name' => 'JAKARTA PUSAT',
                        'district_name' => 'GAMBIR',
                        'subdistrict_name' => 'GAMBIR',
                        'zip_code' => '10110',
                    ],
                    [
                        'id' => 17602,
                        'label' => 'KEBON KELAPA, GAMBIR, JAKARTA PUSAT, 10120',
                        'city_name' => 'JAKARTA PUSAT',
                        'district_name' => 'GAMBIR',
                        'subdistrict_name' => 'KEBON KELAPA',
                        'zip_code' => '10120',
                    ],
                ],
            ]),
        ]);

        $driver = Shipping::driver('komerce');
        $first = $driver->searchDestinations('Gambir', 1);
        $second = $driver->searchDestinations('  gambir ', 2);

        $this->assertCount(1, $first);
        $this->assertSame('17601', $first[0]['id']);
        $this->assertSame('10110', $first[0]['postal_code']);
        $this->assertSame('JAKARTA PUSAT', $first[0]['city']);
        $this->assertSame('DKI JAKARTA', $first[0]['state']);
        $this->assertSame('GAMBIR', $first[0]['district']);
        $this->assertSame('10110', $first[0]['zip_code']);
        $this->assertCount(2, $second);
        Http::assertSentCount(1);

        // Warm cache survives quota exhaustion; cold miss throws without HTTP.
        resolve(KomerceCapabilities::class)->observe('shipping_delivery', 429, json_encode(['meta' => ['message' => 'Daily limit']]));
        $this->assertCount(1, $driver->searchDestinations('Gambir', 1));
        Http::assertSentCount(1);

        try {
            $driver->searchDestinations('Menteng', 5);
            $this->fail('Expected quota exhaustion on uncached Delivery destination search.');
        } catch (ShippingException $e) {
            $this->assertStringContainsString('quota', strtolower($e->getMessage()));
        }

        Http::assertSentCount(1);
    }

    public function test_search_destinations_handles_404_as_empty_and_throws_when_unconfigured(): void
    {
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['meta' => ['code' => 404]], 404),
        ]);

        $driver = Shipping::driver('komerce');
        $this->assertSame([], $driver->searchDestinations('UnknownPlace', 10));

        Cache::flush();
        config()->set('komerce.shipping_delivery_api_key', '');
        $this->expectException(ShippingException::class);
        $driver->searchDestinations('Gambir');
    }

    public function test_resolve_origin_works_in_delivery_only_and_skips_in_komship_mode(): void
    {
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'id' => 17248,
                        'label' => 'KERTAWINANGUN, KEDAWUNG, CIREBON, 45153',
                        'city_name' => 'CIREBON',
                        'district_name' => 'KEDAWUNG',
                        'subdistrict_name' => 'KERTAWINANGUN',
                        'zip_code' => '45153',
                    ],
                ],
            ]),
        ]);

        $driver = Shipping::driver('komerce');
        $inventory = Inventory::factory()->make([
            'street_address' => 'Jl. Tuparev, Kertawinangun',
            'city' => 'Cirebon',
            'postal_code' => '45153',
        ]);

        $resolved = $driver->resolveOrigin($inventory);
        $this->assertSame('17248', $resolved['id'] ?? null);

        // In Komship mode (Cost also enabled), KomerceShippingDriver defers origin resolution to RajaOngkirDriver.
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        $this->assertNull($driver->resolveOrigin($inventory));
    }

    public function test_track_respects_delivery_quota_without_http_call(): void
    {
        resolve(KomerceCapabilities::class)->observe('shipping_delivery', 429, json_encode(['meta' => ['message' => 'Daily limit']]));
        resolve(KomerceTrackingContext::class)->setCourier('JNE');
        Http::fake();

        try {
            Shipping::driver('komerce')->track('JNE123');
            $this->fail('Expected quota exception on Delivery track.');
        } catch (ShippingException $e) {
            $this->assertStringContainsString('quota', strtolower($e->getMessage()));
        }

        Http::assertNothingSent();
    }

    public function test_track_throws_and_records_quota_on_http_200_error_envelope(): void
    {
        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded'],
                'data' => null,
            ], 200),
        ]);

        resolve(KomerceTrackingContext::class)->setCourier('JNE');

        try {
            Shipping::driver('komerce')->track('JNE123');
            $this->fail('Expected ShippingException on Delivery HTTP 200 quota envelope.');
        } catch (ShippingException $e) {
            $this->assertStringContainsString('quota', strtolower($e->getMessage()));
        }

        $this->assertTrue(resolve(KomerceCapabilities::class)->quotaExhausted('shipping_delivery'));
    }
}
