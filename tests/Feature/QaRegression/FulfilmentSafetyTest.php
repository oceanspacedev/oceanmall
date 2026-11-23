<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Integrations\Komerce\Services\KomerceDeliveryFulfillment;
use App\Jobs\FulfillOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\InventoryOrigins;
use App\Shipping\ProviderRequestFailure;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShipmentNotFulfillable;
use App\Shipping\ShippingCapabilities;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Shopper\Shipping\Facades\Shipping;
use Tests\Feature\KiriminAja\InteractsWithKiriminAja;
use Tests\TestCase;
use Throwable;

/**
 * Go-live QA "fulfilment-safety": a shipment is booked with its shipping
 * provider at most once, and never for an order that is cancelled, completed,
 * already delivered or historic. Covers Komerce Delivery (no provider-side
 * idempotency), Biteship and KiriminAja through the neutral job, the
 * shipping:fulfill-paid sweep and direct (staff) driver calls.
 */
final class FulfilmentSafetyTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    private const DELIVERY = 'https://delivery.example.test';

    private const BITESHIP = 'https://api.biteship.com';

    private int $storeCalls = 0;

    /** @var (callable(int): void)|null */
    private $duringStore = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'Asia/Jakarta'));

        // Hermetic: no provider is configured unless a test plugs it in.
        config()->set('komerce.shipping_delivery_api_key', '');
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('kiriminaja.api_key', '');
        config()->set('biteship.api_key', '');
        config()->set('biteship.webhook_secret', '');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

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

    public function test_lifecycle_schedules_never_overlap_and_run_on_one_server(): void
    {
        $events = collect(resolve(Schedule::class)->events());

        foreach (['shipping:fulfill-paid', 'shipping:refresh-tracking', 'shipping:sync-carriers', 'orders:expire-unpaid'] as $command) {
            $event = $events->first(static fn (Event $event): bool => str_contains((string) $event->command, $command));

            $this->assertInstanceOf(Event::class, $event, "{$command} is scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap itself");
            $this->assertTrue($event->onOneServer, "{$command} must run on one server");
            $this->assertLessThan(1440, $event->expiresAt, "{$command} overlap mutex must not block the schedule for a day");
        }
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

    public function test_cancelled_paid_order_is_never_booked_with_biteship(): void
    {
        $this->configureBiteship();
        [$order, $shipment] = $this->biteshipShipment();
        Http::fake([self::BITESHIP.'/v1/orders' => Http::response($this->biteshipOrder())]);

        resolve(CancelOrderWithProviders::class)->handle($order);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        try {
            Shipping::driver('biteship')->fulfillShipment($shipment);
            $this->fail('A cancelled order must not be booked from a direct driver call either.');
        } catch (ShipmentNotFulfillable) {
        }

        $this->assertSame(0, $this->sent(self::BITESHIP.'/v1/orders'), 'A cancelled order must never book a Biteship pickup.');
        $this->assertNull($shipment->refresh()->awb);
    }

    public function test_open_paid_biteship_order_is_still_booked(): void
    {
        $this->configureBiteship();
        [, $shipment] = $this->biteshipShipment();
        Http::fake([self::BITESHIP.'/v1/orders' => Http::response($this->biteshipOrder())]);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame(1, $this->sent(self::BITESHIP.'/v1/orders'));
        $this->assertSame('JNE-WB-001', $shipment->refresh()->awb);
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

    // ---- Transport failure classification ------------------------------------

    public function test_only_unsent_writes_are_retryable(): void
    {
        $readTimeout = $this->connectionFailure('POST', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
        $emptyReply = $this->connectionFailure('POST', 'cURL error 52: Empty reply from server');
        $refused = $this->connectionFailure('POST', 'cURL error 7: Failed to connect to host port 443: Connection refused');
        $dns = $this->connectionFailure('POST', 'cURL error 6: Could not resolve host: delivery.example.test');
        $connectTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 5001 milliseconds');
        $sentThenTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 5001 milliseconds', ['errno' => 28, 'connect_time' => 0.12]);

        $this->assertFalse(ProviderRequestFailure::retryable($readTimeout, 'POST'));
        $this->assertFalse(ProviderRequestFailure::retryable($emptyReply, 'POST'));
        $this->assertFalse(ProviderRequestFailure::retryable($sentThenTimeout, 'POST'));
        $this->assertTrue(ProviderRequestFailure::retryable($refused, 'POST'));
        $this->assertTrue(ProviderRequestFailure::retryable($dns, null));
        $this->assertTrue(ProviderRequestFailure::retryable($connectTimeout, 'POST'));
        $this->assertTrue(ProviderRequestFailure::retryable($readTimeout, 'GET'));
        $this->assertFalse(ProviderRequestFailure::retryable(new RuntimeException('boom'), 'GET'));
    }

    public function test_real_curl_contexts_are_classified_by_bytes_sent_not_pretransfer_time(): void
    {
        // Handler contexts as real cURL reports them: pretransfer_time is
        // filled with the elapsed time even when nothing was sent.
        $refused = $this->connectionFailure('POST', 'cURL error 7: Failed to connect to 127.0.0.1:1 after 0 ms: Could not connect to server', [
            'errno' => 7, 'connect_time' => 0.0, 'pretransfer_time' => 0.00048, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $dns = $this->connectionFailure('POST', 'cURL error 6: Could not resolve host: delivery.example.test', [
            'errno' => 6, 'connect_time' => 0.0, 'pretransfer_time' => 0.021829, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $connectTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 2005 milliseconds', [
            'errno' => 28, 'connect_time' => 0.0, 'pretransfer_time' => 2.006182, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $tlsHandshakeTimeout = $this->connectionFailure('POST', 'cURL error 28: SSL connection timeout', [
            'errno' => 28, 'connect_time' => 0.0012, 'pretransfer_time' => 0.0, 'request_size' => 0, 'size_upload' => 0.0,
        ]);
        $readTimeout = $this->connectionFailure('POST', 'cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received', [
            'errno' => 28, 'connect_time' => 0.001276, 'pretransfer_time' => 0.001647, 'request_size' => 127, 'size_upload' => 7.0,
        ]);
        $emptyReply = $this->connectionFailure('POST', 'cURL error 52: Empty reply from server', [
            'errno' => 52, 'connect_time' => 0.0011, 'pretransfer_time' => 0.0014, 'request_size' => 127, 'size_upload' => 7.0,
        ]);
        // A "Connection timed out" message never overrides bytes on the wire.
        $sentThenTimeout = $this->connectionFailure('POST', 'cURL error 28: Connection timed out after 5001 milliseconds', [
            'errno' => 28, 'connect_time' => 0.01, 'request_size' => 127,
        ]);

        $this->assertTrue(ProviderRequestFailure::notSent($refused));
        $this->assertTrue(ProviderRequestFailure::notSent($dns));
        $this->assertTrue(ProviderRequestFailure::notSent($connectTimeout));
        $this->assertTrue(ProviderRequestFailure::notSent($tlsHandshakeTimeout));
        $this->assertFalse(ProviderRequestFailure::notSent($readTimeout));
        $this->assertFalse(ProviderRequestFailure::notSent($emptyReply));
        $this->assertFalse(ProviderRequestFailure::notSent($sentThenTimeout));
        $this->assertTrue(ProviderRequestFailure::retryable($refused, 'POST'));
        $this->assertFalse(ProviderRequestFailure::retryable($readTimeout, 'POST'));
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

    private function runJobExpectingFailure(OrderShipment $shipment): void
    {
        $thrown = null;

        try {
            (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The fulfilment attempt was expected to fail (so the queue retries it).');
        $this->assertNotInstanceOf(ShipmentNotFulfillable::class, $thrown);
    }

    private function sent(string $path): int
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $path))->count();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function connectionFailure(string $method, string $message, array $context = []): ConnectionException
    {
        return new ConnectionException($message, 0, new ConnectException($message, new PsrRequest($method, 'https://delivery.example.test'), null, $context));
    }

    private function configureKomerce(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', self::DELIVERY);
        config()->set('komerce.pickup_time', '10:00:00');
        config()->set('komerce.pickup_vehicle', 'Motor');
        config()->set('shopper.shipping.drivers.komerce.enabled', true);
    }

    private function configureBiteship(): void
    {
        config()->set('biteship.api_key', 'biteship_test.eyJhbGciOiJIUzI1NiJ9.test');
        config()->set('biteship.base_url', self::BITESHIP);
        config()->set('biteship.webhook_secret', 'whsec-test-secret');
        config()->set('biteship.allow_test_key_in_production', false);
        config()->set('biteship.allow_live_key_outside_production', false);
        config()->set('biteship.delivery_type', 'now');
        config()->set('shopper.shipping.drivers.biteship.enabled', true);
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

    /**
     * A paid, open order with one Komerce Delivery shipment (the shape of
     * tests/Feature/Komerce/ShippingDeliveryTest).
     *
     * @param  array<string, mixed>  $orderOverrides
     * @return array{0: Order, 1: OrderShipment}
     */
    private function komerceShipment(array $orderOverrides = []): array
    {
        [$order, $shipment] = $this->paidShipment(
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
     * @return array{0: Order, 1: OrderShipment}
     */
    private function biteshipShipment(): array
    {
        [$order, $shipment] = $this->paidShipment(
            [
                'driver' => 'biteship',
                'service_code' => 'jne:reg',
                'service_name' => 'Reguler',
                'cost' => 18000,
                'metadata' => ['rate' => [
                    'service_code' => 'jne:reg', 'carrier_code' => 'jne', 'courier_company' => 'jne',
                    'courier_type' => 'reg', 'provider' => 'biteship', 'amount' => 18000,
                ]],
            ],
            [
                'shipping_status' => ShippingStatus::Unfulfilled,
                'metadata' => json_encode(['shipping_address' => [
                    'country_id' => 1,
                    'destination_id' => 'IDNP6IDNC147IDND830IDZ10110',
                    'destination_driver' => 'biteship',
                    'destination_label' => 'Gambir, Jakarta Pusat',
                ]], JSON_THROW_ON_ERROR),
            ],
        );

        $order->shippingAddress?->forceFill(['phone' => '081234567890'])->save();
        resolve(InventoryOrigins::class)->put((int) $shipment->inventory_id, 'biteship', 'IDNP9IDNC74IDND6IDZ45111', 'Kejaksan, Cirebon');

        return [$order->refresh(), $shipment->refresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function biteshipOrder(): array
    {
        return [
            'success' => true,
            'object' => 'order',
            'id' => 'BS-ORDER-1',
            'status' => 'confirmed',
            'price' => 18000,
            'courier' => [
                'tracking_id' => 'BS-TRACK-1',
                'waybill_id' => 'JNE-WB-001',
                'company' => 'jne',
                'type' => 'reg',
                'link' => 'https://track.example.test/JNE-WB-001',
            ],
        ];
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
