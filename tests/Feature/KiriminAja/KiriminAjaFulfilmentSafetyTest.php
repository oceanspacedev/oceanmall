<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Jobs\FulfillOrderShipment;
use App\Shipping\ShippingCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Tests\Support\FulfilmentSafetyFixtures;
use Tests\TestCase;

/**
 * Go-live QA "fulfilment-safety" (shared fixtures: Tests\Support\FulfilmentSafetyFixtures).
 *
 * A paid KiriminAja shipment is booked through the neutral job, never for a
 * cancelled order.
 */
final class KiriminAjaFulfilmentSafetyTest extends TestCase
{
    use FulfilmentSafetyFixtures;
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startFulfilmentSafety();
    }

    protected function tearDown(): void
    {
        $this->endFulfilmentSafety();

        parent::tearDown();
    }

    public function test_cancelled_paid_order_is_never_booked_with_kiriminaja(): void
    {
        $this->configureKiriminAja();
        $this->fakeKiriminAja();
        [$order, $shipment] = $this->paidShipment([], ['shipping_status' => ShippingStatus::Unfulfilled]);

        resolve(CancelOrderWithProviders::class)->handle($order);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(0, $this->sent('request_pickup'), 'A courier pickup must never be booked for a cancelled order.');
        $this->assertNull($shipment->refresh()->external_reference);
    }

    public function test_open_paid_kiriminaja_order_is_still_booked(): void
    {
        $this->configureKiriminAja();
        $this->fakeKiriminAja();
        [, $shipment] = $this->paidShipment([], ['shipping_status' => ShippingStatus::Unfulfilled]);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame(1, $this->sent('request_pickup'));
        $this->assertNotNull($shipment->refresh()->external_reference);
    }

    // ---- Fixtures --------------------------------------------------------------

    private function fakeKiriminAja(): void
    {
        Http::fake([
            self::BASE_URL.'/api/mitra/v2/schedules' => Http::response([
                'status' => true,
                'method' => 'schedules',
                'text' => 'Success',
                'schedules' => [
                    ['clock' => '2026-11-19 14:00:00', 'until' => '16:00', 'expired' => now()->addHours(4)->getTimestamp(), 'libur' => false],
                ],
            ]),
            self::BASE_URL.'/api/mitra/v6.1/request_pickup' => static function (Request $request) {
                $orderId = (string) $request->data()['packages'][0]['order_id'];

                return Http::response([
                    'status' => true,
                    'text' => 'Request pickup berhasil',
                    'method' => 'request_pickup',
                    'payment_status' => 'paid',
                    'details' => [['order_id' => $orderId, 'kj_order_id' => $orderId, 'awb' => null, 'service' => 'JNE', 'service_type' => 'REG23']],
                    'pickup_number' => 'EPR-1',
                ]);
            },
            self::BASE_URL.'/api/mitra/tracking' => static fn (Request $request) => Http::response(
                self::trackingResponse((string) $request->data()['order_id'], null, 101),
            ),
        ]);
    }
}
