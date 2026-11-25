<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Integrations\Komerce\Services\KomerceDeliveryFulfillment;
use App\Jobs\FulfillOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShipmentNotFulfillable;
use App\Shipping\ShippingCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Shopper\Shipping\Facades\Shipping;
use Tests\Support\FulfilmentSafetyFixtures;
use Tests\TestCase;

/**
 * Go-live QA "fulfilment-safety" (shared fixtures: Tests\Support\FulfilmentSafetyFixtures).
 *
 * Komerce Delivery has no provider-side idempotency: a Store Order / pickup
 * is never re-sent blindly, overlapping runs book a shipment once, and
 * closed or historic orders are never booked.
 */
final class KomerceFulfilmentSafetyTest extends TestCase
{
    use FulfilmentSafetyFixtures;
    use RefreshDatabase;

    private const DELIVERY = 'https://delivery.example.test';

    private int $storeCalls = 0;

    /** @var (callable(int): void)|null */
    private $duringStore = null;

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

    // ---- Komerce Store Order / pickup are never re-sent blindly --------------

    public function test_store_order_is_not_resent_by_the_http_client_after_a_read_timeout(): void
    {
        $this->configureKomerce();
        [, $shipment] = $this->komerceShipment();

        Http::fake([
            self::DELIVERY.'/order/api/v1/orders/store' => Http::sequence()
                ->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')
                ->push($this->storeOk('RO-2')),
            self::DELIVERY.'/order/api/v1/pickup/request' => Http::response($this->pickupOk('RO-2', 'AWB-2')),
            self::DELIVERY.'/order/api/v1/orders/print-label*' => Http::response($this->labelOk()),
        ]);

        $this->runJobExpectingFailure($shipment);

        $this->assertSame(1, $this->sent('/orders/store'), 'A store order that may exist at Komerce must not be POSTed again.');
        $shipment->refresh();
        $this->assertNull($shipment->awb);
        $this->assertNotNull(data_get($shipment->metadata, 'komerce.'.KomerceDeliveryFulfillment::STORE_ORDER_PENDING));
        $this->assertStringContainsString('dashboard Komerce', (string) ShipmentMetadata::fulfillmentError($shipment));

        // Neither the queued retry nor the 5-minute sweep re-POSTs it.
        $this->runJobExpectingFailure($shipment);
        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame(1, $this->sent('/orders/store'));
        $this->assertNull($shipment->refresh()->awb);
    }

    public function test_store_order_server_error_is_held_for_reconciliation(): void
    {
        $this->configureKomerce();
        [, $shipment] = $this->komerceShipment();

        Http::fake([
            self::DELIVERY.'/order/api/v1/orders/store' => Http::sequence()
                ->push(['meta' => ['status' => 'error', 'code' => 502, 'message' => 'Bad Gateway']], 502)
                ->push($this->storeOk('RO-2')),
        ]);

        $this->runJobExpectingFailure($shipment);
        $this->runJobExpectingFailure($shipment);

        $this->assertSame(1, $this->sent('/orders/store'));
    }

    public function test_store_order_connect_failure_is_retried_because_it_was_never_sent(): void
    {
        $this->configureKomerce();
        [, $shipment] = $this->komerceShipment();

        Http::fake([
            self::DELIVERY.'/order/api/v1/orders/store' => Http::sequence()
                ->pushFailedConnection('cURL error 7: Failed to connect to delivery.example.test port 443: Connection refused')
                ->push($this->storeOk('RO-1')),
            self::DELIVERY.'/order/api/v1/pickup/request' => Http::response($this->pickupOk('RO-1', 'AWB-1')),
            self::DELIVERY.'/order/api/v1/orders/print-label*' => Http::response($this->labelOk()),
        ]);

        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(2, $this->sent('/orders/store'));
        $shipment->refresh();
        $this->assertSame('AWB-1', $shipment->awb);
        $this->assertNull(data_get($shipment->metadata, 'komerce.'.KomerceDeliveryFulfillment::STORE_ORDER_PENDING));
    }

    public function test_a_definite_store_order_rejection_can_be_retried(): void
    {
        $this->configureKomerce();
        [, $shipment] = $this->komerceShipment();

        Http::fake([
            self::DELIVERY.'/order/api/v1/orders/store' => Http::sequence()
                ->push(['meta' => ['status' => 'error', 'code' => 422, 'message' => 'Please fill correct payment method']], 422)
                ->push($this->storeOk('RO-1')),
            self::DELIVERY.'/order/api/v1/pickup/request' => Http::response($this->pickupOk('RO-1', 'AWB-1')),
            self::DELIVERY.'/order/api/v1/orders/print-label*' => Http::response($this->labelOk()),
        ]);

        $this->runJobExpectingFailure($shipment);
        $this->assertNull(data_get($shipment->refresh()->metadata, 'komerce.'.KomerceDeliveryFulfillment::STORE_ORDER_PENDING));

        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(2, $this->sent('/orders/store'));
        $this->assertSame('AWB-1', $shipment->refresh()->awb);
    }

    public function test_pickup_request_is_not_resent_by_the_http_client_after_a_read_timeout(): void
    {
        $this->configureKomerce();
        [, $shipment] = $this->komerceShipment();

        Http::fake([
            self::DELIVERY.'/order/api/v1/orders/store' => Http::response($this->storeOk('RO-1')),
            self::DELIVERY.'/order/api/v1/pickup/request' => Http::sequence()
                ->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')
                ->push($this->pickupOk('RO-1', 'AWB-1')),
            self::DELIVERY.'/order/api/v1/orders/print-label*' => Http::response($this->labelOk()),
        ]);

        $this->runJobExpectingFailure($shipment);

        $this->assertSame(1, $this->sent('/pickup/request'));
        $this->assertSame('RO-1', data_get($shipment->refresh()->metadata, 'komerce.order_no'));
    }

    public function test_delivery_reads_still_retry_on_any_connection_failure(): void
    {
        $this->configureKomerce();
        $shipment = $this->komerceShipment()[1];
        $shipment->forceFill(['metadata' => [...$shipment->metadata, 'komerce' => ['order_id' => '7', 'order_no' => 'RO-7']]])->save();

        Http::fake([
            self::DELIVERY.'/order/api/v1/orders/detail*' => Http::sequence()
                ->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')
                ->push(['meta' => ['status' => 'success'], 'data' => ['order_no' => 'RO-7', 'order_status' => 'Diajukan', 'awb' => null]]),
            self::DELIVERY.'/order/api/v1/pickup/request' => Http::response($this->pickupOk('RO-7', 'AWB-7')),
            self::DELIVERY.'/order/api/v1/orders/print-label*' => Http::response($this->labelOk()),
        ]);

        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(2, $this->sent('/orders/detail'));
        $this->assertSame('AWB-7', $shipment->refresh()->awb);
    }

    // ---- Overlapping runs book a shipment once -------------------------------

    public function test_overlapping_sweep_runs_store_one_komerce_order(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [, $shipment] = $this->komerceShipment();

        $this->duringStore = function (int $n): void {
            if ($n === 1) {
                Artisan::call('shipping:fulfill-paid'); // a second, overlapping cron run
            }
        };

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame(1, $this->storeCalls);
        $this->assertSame(1, $this->sent('/pickup/request'));
        $this->assertSame('AWB-RO-1', $shipment->refresh()->awb);
    }

    public function test_queued_job_during_the_sweep_stores_one_komerce_order(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [, $shipment] = $this->komerceShipment();

        $this->duringStore = function (int $n) use ($shipment): void {
            if ($n === 1) {
                (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));
            }
        };

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame(1, $this->storeCalls);
        $this->assertSame('AWB-RO-1', $shipment->refresh()->awb);
    }

    public function test_cpanel_driver_call_during_the_job_stores_one_komerce_order(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [, $shipment] = $this->komerceShipment();

        // The cpanel "Proses" button calls the driver directly, bypassing the
        // neutral gate: the Komerce service's own lock still holds.
        $this->duringStore = function (int $n) use ($shipment): void {
            if ($n === 1) {
                Shipping::driver('komerce')->fulfillShipment(OrderShipment::query()->findOrFail($shipment->id));
            }
        };

        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(1, $this->storeCalls);
        $this->assertSame('AWB-RO-1', $shipment->refresh()->awb);
    }

    public function test_a_shipment_locked_by_another_process_is_left_alone(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [, $shipment] = $this->komerceShipment();

        $lock = Cache::lock(ShipmentFulfillment::lockKey((int) $shipment->id), 60);
        $this->assertTrue($lock->get());

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(0, $this->storeCalls);
        $lock->release();

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        $this->assertSame(1, $this->storeCalls);
    }

    // ---- Closed / historic orders are never booked ---------------------------

    public function test_completed_delivered_historic_order_is_not_booked_by_the_sweep(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        $this->komerceShipment([
            'status' => OrderStatus::Completed,
            'shipping_status' => ShippingStatus::Delivered,
            'created_at' => now()->subDays(90),
        ]);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame(0, $this->storeCalls, 'A completed / delivered order must not get a new courier pickup.');
    }

    public function test_completed_order_is_not_booked_by_a_queued_retry_or_the_cpanel(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [, $shipment] = $this->komerceShipment(['status' => OrderStatus::Completed]);

        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        try {
            Shipping::driver('komerce')->fulfillShipment($shipment);
            $this->fail('A completed order must not be booked from the cpanel either.');
        } catch (ShipmentNotFulfillable) {
        }

        $this->assertSame(0, $this->storeCalls);
        $this->assertNull(ShipmentMetadata::fulfillmentError($shipment->refresh()));
    }

    public function test_sweep_leaves_orders_older_than_max_age_to_staff(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [, $shipment] = $this->komerceShipment();
        $shipment->forceFill(['created_at' => now()->subDays(45)])->save();

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        $this->assertSame(0, $this->storeCalls);

        $this->artisan('shipping:fulfill-paid', ['--max-age' => 0])->assertSuccessful();
        $this->assertSame(1, $this->storeCalls);
        $this->assertSame('AWB-RO-1', $shipment->refresh()->awb);
    }

    public function test_delivered_shipment_without_awb_is_not_booked_again(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [, $shipment] = $this->komerceShipment();
        $shipment->forceFill(['status' => 'delivered'])->save();

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(0, $this->storeCalls);
    }

    public function test_order_cancelled_during_the_store_order_round_trip_gets_no_pickup(): void
    {
        $this->configureKomerce();
        $this->fakeKomerceDelivery();
        [$order, $shipment] = $this->komerceShipment();

        $this->duringStore = static function () use ($order): void {
            Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Cancelled]);
        };

        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        $this->assertSame(1, $this->storeCalls);
        $this->assertSame(0, $this->sent('/pickup/request'), 'The pickup is re-checked right before it is requested.');
        $this->assertNull($shipment->refresh()->awb);
    }

    public function test_store_order_on_a_real_refused_connection_is_retried_and_not_held(): void
    {
        $this->configureKomerce();
        // Port 1 on loopback refuses the TCP connect: the request never leaves.
        config()->set('komerce.rajaongkir.delivery_base_url', 'http://127.0.0.1:1');
        config()->set('komerce.timeout', 2);
        Http::allowStrayRequests();
        [, $shipment] = $this->komerceShipment();

        $failedAttempts = 0;
        EventFacade::listen(ConnectionFailed::class, static function () use (&$failedAttempts): void {
            $failedAttempts++;
        });

        $this->runJobExpectingFailure($shipment);

        $this->assertGreaterThan(1, $failedAttempts, 'A Store Order that never left the server is retried by the client.');
        $shipment->refresh();
        $this->assertNull(data_get($shipment->metadata, 'komerce.'.KomerceDeliveryFulfillment::STORE_ORDER_PENDING), 'A refused connection must not hold the shipment for reconciliation.');
        $this->assertStringNotContainsString('dashboard Komerce', (string) ShipmentMetadata::fulfillmentError($shipment));
    }

    // ---- Fixtures --------------------------------------------------------------

    private function configureKomerce(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', self::DELIVERY);
        config()->set('komerce.pickup_time', '10:00:00');
        config()->set('komerce.pickup_vehicle', 'Motor');
        config()->set('shopper.shipping.drivers.komerce.enabled', true);
    }

    private function fakeKomerceDelivery(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/order/api/v1/orders/store')) {
                $n = ++$this->storeCalls;

                if ($this->duringStore !== null) {
                    ($this->duringStore)($n);
                }

                return Http::response($this->storeOk('RO-'.$n));
            }

            if (str_contains($url, '/order/api/v1/pickup/request')) {
                $orderNo = (string) data_get($request->data(), 'orders.0.order_no');

                return Http::response($this->pickupOk($orderNo, 'AWB-'.$orderNo));
            }

            if (str_contains($url, '/order/api/v1/orders/print-label')) {
                return Http::response($this->labelOk());
            }

            return Http::response(['meta' => ['code' => 404, 'status' => 'error', 'message' => 'unexpected '.$url]], 404);
        });
    }

    /**
     * A paid, open order with one Komerce Delivery shipment (the shape of
     * tests/Feature/Komerce/ShippingDeliveryTest).
     *
     * @param  array<string, mixed>  $orderOverrides
     * @return array{0: Order, 1: OrderShipment}
     */
    private function komerceShipment(array $orderOverrides = []): array
    {
        [$order, $shipment] = $this->paidOrderShipment(
            [
                'driver' => 'komerce',
                'carrier_code' => 'jne',
                'carrier_name' => 'JNE',
                'service_code' => 'REG',
                'service_name' => 'Reguler',
                'cost' => 18000,
                'metadata' => ['rate' => [
                    'provider' => 'shipping_delivery', 'shipping_name' => 'JNE', 'service_name' => 'REG23',
                    'shipping_cost' => 18000, 'shipping_cashback' => 4500, 'service_fee' => 0, 'additional_cost' => 0,
                    'grandtotal' => 118000, 'cod_value' => 0, 'insurance_value' => 0,
                ]],
            ],
            [
                'status' => OrderStatus::Processing,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'metadata' => json_encode([
                    'komerce' => ['payment_type' => 'bank_transfer'],
                    'shipping_address' => ['country_id' => 1, 'rajaongkir_destination_id' => '152'],
                ], JSON_THROW_ON_ERROR),
                ...$orderOverrides,
            ],
        );

        $order->shippingAddress?->forceFill(['phone' => '081234567890'])->save();
        resolve(InventoryOrigins::class)->put((int) $shipment->inventory_id, 'rajaongkir', '501', 'Cirebon');

        return [$order->refresh(), $shipment->refresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeOk(string $orderNo): array
    {
        return ['meta' => ['message' => 'Success Create New Order', 'code' => 201, 'status' => 'success'], 'data' => ['order_id' => 1, 'order_no' => $orderNo]];
    }

    /**
     * @return array<string, mixed>
     */
    private function pickupOk(string $orderNo, string $awb): array
    {
        return ['meta' => ['message' => 'Success Request Pickup', 'code' => 201, 'status' => 'success'], 'data' => [['status' => 'success', 'order_no' => $orderNo, 'awb' => $awb]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function labelOk(): array
    {
        return ['meta' => ['code' => 200, 'status' => 'success'], 'data' => ['path' => self::DELIVERY.'/label.pdf']];
    }
}
