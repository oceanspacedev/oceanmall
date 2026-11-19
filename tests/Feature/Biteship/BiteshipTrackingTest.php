<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Actions\Shipping\RefreshShipmentTracking;
use App\Integrations\Biteship\Exceptions\BiteshipApiException;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Shipping\Facades\Shipping;

final class BiteshipTrackingTest extends BiteshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBiteship();
    }

    /**
     * @return array<string, mixed>
     */
    private function tracking(string $status = 'dropping_off'): array
    {
        return [
            'success' => true,
            'object' => 'tracking',
            'id' => 'BS-TRACK-1',
            'waybill_id' => 'JNE-WB-001',
            'courier' => ['company' => 'jne'],
            'status' => $status,
            'history' => [
                ['note' => 'Order confirmed', 'status' => 'confirmed', 'updated_at' => '2026-11-19T09:00:00+07:00'],
                ['note' => 'Picked up by courier', 'status' => 'picked', 'updated_at' => '2026-11-19T12:00:00+07:00'],
                ['note' => 'Out for delivery', 'status' => $status, 'updated_at' => '2026-11-20T08:00:00+07:00'],
            ],
        ];
    }

    /**
     * @return array{0: mixed, 1: OrderShipment}
     */
    private function registeredShipment(string $status = 'labeled'): array
    {
        return $this->paidShipment([
            'awb' => 'JNE-WB-001',
            'tracking_number' => 'JNE-WB-001',
            'external_reference' => 'BS-ORDER-1',
            'status' => $status,
            'metadata' => [
                'rate' => ['courier_company' => 'jne', 'courier_type' => 'reg'],
                'biteship' => ['order_id' => 'BS-ORDER-1', 'tracking_id' => 'BS-TRACK-1'],
            ],
        ]);
    }

    public function test_refresh_tracking_reads_the_biteship_tracking_and_stores_a_neutral_timeline(): void
    {
        [, $shipment] = $this->registeredShipment();
        Http::fake([self::BASE.'/v1/trackings/BS-TRACK-1' => Http::response($this->tracking())]);

        $shipment = resolve(RefreshShipmentTracking::class)->handle($shipment);

        $this->assertSame('in_transit', $shipment->status);
        $history = ShipmentMetadata::trackingHistory($shipment);
        $this->assertCount(3, $history);
        $this->assertSame('Out for delivery', $history[0]['description'], 'Newest first');
        $this->assertSame('dropping_off', data_get($shipment->metadata, 'biteship.tracking_status'));
    }

    public function test_delivered_tracking_marks_the_shipment_delivered(): void
    {
        [, $shipment] = $this->registeredShipment('in_transit');
        Http::fake([self::BASE.'/v1/trackings/BS-TRACK-1' => Http::response($this->tracking('delivered'))]);

        $info = Shipping::driver('biteship')->trackShipment($shipment);

        $this->assertSame('delivered', $info->status);
        $this->assertNotNull($info->deliveredAt);
        $this->assertSame('delivered', resolve(RefreshShipmentTracking::class)->handle($shipment->refresh())->status);
    }

    public function test_a_hand_entered_awb_is_tracked_through_public_tracking(): void
    {
        // Staff shipped a manual / zone-option parcel and typed the AWB.
        [, $shipment] = $this->paidShipment([
            'driver' => 'manual',
            'carrier_code' => 'sicepat',
            'awb' => 'SCP-123',
            'tracking_number' => 'SCP-123',
            'metadata' => null,
        ]);
        Http::fake([self::BASE.'/v1/trackings/SCP-123/couriers/sicepat' => Http::response([...$this->tracking('picked'), 'waybill_id' => 'SCP-123'])]);

        $shipment = resolve(RefreshShipmentTracking::class)->handle($shipment);

        $this->assertSame('picked_up', $shipment->status);
        $this->assertNotEmpty(ShipmentMetadata::trackingHistory($shipment));
    }

    public function test_refresh_tracking_command_reconciles_then_tracks_biteship_shipments(): void
    {
        [, $shipment] = $this->registeredShipment();
        Http::fake([
            self::BASE.'/v1/orders/BS-ORDER-1' => Http::response($this->biteshipOrder('picked', 'JNE-WB-002')),
            self::BASE.'/v1/trackings/BS-TRACK-1' => Http::response([...$this->tracking('picked'), 'waybill_id' => 'JNE-WB-002']),
        ]);

        $this->artisan('shipping:refresh-tracking')->assertSuccessful();

        $shipment->refresh();
        $this->assertSame('JNE-WB-002', $shipment->awb, 'A courier switch (order.waybill_id) is picked up');
        $this->assertSame('picked_up', $shipment->status);
    }

    public function test_reconcile_never_moves_a_status_backwards(): void
    {
        [, $shipment] = $this->registeredShipment('in_transit');
        Http::fake([self::BASE.'/v1/orders/BS-ORDER-1' => Http::response($this->biteshipOrder('allocated'))]);

        $this->assertSame('in_transit', Shipping::driver('biteship')->reconcileShipment($shipment)->status);
    }

    public function test_cancelling_a_registered_shipment_cancels_the_biteship_order_once(): void
    {
        [, $shipment] = $this->registeredShipment();
        Http::fake([self::BASE.'/v1/orders/BS-ORDER-1/cancel' => Http::response(['success' => true, 'id' => 'BS-ORDER-1', 'status' => 'cancelled'])]);

        $driver = Shipping::driver('biteship');
        $driver->cancelShipment($shipment);
        $driver->cancelShipment($shipment->refresh());

        $this->assertSame('cancelled', $shipment->refresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['cancellation_reason_code'] === 'others');
    }

    public function test_a_refused_cancellation_keeps_the_parcel_status(): void
    {
        [, $shipment] = $this->registeredShipment('picked_up');
        Http::fake([self::BASE.'/v1/orders/BS-ORDER-1/cancel' => Http::response(['success' => false, 'error' => 'Order cannot be cancelled'], 400)]);

        try {
            Shipping::driver('biteship')->cancelShipment($shipment);
            $this->fail('A refused cancellation must surface');
        } catch (BiteshipApiException) {
        }

        $shipment->refresh();
        $this->assertSame('picked_up', $shipment->status);
        $this->assertStringContainsString('cannot be cancelled', (string) data_get($shipment->metadata, 'biteship.cancel_error'));
    }

    public function test_cancelling_an_unregistered_shipment_is_a_no_op(): void
    {
        [, $shipment] = $this->paidShipment();
        Http::fake();

        Shipping::driver('biteship')->cancelShipment($shipment);

        Http::assertNothingSent();
        $this->assertSame('pending', $shipment->refresh()->status);
    }
}
