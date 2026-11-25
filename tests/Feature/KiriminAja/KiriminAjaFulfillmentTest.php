<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Actions\Shipping\RefreshShipmentTracking;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Listeners\CancelShipmentsOnOrderCancelled;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Tests\TestCase;

/**
 * Paid shipment → request pickup → AWB (tracking) → delivered, cancel, and
 * the retry / crash idempotency of the registration, driven by the generic
 * shipping:* commands and listeners.
 */
final class KiriminAjaFulfillmentTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'Asia/Jakarta'));
        $this->configureKiriminAja();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paid_shipments_are_registered_with_a_pickup_request(): void
    {
        $this->fakeApi();
        [$order, $shipment] = $this->paidShipment();
        $orderId = $this->expectedOrderId($shipment);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $shipment->refresh();
        $this->assertSame($orderId, $shipment->external_reference);
        $this->assertNull($shipment->awb, 'The AWB is issued later (callback / tracking).');
        $this->assertSame('pending', $shipment->status);
        $this->assertSame('EPR-123456789', data_get($shipment->metadata, 'kiriminaja.pickup_number'));
        $this->assertSame('unpaid', data_get($shipment->metadata, 'kiriminaja.payment_status'));
        $this->assertSame('https://pay.example.test/qr', data_get($shipment->metadata, 'kiriminaja.qr_url'));
        $this->assertTrue(ShipmentMetadata::pickupRequested($shipment));
        $this->assertNull(ShipmentMetadata::fulfillmentError($shipment));

        Http::assertSent(function (Request $request) use ($orderId): bool {
            if (! str_ends_with($request->url(), '/api/mitra/v6.1/request_pickup')) {
                return false;
            }

            $data = $request->data();
            $package = $data['packages'][0];

            return $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && $data['kecamatan_id'] === 1536
                && $data['kelurahan_id'] === 22010
                && $data['phone'] === '02311234567'
                && $data['zipcode'] === '45111'
                && $data['name'] === 'Gudang Cirebon'
                && $data['schedule'] === '2026-11-19 14:00:00'
                && $package['order_id'] === $orderId
                && strlen($package['order_id']) <= 20
                && $package['destination_kecamatan_id'] === 1535
                && $package['destination_kelurahan_id'] === 22001
                && $package['destination_phone'] === '6281234567890'
                && $package['destination_name'] === 'Budi Santoso'
                && $package['destination_zipcode'] === '45134'
                && $package['destination_latitude'] === -6.72
                && $package['service'] === 'jne'
                && $package['service_type'] === 'REG23'
                && $package['shipping_cost'] === 12000
                && $package['item_value'] === 100000
                && $package['cod'] === 0
                && $package['insurance_amount'] === 0
                && $package['weight'] === 1000
                && $package['qty'] === 2
                && $package['height'] === 16
                && $package['width'] === 12
                && $package['length'] === 20
                && $package['drop'] === false
                && $package['items'][0]['price'] === 50000
                && $package['items'][0]['metadata'] === ['sku' => 'KOPI-500'];
        });

        // Next run: the registered parcel is only reconciled, never re-registered.
        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        $this->assertSame(1, $this->sentCount('schedules'));
        $this->assertSame(1, $this->sentCount('tracking'));
        $this->assertSame(1, $this->sentCount('request_pickup'));
        $this->assertSame($order->id, $shipment->order_id);
    }

    public function test_tracking_brings_the_awb_and_delivery(): void
    {
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST', 'status' => 'pending']);

        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::sequence()
            ->push(self::trackingResponse('OM1-TEST', 'JNE0001', 105))
            ->push(self::trackingResponse('OM1-TEST', 'JNE0001', 106, shippedAt: '2026-11-19 17:44:04'))
            ->push(self::trackingResponse('OM1-TEST', 'JNE0001', 200, delivered: true, shippedAt: '2026-11-19 17:44:04')),
        ]);

        $this->artisan('shipping:refresh-tracking')->assertSuccessful();
        $shipment->refresh();
        $this->assertSame('JNE0001', $shipment->awb);
        $this->assertSame('labeled', $shipment->status);

        $this->artisan('shipping:refresh-tracking')->assertSuccessful();
        $this->assertSame('in_transit', $shipment->refresh()->status);

        $shipment = resolve(RefreshShipmentTracking::class)->handle($shipment);
        $this->assertSame('delivered', $shipment->status);
        $this->assertSame('Delivered to BUDI', ShipmentMetadata::trackingHistory($shipment)[0]['description']);
        $this->assertSame('2026-11-20 16:00', ShipmentMetadata::trackingHistory($shipment)[0]['datetime']);
        $this->assertArrayNotHasKey('images', (array) data_get($shipment->metadata, 'kiriminaja.tracking.details'));

        Http::assertSent(fn (Request $request): bool => $request->data() === ['order_id' => 'OM1-TEST']);
    }

    public function test_tracking_for_another_parcel_is_rejected(): void
    {
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse('OM9-OTHER', 'JNE0009', 105))]);

        $this->expectExceptionMessage('instead of OM1-TEST');

        resolve(KiriminAjaDriver::class)->reconcileShipment($shipment);
    }

    public function test_a_registration_lost_in_a_crash_is_adopted_not_duplicated(): void
    {
        [, $shipment] = $this->paidShipment();
        $orderId = $this->expectedOrderId($shipment);
        $shipment->forceFill(['metadata' => [
            ...$shipment->metadata,
            'kiriminaja' => ['order_id' => $orderId, 'attempted_at' => now()->subMinutes(10)->toIso8601String()],
        ]])->save();

        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse($orderId, 'JNE0002', 105))]);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $shipment->refresh();
        $this->assertSame($orderId, $shipment->external_reference);
        $this->assertSame('JNE0002', $shipment->awb);
        $this->assertSame('labeled', $shipment->status);
        $this->assertSame(0, $this->sentCount('request_pickup'));
    }

    public function test_a_duplicate_order_id_answer_adopts_the_existing_parcel(): void
    {
        [, $shipment] = $this->paidShipment();
        $orderId = $this->expectedOrderId($shipment);

        Http::fake([
            self::BASE_URL.'/api/mitra/v2/schedules' => Http::response($this->schedules()),
            self::BASE_URL.'/api/mitra/v6.1/request_pickup' => Http::response(['status' => false, 'text' => 'Order ID sudah digunakan']),
            self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse($orderId, null, 101)),
        ]);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $shipment->refresh();
        $this->assertSame($orderId, $shipment->external_reference);
        $this->assertNull(ShipmentMetadata::fulfillmentError($shipment));
    }

    public function test_provider_errors_are_recorded_and_retried(): void
    {
        [, $shipment] = $this->paidShipment();

        Http::fake([
            self::BASE_URL.'/api/mitra/v2/schedules' => Http::response($this->schedules()),
            self::BASE_URL.'/api/mitra/v6.1/request_pickup' => Http::response(['status' => false, 'text' => 'Saldo tidak cukup']),
            self::BASE_URL.'/api/mitra/tracking' => Http::response(['status' => false, 'text' => 'Data tidak ditemukan']),
        ]);

        $this->artisan('shipping:fulfill-paid')->expectsOutputToContain('Saldo tidak cukup')->assertSuccessful();

        $shipment->refresh();
        $this->assertNull($shipment->external_reference);
        $this->assertStringContainsString('Saldo tidak cukup', (string) ShipmentMetadata::fulfillmentError($shipment));
    }

    public function test_unpaid_orders_are_never_shipped_cod_is_not_supported(): void
    {
        Http::fake();
        [$order, $shipment] = $this->paidShipment(orderOverrides: ['payment_status' => PaymentStatus::Pending]);

        try {
            resolve(KiriminAjaDriver::class)->fulfillShipment($shipment);
            $this->fail('An unpaid order must not be registered.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not paid', $e->getMessage());
        }

        $this->assertNull($shipment->refresh()->external_reference);
        $this->assertSame(0, $this->sentCount('request_pickup'));
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_a_fixed_pickup_time_skips_the_schedule_endpoint(): void
    {
        config()->set('kiriminaja.pickup.schedule', '08:30');
        Http::fake([
            self::BASE_URL.'/api/mitra/v6.1/request_pickup' => Http::response($this->pickupResponse('X')),
        ]);
        [, $shipment] = $this->paidShipment();

        resolve(KiriminAjaDriver::class)->fulfillShipment($shipment);

        // 08:00 WIB is less than an hour before 08:30: tomorrow.
        Http::assertSent(fn (Request $request): bool => ($request->data()['schedule'] ?? null) === '2026-11-20 08:30:00');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'schedules'));
    }

    public function test_an_address_of_another_provider_is_matched_by_postal_code(): void
    {
        $this->fakeApi();
        [$order, $shipment] = $this->paidShipment();
        $order->forceFill(['metadata' => json_encode(['shipping_address' => [
            'destination_id' => '17547',
            'destination_driver' => 'rajaongkir',
            'pin_point' => '-6.7200,108.5500',
        ]], JSON_THROW_ON_ERROR)])->save();

        resolve(KiriminAjaDriver::class)->fulfillShipment($shipment);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'request_pickup')
            && $request->data()['packages'][0]['destination_kecamatan_id'] === 1535
            && $request->data()['packages'][0]['destination_kelurahan_id'] === 22001);
    }

    public function test_order_cancellation_cancels_by_awb_once(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v3/cancel_shipment*' => Http::response([
            'status' => true, 'method' => 'cancel_shipment', 'text' => 'Paket akan dibatalkan dalam 2x24jam', 'data' => ['success' => 'pending'],
        ])]);
        [$order, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST', 'awb' => 'JNE0001']);

        resolve(CancelShipmentsOnOrderCancelled::class)->handle(new OrderCancelled($order));
        resolve(CancelShipmentsOnOrderCancelled::class)->handle(new OrderCancelled($order));

        $this->assertSame(1, $this->sentCount('cancel_shipment'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cancel_shipment')
            && $request->data()['awb'] === 'JNE0001'
            && str_contains($request->url(), 'awb=JNE0001'));
        $this->assertNotNull(data_get($shipment->refresh()->metadata, 'kiriminaja.cancel_requested_at'));
    }

    public function test_cancelling_before_an_awb_exists_is_left_to_the_dashboard(): void
    {
        Http::fake();
        [$order, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);

        resolve(CancelShipmentsOnOrderCancelled::class)->handle(new OrderCancelled($order));

        $this->assertSame(0, $this->sentCount('cancel_shipment'));
        $this->assertStringContainsString('dashboard', (string) data_get($shipment->refresh()->metadata, 'kiriminaja.cancel_note'));
    }

    public function test_a_driver_less_shipment_it_registers_becomes_kiriminajas(): void
    {
        $this->fakeApi();
        [, $shipment] = $this->paidShipment();
        $shipment->forceFill(['driver' => null])->save();

        // As the only configured fulfiller it takes a legacy row with no driver.
        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $shipment->refresh();
        $this->assertSame($this->expectedOrderId($shipment), $shipment->external_reference);
        $this->assertSame('kiriminaja', $shipment->driver, 'Stamped like Biteship/Komerce, so no other module takes it for a legacy row of its own.');
    }

    public function test_an_unregistered_shipment_is_not_cancelled(): void
    {
        Http::fake();
        [$order] = $this->paidShipment();

        resolve(CancelShipmentsOnOrderCancelled::class)->handle(new OrderCancelled($order));

        $this->assertSame(0, $this->sentCount('cancel_shipment'));
    }

    private function fakeApi(): void
    {
        Http::fake([
            self::BASE_URL.'/api/mitra/v6.1/addresses*' => Http::response(self::addressesResponse()),
            self::BASE_URL.'/api/mitra/v2/schedules' => Http::response($this->schedules()),
            self::BASE_URL.'/api/mitra/v6.1/request_pickup' => function (Request $request) {
                return Http::response($this->pickupResponse((string) $request->data()['packages'][0]['order_id']));
            },
            self::BASE_URL.'/api/mitra/tracking' => function (Request $request) {
                return Http::response(self::trackingResponse((string) $request->data()['order_id'], null, 101));
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedules(): array
    {
        return [
            'status' => true,
            'method' => 'schedules',
            'text' => 'Success',
            'schedules' => [
                ['clock' => '2026-11-19 07:00:00', 'until' => '09:00', 'expired' => now()->subHour()->getTimestamp(), 'libur' => false],
                ['clock' => '2026-11-19 12:00:00', 'until' => '14:00', 'expired' => now()->addHours(2)->getTimestamp(), 'libur' => true],
                ['clock' => '2026-11-19 14:00:00', 'until' => '16:00', 'expired' => now()->addHours(4)->getTimestamp(), 'libur' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pickupResponse(string $orderId): array
    {
        return [
            'status' => true,
            'text' => 'Request pickup berhasil',
            'method' => 'request_pickup',
            'qr_url' => 'https://pay.example.test/qr',
            'payment_status' => 'unpaid',
            'details' => [['order_id' => $orderId, 'kj_order_id' => $orderId, 'awb' => null, 'service' => 'JNE', 'service_type' => 'REG23']],
            'pickup_number' => 'EPR-123456789',
        ];
    }

    private function expectedOrderId(OrderShipment $shipment): string
    {
        return 'OM'.$shipment->id.'-'.strtoupper(base_convert((string) $shipment->created_at->getTimestamp(), 10, 36));
    }

    private function sentCount(string $path): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $path))->count();
    }
}
