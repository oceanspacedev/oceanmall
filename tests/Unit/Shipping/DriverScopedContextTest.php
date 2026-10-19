<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Models\OrderShipment;
use App\Support\KomerceFulfillmentContext;
use App\Support\KomerceTrackingContext;
use App\Support\RajaOngkirQuoteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * Shopper's ShippingManager is a singleton that memoizes driver instances, while
 * the driver contexts are scoped and flushed before every queued job / Octane
 * request. A memoized driver must read the current context, not the one alive
 * when it was first resolved.
 */
final class DriverScopedContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('komerce.couriers', ['jne']);
    }

    public function test_rajaongkir_calculate_rates_reads_quote_context_after_scoped_flush(): void
    {
        Http::fake([
            'https://shipping.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'cost' => 18000, 'etd' => '2-3'],
                ],
            ]),
        ]);

        $driver = Shipping::driver('rajaongkir');

        app()->forgetScopedInstances();
        resolve(RajaOngkirQuoteContext::class)->set('501', '114', ['jne']);

        $rates = Shipping::driver('rajaongkir')->calculateRates(
            $this->address(),
            $this->address(),
            [new Package(length: 10, width: 10, height: 10, weight: 1)],
        );

        $this->assertSame($driver, Shipping::driver('rajaongkir'));
        $this->assertCount(1, $rates);
        Http::assertSent(fn (ClientRequest $request): bool => (string) $request['origin'] === '501'
            && (string) $request['destination'] === '114');
    }

    public function test_rajaongkir_track_reads_tracking_context_after_scoped_flush(): void
    {
        Http::fake([
            'https://shipping.example.test/api/v1/track/waybill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['delivery_status' => ['status' => 'ON PROCESS'], 'manifest' => []],
            ]),
        ]);

        Shipping::driver('rajaongkir');

        app()->forgetScopedInstances();
        $context = resolve(KomerceTrackingContext::class);
        $context->setCourier('jne');

        Shipping::driver('rajaongkir')->track('JNE123');

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['courier'] ?? null) === 'jne' && ($query['awb'] ?? null) === 'JNE123';
        });
        $this->assertSame('ON PROCESS', data_get($context->lastRaw(), 'data.delivery_status.status'));
    }

    public function test_komerce_track_reads_tracking_context_after_scoped_flush(): void
    {
        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['airway_bill' => 'JNE123', 'last_status' => 'ON_PROCESS', 'history' => []],
            ]),
        ]);

        Shipping::driver('komerce');

        app()->forgetScopedInstances();
        $context = resolve(KomerceTrackingContext::class);
        $context->setCourier('JNE');

        Shipping::driver('komerce')->track('JNE123');

        Http::assertSent(fn (ClientRequest $request): bool => $request['shipping'] === 'JNE'
            && $request['airway_bill'] === 'JNE123');
        $this->assertSame('ON_PROCESS', data_get($context->lastRaw(), 'data.last_status'));
    }

    public function test_komerce_create_shipment_reads_fulfillment_context_after_scoped_flush(): void
    {
        Http::fake();

        $shipment = OrderShipment::query()->create([
            'order_id' => Order::factory()->create()->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'awb' => 'JNE-AFTER-FLUSH',
        ]);

        Shipping::driver('komerce');

        app()->forgetScopedInstances();
        resolve(KomerceFulfillmentContext::class)->set($shipment);

        $result = Shipping::driver('komerce')->createShipment($this->address(), $this->address(), [], 'jne:REG');

        $this->assertSame('JNE-AFTER-FLUSH', $result->trackingNumber);
    }

    private function address(): Address
    {
        return new Address(
            firstName: 'Budi',
            lastName: 'Santoso',
            street: 'Jl. Merdeka 1',
            city: 'Jakarta',
            postalCode: '10110',
            state: 'DKI Jakarta',
            country: 'ID',
        );
    }
}
