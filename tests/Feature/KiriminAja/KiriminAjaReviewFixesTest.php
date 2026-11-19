<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\KiriminAja\Services\KiriminAjaClient;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Integrations\KiriminAja\Support\KiriminAjaCouriers;
use App\Integrations\KiriminAja\Support\KiriminAjaStatus;
use App\Listeners\CancelShipmentsOnOrderCancelled;
use App\Shipping\ShipmentMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Country;
use Shopper\Shipping\DataTransferObjects\Package;
use Tests\TestCase;

/**
 * Behaviour pinned down by the independent review of the module: error
 * envelopes on tracking, the docs' status code table, phone validation,
 * courier / insurance defaults on quotes, cancellation limits, concurrent
 * fulfilment and Tracking API use while an AWB is pending.
 */
final class KiriminAjaReviewFixesTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureKiriminAja();
        Http::preventStrayRequests();
    }

    public function test_a_rejected_key_on_tracking_is_an_error_not_an_unknown_parcel(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(['status' => false, 'text' => 'Unauthenticated'], 401)]);

        $this->expectExceptionMessage('Unauthenticated');

        resolve(KiriminAjaClient::class)->tracking('OM1-TEST');
    }

    public function test_an_unknown_parcel_is_still_an_answer(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(['status' => false, 'text' => 'Data tidak ditemukan'])]);

        $this->assertFalse(resolve(KiriminAjaClient::class)->tracking('OM1-TEST')['status']);
    }

    public function test_a_callback_whose_verification_is_refused_is_retried_not_acknowledged(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::sequence()
            ->push(['status' => false, 'text' => 'Unauthenticated'], 401)
            ->push(self::trackingResponse('OM1-TEST', 'JNE0001', 105)),
        ]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);
        $payload = ['method' => 'processed_packages', 'data' => [['order_id' => 'OM1-TEST', 'awb' => 'JNE0001']]];

        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $payload)->assertStatus(500);
        $this->assertNull($shipment->refresh()->awb);

        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $payload)->assertOk();
        $this->assertSame('JNE0001', $shipment->refresh()->awb);
    }

    public function test_a_callback_never_matches_another_drivers_shipment(): void
    {
        Http::fake();
        [, $shipment] = $this->paidShipment([
            'driver' => 'rajaongkir',
            'metadata' => ['kiriminaja' => ['order_id' => 'OM1-TEST']],
        ]);

        $this->withToken(self::API_KEY)
            ->postJson('/webhooks/kiriminaja', ['method' => 'processed_packages', 'data' => [['order_id' => 'OM1-TEST', 'awb' => 'X']]])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertNull($shipment->refresh()->awb);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tracking'));
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: bool, 3: string}>
     */
    public static function statusCodes(): array
    {
        return [
            'new, no awb' => [100, false, false, NormalizeShipmentStatus::PENDING],
            'waiting for 3pl with awb' => [105, true, false, NormalizeShipmentStatus::LABELED],
            'pending direct pickup' => [110, true, false, NormalizeShipmentStatus::LABELED],
            'picked up by ka driver' => [103, true, false, NormalizeShipmentStatus::PICKED_UP],
            'with courier' => [106, true, true, NormalizeShipmentStatus::IN_TRANSIT],
            're-delivery' => [152, true, true, NormalizeShipmentStatus::IN_TRANSIT],
            'delivered' => [200, true, true, NormalizeShipmentStatus::DELIVERED],
            'cancelled by client' => [300, true, false, NormalizeShipmentStatus::CANCELLED],
            'pickup cancelled by seller' => [302, true, false, NormalizeShipmentStatus::CANCELLED],
            'recipient refused' => [301, true, true, KiriminAjaStatus::RETURNED],
            'cancellation requested' => [350, true, false, KiriminAjaStatus::PROBLEM],
            'returned' => [400, true, true, KiriminAjaStatus::RETURNED],
            'return requested' => [403, true, true, KiriminAjaStatus::RETURNED],
            'problem' => [500, true, true, KiriminAjaStatus::PROBLEM],
            'lost' => [701, true, true, KiriminAjaStatus::PROBLEM],
            'courier issue' => [903, true, true, KiriminAjaStatus::PROBLEM],
        ];
    }

    #[DataProvider('statusCodes')]
    public function test_status_codes_follow_the_docs_table(int $code, bool $hasAwb, bool $shipped, string $expected): void
    {
        $this->assertSame($expected, KiriminAjaStatus::stable($code, hasAwb: $hasAwb, shipped: $shipped));
    }

    public function test_a_returned_parcel_keeps_the_shipment_status(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse('OM1-TEST', 'JNE0001', 403, shippedAt: '2026-11-19 17:44:04'))]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST', 'awb' => 'JNE0001', 'status' => NormalizeShipmentStatus::IN_TRANSIT]);

        $shipment = resolve(KiriminAjaDriver::class)->reconcileShipment($shipment);

        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $shipment->status);
    }

    public function test_a_phone_outside_kiriminajas_patterns_is_refused_before_any_order_call(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v2/schedules' => Http::response(['status' => true, 'schedules' => []])]);
        [$order, $shipment] = $this->paidShipment();
        $order->shippingAddress()->update(['phone' => '0812345']);

        try {
            resolve(KiriminAjaDriver::class)->fulfillShipment($shipment);
            $this->fail('A too-short phone must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Recipient phone', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'request_pickup'));
        $this->assertStringContainsString('Recipient phone', (string) ShipmentMetadata::fulfillmentError($shipment->refresh()));
    }

    public function test_without_owned_carriers_only_the_default_couriers_are_quoted(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/shipping_price' => Http::response(self::priceResponse())]);
        [, $country] = $this->indonesiaZone();
        $this->warehouse();

        $this->rates($country, 150000);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'shipping_price')
            && $request->data()['courier'] === array_keys(KiriminAjaCouriers::DEFAULTS));
    }

    public function test_insurance_is_not_requested_without_an_item_value(): void
    {
        config()->set('kiriminaja.insurance', true);
        Http::fake([self::BASE_URL.'/api/mitra/v6.1/shipping_price' => Http::response(self::priceResponse())]);
        [$zone, $country] = $this->indonesiaZone();
        $this->kiriminAjaCarrier('jne', $zone);
        $this->warehouse();

        $rates = $this->rates($country, 0);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'shipping_price')
            && $request->data()['insurance'] === 0
            && ! array_key_exists('item_value', $request->data()));
        $this->assertSame(12000, $rates[0]['amount']);
    }

    public function test_a_parcel_already_with_the_courier_is_not_cancelled_through_the_api(): void
    {
        Http::fake();
        [$order, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST', 'awb' => 'JNE0001', 'status' => NormalizeShipmentStatus::IN_TRANSIT]);

        resolve(CancelShipmentsOnOrderCancelled::class)->handle(new OrderCancelled($order));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cancel_shipment'));
        $this->assertStringContainsString('courier', (string) data_get($shipment->refresh()->metadata, 'kiriminaja.cancel_note'));
    }

    public function test_a_shipment_being_registered_elsewhere_is_left_alone(): void
    {
        Http::fake();
        [, $shipment] = $this->paidShipment();
        $lock = Cache::lock('kiriminaja:fulfill:'.$shipment->id, 120);
        $this->assertTrue($lock->get());

        try {
            resolve(KiriminAjaDriver::class)->fulfillShipment($shipment);
        } finally {
            $lock->release();
        }

        Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), 'addresses'));
        $this->assertNull(ShipmentMetadata::fulfillmentError($shipment->refresh()));
    }

    public function test_a_registered_shipment_awaiting_its_awb_is_tracked_at_most_every_15_minutes(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse('OM1-TEST', null, 101))]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);
        $driver = resolve(KiriminAjaDriver::class);

        $driver->fulfillShipment($shipment);
        $driver->fulfillShipment($shipment);
        $this->travel(16)->minutes();
        $driver->fulfillShipment($shipment);

        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'tracking'))->count());
    }

    public function test_a_pickup_request_is_never_resent_after_a_transport_failure(): void
    {
        $attempts = 0;
        Http::fake([
            self::BASE_URL.'/api/mitra/v2/schedules' => Http::response(['status' => true, 'schedules' => [
                ['clock' => '2030-01-01 14:00:00', 'expired' => now()->addDay()->getTimestamp(), 'libur' => false],
            ]]),
            self::BASE_URL.'/api/mitra/v6.1/request_pickup' => function () use (&$attempts): never {
                $attempts++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            self::BASE_URL.'/api/mitra/tracking' => Http::response(['status' => false, 'text' => 'Data tidak ditemukan']),
        ]);
        [, $shipment] = $this->paidShipment();

        try {
            resolve(KiriminAjaDriver::class)->fulfillShipment($shipment);
            $this->fail('The failure must be reported for a later, idempotent retry.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $attempts);
        $this->assertNotNull(data_get($shipment->refresh()->metadata, 'kiriminaja.attempted_at'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rates(Country $country, int $itemValue): array
    {
        return resolve(FetchDeliveryRates::class)->handle([
            'first_name' => 'Budi',
            'street_address' => 'Jl. Kesambi Raya 5',
            'postal_code' => '45134',
            'city' => 'Kota Cirebon',
            'country_id' => $country->id,
            'destination_id' => '1535:22001',
            'destination_driver' => KiriminAjaDriver::CODE,
        ], [new Package(10, 10, 10, 0.5)], itemValue: $itemValue);
    }
}
