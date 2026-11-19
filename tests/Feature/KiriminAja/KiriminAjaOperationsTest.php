<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Actions\Orders\MarkOrderPaid;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Carrier;
use Tests\TestCase;

/**
 * Carrier sync, callback registration and the paid-order hand-over, through
 * the generic commands / listeners only.
 */
final class KiriminAjaOperationsTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureKiriminAja();
        Http::preventStrayRequests();
    }

    public function test_carrier_sync_aligns_the_carriers_it_owns(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/couriers' => Http::response([
            'status' => true,
            'method' => 'get_active_courier',
            'text' => 'Data Loaded',
            'datas' => [
                ['code' => 'jne', 'name' => 'JNE Express', 'type' => 'Express'],
                ['code' => 'sicepat', 'name' => 'Sicepat Express', 'type' => 'Express'],
                ['code' => 'tiki', 'name' => 'Tiki', 'type' => 'Express'],
                ['code' => 'gosend', 'name' => 'GoSend', 'type' => 'Instant'],
            ],
        ])]);

        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE (RajaOngkir)', 'driver' => 'rajaongkir', 'is_enabled' => true]);
        $this->kiriminAjaCarrier('kiriminaja-tiki', attributes: ['is_enabled' => false, 'metadata' => ['courier_code' => 'tiki']]);
        $this->kiriminAjaCarrier('ninja');

        $this->artisan('shipping:sync-carriers', ['--driver' => KiriminAjaDriver::CODE])->assertSuccessful();

        $owned = Carrier::query()->where('driver', KiriminAjaDriver::CODE)->get()->keyBy('slug');

        $this->assertTrue($owned['kiriminaja-jne']->is_enabled, 'Created without taking over RajaOngkir\'s "jne".');
        $this->assertSame('jne', data_get($owned['kiriminaja-jne']->metadata, 'courier_code'));
        $this->assertTrue($owned['sicepat']->is_enabled);
        $this->assertFalse($owned['kiriminaja-tiki']->is_enabled, 'Staff switched it off: kept off.');
        $this->assertFalse($owned['ninja']->is_enabled, 'No longer offered.');
        $this->assertTrue(data_get($owned['ninja']->metadata, 'kiriminaja.synced_off'));
        $this->assertArrayNotHasKey('gosend', $owned->all(), 'Instant couriers use another API.');
        $this->assertSame('rajaongkir', Carrier::query()->where('slug', 'jne')->value('driver'));
    }

    public function test_a_switched_off_courier_returns_when_offered_again(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/couriers' => Http::sequence()
            ->push(['status' => true, 'datas' => [['code' => 'jne', 'name' => 'JNE', 'type' => 'Express']]])
            ->push(['status' => true, 'datas' => [['code' => 'jne', 'name' => 'JNE', 'type' => 'Express'], ['code' => 'ninja', 'name' => 'Ninja', 'type' => 'Express']]]),
        ]);
        $this->kiriminAjaCarrier('ninja');

        $this->artisan('shipping:sync-carriers', ['--driver' => KiriminAjaDriver::CODE])->assertSuccessful();
        $this->assertFalse(Carrier::query()->where('slug', 'ninja')->value('is_enabled'));

        $this->artisan('shipping:sync-carriers', ['--driver' => KiriminAjaDriver::CODE])->assertSuccessful();
        $this->assertTrue((bool) Carrier::query()->where('slug', 'ninja')->value('is_enabled'));
    }

    public function test_carrier_sync_can_set_the_courier_whitelist(): void
    {
        config()->set('kiriminaja.sync_whitelist', true);
        Http::fake([
            self::BASE_URL.'/api/mitra/couriers' => Http::response(['status' => true, 'datas' => [['code' => 'jne', 'name' => 'JNE', 'type' => 'Express']]]),
            self::BASE_URL.'/api/mitra/v3/set_whitelist_services' => Http::response(['status' => true, 'text' => 'Success to set']),
        ]);

        $this->artisan('shipping:sync-carriers', ['--driver' => KiriminAjaDriver::CODE])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'set_whitelist_services')
            && $request->data() === ['services' => ['jne']]);
    }

    public function test_an_empty_courier_answer_leaves_carriers_untouched(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/couriers' => Http::response(['status' => true, 'datas' => []])]);
        $this->kiriminAjaCarrier('jne');

        $this->artisan('shipping:sync-carriers', ['--driver' => KiriminAjaDriver::CODE])->assertFailed();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
    }

    public function test_set_callback_registers_the_module_url_with_the_token(): void
    {
        config()->set('app.url', 'https://shop.example.com');
        url()->forceRootUrl('https://shop.example.com');
        url()->forceScheme('https');
        config()->set('kiriminaja.webhook_secret', 'tok3n');
        Http::fake([self::BASE_URL.'/api/mitra/set_callback' => Http::response(['status' => true, 'method' => 'set_callback', 'text' => 'Callback di set'])]);

        $this->artisan('kiriminaja:set-callback')
            ->expectsOutputToContain('token=***')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->data() === ['url' => 'https://shop.example.com/webhooks/kiriminaja?token=tok3n']);
    }

    public function test_set_callback_refuses_a_non_https_url(): void
    {
        Http::fake();

        $this->artisan('kiriminaja:set-callback', ['--url' => 'http://localhost/webhooks/kiriminaja'])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_a_paid_order_hands_its_shipment_to_kiriminaja(): void
    {
        Http::fake([
            self::BASE_URL.'/api/mitra/v6.1/addresses*' => Http::response(self::addressesResponse()),
            self::BASE_URL.'/api/mitra/v2/schedules' => Http::response(['status' => true, 'schedules' => [
                ['clock' => now('Asia/Jakarta')->addDay()->format('Y-m-d').' 10:00:00', 'until' => '12:00', 'expired' => now()->addDay()->getTimestamp(), 'libur' => false],
            ]]),
            self::BASE_URL.'/api/mitra/v6.1/request_pickup' => fn (Request $request) => Http::response([
                'status' => true,
                'text' => 'Request pickup berhasil',
                'payment_status' => 'paid',
                'details' => [['order_id' => $request->data()['packages'][0]['order_id'], 'awb' => 'JNE7777', 'service' => 'jne', 'service_type' => 'REG23']],
                'pickup_number' => 'EPR-1',
            ]),
        ]);
        [$order, $shipment] = $this->paidShipment(orderOverrides: ['payment_status' => PaymentStatus::Pending]);

        resolve(MarkOrderPaid::class)->handle($order, 'manual', 'TRX-1');

        $shipment->refresh();
        $this->assertSame('JNE7777', $shipment->awb);
        $this->assertSame('labeled', $shipment->status);
        $this->assertNotNull($shipment->external_reference);
    }
}
