<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Actions\Shipping\DispatchShipmentFulfillment;
use App\Jobs\FulfillOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Shopper\Shipping\Facades\Shipping;

final class BiteshipFulfillmentTest extends BiteshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBiteship();
    }

    public function test_paid_shipment_is_registered_with_biteship_and_gets_its_waybill(): void
    {
        [$order, $shipment] = $this->paidShipment();
        Http::fake([self::BASE.'/v1/orders' => Http::response($this->biteshipOrder('confirmed', 'JNE-WB-001'))]);

        (new FulfillOrderShipment($shipment->id))->handle(resolve(ShippingCapabilities::class));

        $shipment->refresh();
        $this->assertSame('BS-ORDER-1', $shipment->external_reference);
        $this->assertSame('JNE-WB-001', $shipment->awb);
        $this->assertSame('JNE-WB-001', $shipment->tracking_number);
        $this->assertSame('labeled', $shipment->status);
        $this->assertSame('BS-TRACK-1', data_get($shipment->metadata, 'biteship.tracking_id'));
        $this->assertTrue(ShipmentMetadata::pickupRequested($shipment));
        $this->assertNull(ShipmentMetadata::fulfillmentError($shipment));
        $this->assertSame('Order confirmed', ShipmentMetadata::trackingHistory($shipment)[0]['description']);

        Http::assertSent(function (Request $request) use ($order, $shipment): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE.'/v1/orders'
                && $request['courier_company'] === 'jne'
                && $request['courier_type'] === 'reg'
                && $request['delivery_type'] === 'now'
                && $request['origin_area_id'] === 'IDNP9IDNC74IDND6IDZ45111'
                && $request['destination_area_id'] === 'IDNP6IDNC147IDND830IDZ10110'
                && $request['origin_contact_name'] === 'Gudang Cirebon'
                && $request['origin_contact_phone'] === '02311234567'
                && $request['destination_contact_name'] === 'Budi Santoso'
                && $request['destination_contact_phone'] === '081234567890'
                && $request['reference_id'] === $order->number.'-S'.$shipment->id
                && ! isset($request['destination_cash_on_delivery'])
                && $request['items'] === [[
                    'name' => 'Kopi Cirebon',
                    'sku' => 'KOPI-CIREBON-500',
                    'category' => 'others',
                    'value' => 50000,
                    'quantity' => 2,
                    'weight' => 500,
                    'length' => 20,
                    'width' => 12,
                    'height' => 8,
                ]];
        });
    }

    public function test_paid_order_dispatch_reaches_biteship_through_the_generic_pipeline(): void
    {
        [$order] = $this->paidShipment();
        Http::fake([self::BASE.'/v1/orders' => Http::response($this->biteshipOrder())]);

        resolve(DispatchShipmentFulfillment::class)->handle($order);

        $this->assertSame('JNE-WB-001', OrderShipment::query()->where('order_id', $order->id)->value('awb'));
    }

    public function test_fulfill_paid_command_registers_missing_waybills(): void
    {
        [, $shipment] = $this->paidShipment();
        Http::fake([self::BASE.'/v1/orders' => Http::response($this->biteshipOrder())]);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame('JNE-WB-001', $shipment->refresh()->awb);
    }

    public function test_an_order_without_waybill_yet_is_kept_pending_and_never_registered_twice(): void
    {
        [, $shipment] = $this->paidShipment();
        Http::fake([
            self::BASE.'/v1/orders' => Http::response($this->biteshipOrder('confirmed', null)),
            self::BASE.'/v1/orders/BS-ORDER-1' => Http::response($this->biteshipOrder('allocated', null)),
        ]);

        $driver = Shipping::driver('biteship');
        $driver->fulfillShipment($shipment);
        $driver->fulfillShipment($shipment->refresh());

        $shipment->refresh();
        $this->assertSame('BS-ORDER-1', $shipment->external_reference);
        $this->assertNull($shipment->awb);
        $this->assertSame('labeled', $shipment->status);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/v1/orders/BS-ORDER-1'));
    }

    public function test_a_reused_reference_id_adopts_the_order_an_earlier_attempt_created(): void
    {
        [, $shipment] = $this->paidShipment();
        Http::fake([
            self::BASE.'/v1/orders' => Http::response([
                'success' => false,
                'error' => 'Reference id has already been used before. Please input other reference id',
                'code' => 40002060,
                'details' => ['order_id' => 'BS-ORDER-1', 'waybill_id' => 'JNE-WB-001'],
            ], 400),
            self::BASE.'/v1/orders/BS-ORDER-1' => Http::response($this->biteshipOrder('allocated', 'JNE-WB-001')),
        ]);

        Shipping::driver('biteship')->fulfillShipment($shipment);

        $shipment->refresh();
        $this->assertSame('BS-ORDER-1', $shipment->external_reference);
        $this->assertSame('JNE-WB-001', $shipment->awb);
        $this->assertNull(ShipmentMetadata::fulfillmentError($shipment));
    }

    public function test_a_provider_error_is_recorded_and_rethrown_for_the_queue_retry(): void
    {
        [, $shipment] = $this->paidShipment();
        Http::fake([self::BASE.'/v1/orders' => Http::sequence()
            ->push(['success' => false, 'error' => 'Insufficient balance', 'code' => 40000001], 400)
            ->push($this->biteshipOrder()),
        ]);

        try {
            Shipping::driver('biteship')->fulfillShipment($shipment);
            $this->fail('A failed registration must throw so the job retries.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
        }

        $shipment->refresh();
        $this->assertStringContainsString('Insufficient balance', (string) ShipmentMetadata::fulfillmentError($shipment));
        $this->assertNull($shipment->external_reference);

        // The next attempt succeeds and clears the error.
        Shipping::driver('biteship')->fulfillShipment($shipment);

        $this->assertNull(ShipmentMetadata::fulfillmentError($shipment->refresh()));
    }

    public function test_missing_receiver_phone_fails_before_calling_biteship(): void
    {
        [$order, $shipment] = $this->paidShipment();
        $order->shippingAddress()->update(['phone' => null]);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Receiver phone');

        try {
            Shipping::driver('biteship')->fulfillShipment($shipment);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_scheduled_delivery_sends_a_pickup_slot(): void
    {
        config()->set('biteship.delivery_type', 'scheduled');
        config()->set('biteship.pickup_time', '10:00');
        [, $shipment] = $this->paidShipment();
        Http::fake([self::BASE.'/v1/orders' => Http::response($this->biteshipOrder())]);

        Shipping::driver('biteship')->fulfillShipment($shipment);

        Http::assertSent(fn (Request $request): bool => $request['delivery_type'] === 'scheduled'
            && $request['delivery_time'] === '10:00'
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request['delivery_date']) === 1);
    }

    public function test_shipments_with_an_awb_are_left_alone(): void
    {
        [, $shipment] = $this->paidShipment(['awb' => 'HAND-1', 'tracking_number' => 'HAND-1']);
        Http::fake();

        Shipping::driver('biteship')->fulfillShipment($shipment);

        Http::assertNothingSent();
    }

    public function test_a_price_different_from_the_quote_is_flagged_but_not_charged(): void
    {
        [, $shipment] = $this->paidShipment();
        Http::fake([self::BASE.'/v1/orders' => Http::response($this->biteshipOrder('confirmed', 'JNE-WB-001', ['price' => 21000]))]);

        Shipping::driver('biteship')->fulfillShipment($shipment);

        $shipment->refresh();
        $this->assertSame(18000, (int) $shipment->cost);
        $this->assertSame(21000, data_get($shipment->metadata, 'biteship.price'));
        $this->assertTrue(data_get($shipment->metadata, 'biteship.price_differs_from_cost'));
    }
}
