<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Actions\Shipping\RefreshShipmentTracking;
use App\Integrations\Biteship\Exceptions\BiteshipApiException;
use App\Integrations\Biteship\Services\BiteshipFulfillment;
use App\Integrations\Biteship\Support\BiteshipShipments;
use App\Models\OrderShipment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Shopper\Shipping\Facades\Shipping;

/**
 * Regression tests for the independent review of the Biteship module:
 * another provider's order number is never treated as a Biteship order, and
 * a lagging tracking answer never moves a shipment backwards.
 */
final class BiteshipReviewTest extends BiteshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBiteship();
    }

    /**
     * A shipment Komerce registered (legacy driver null, Komerce order no in
     * external_reference) that generic code hands to Biteship.
     *
     * @return array{0: mixed, 1: OrderShipment}
     */
    private function foreignShipment(): array
    {
        return $this->paidShipment([
            'driver' => null,
            'carrier_code' => 'jne',
            'external_reference' => 'KOM-ORDER-77',
            'awb' => 'JNE-KOM-1',
            'tracking_number' => 'JNE-KOM-1',
            'status' => 'labeled',
            'metadata' => ['komerce' => ['order_no' => 'KOM-ORDER-77']],
        ]);
    }

    public function test_the_api_key_is_sent_raw_in_the_authorization_header(): void
    {
        Http::fake([self::BASE.'/v1/couriers' => Http::response(['success' => true, 'couriers' => []])]);

        Shipping::driver('biteship')->syncCarriers(true);

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['biteship_test.eyJhbGciOiJIUzI1NiJ9.test']);
    }

    public function test_another_providers_order_number_is_not_a_biteship_order(): void
    {
        [, $shipment] = $this->foreignShipment();

        $this->assertNull(BiteshipShipments::orderId($shipment));
        $this->assertTrue(BiteshipShipments::registeredElsewhere($shipment));
    }

    public function test_a_foreign_shipment_is_tracked_by_waybill_never_by_order_id(): void
    {
        [, $shipment] = $this->foreignShipment();
        Http::fake([
            self::BASE.'/v1/trackings/JNE-KOM-1/couriers/jne' => Http::response([
                'success' => true,
                'waybill_id' => 'JNE-KOM-1',
                'status' => 'in_transit',
                'history' => [['note' => 'In transit', 'status' => 'in_transit', 'updated_at' => '2026-11-19T12:00:00+07:00']],
            ]),
        ]);

        $info = Shipping::driver('biteship')->trackShipment($shipment);

        $this->assertSame('in_transit', $info->status);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'KOM-ORDER-77'));
    }

    public function test_a_foreign_shipment_is_never_cancelled_at_biteship(): void
    {
        [, $shipment] = $this->foreignShipment();
        Http::fake();

        Shipping::driver('biteship')->cancelShipment($shipment);

        Http::assertNothingSent();
        $this->assertSame('labeled', $shipment->refresh()->status);
    }

    public function test_a_foreign_shipment_is_never_reconciled_against_biteship(): void
    {
        [, $shipment] = $this->foreignShipment();
        Http::fake();

        Shipping::driver('biteship')->reconcileShipment($shipment);

        Http::assertNothingSent();
    }

    public function test_fulfilment_refuses_a_shipment_registered_with_another_provider(): void
    {
        [, $shipment] = $this->foreignShipment();
        $shipment->forceFill(['awb' => null, 'tracking_number' => null])->save();
        Http::fake();

        try {
            resolve(BiteshipFulfillment::class)->fulfill($shipment);
            $this->fail('A parcel another provider holds must not be booked again.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('another provider', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_lagging_tracking_answer_never_moves_the_status_backwards(): void
    {
        [, $shipment] = $this->paidShipment([
            'awb' => 'JNE-WB-001',
            'tracking_number' => 'JNE-WB-001',
            'external_reference' => 'BS-ORDER-1',
            'status' => 'in_transit',
            'metadata' => ['biteship' => ['order_id' => 'BS-ORDER-1', 'tracking_id' => 'BS-TRACK-1']],
        ]);
        Http::fake([self::BASE.'/v1/trackings/BS-TRACK-1' => Http::response([
            'success' => true,
            'id' => 'BS-TRACK-1',
            'waybill_id' => 'JNE-WB-001',
            'status' => 'allocated',
            'history' => [['note' => 'Allocated', 'status' => 'allocated', 'updated_at' => '2026-11-19T09:00:00+07:00']],
        ])]);

        $this->assertSame('in_transit', resolve(RefreshShipmentTracking::class)->handle($shipment)->status);
    }

    public function test_nothing_leaves_a_cancellation_through_tracking(): void
    {
        [, $shipment] = $this->paidShipment([
            'awb' => 'JNE-WB-001',
            'tracking_number' => 'JNE-WB-001',
            'external_reference' => 'BS-ORDER-1',
            'status' => 'cancelled',
            'metadata' => ['biteship' => ['order_id' => 'BS-ORDER-1', 'tracking_id' => 'BS-TRACK-1']],
        ]);
        Http::fake([self::BASE.'/v1/trackings/BS-TRACK-1' => Http::response([
            'success' => true,
            'id' => 'BS-TRACK-1',
            'waybill_id' => 'JNE-WB-001',
            'status' => 'picked',
            'history' => [],
        ])]);

        $this->assertSame('cancelled', resolve(RefreshShipmentTracking::class)->handle($shipment)->status);
    }

    public function test_an_adopted_order_after_reference_id_reuse_is_reconciled_from_biteship(): void
    {
        [, $shipment] = $this->paidShipment(['driver' => null]);
        Http::fake([
            self::BASE.'/v1/orders' => Http::response([
                'success' => false,
                'error' => 'Reference id already used',
                'code' => BiteshipApiException::REFERENCE_ID_USED,
                'details' => ['order_id' => 'BS-ORDER-1'],
            ], 400),
            self::BASE.'/v1/orders/BS-ORDER-1' => Http::response($this->biteshipOrder('allocated', 'JNE-WB-009')),
        ]);

        resolve(BiteshipFulfillment::class)->fulfill($shipment);

        $shipment->refresh();
        $this->assertSame('BS-ORDER-1', $shipment->external_reference);
        $this->assertSame('JNE-WB-009', $shipment->awb);
        $this->assertSame('biteship', $shipment->driver);
        $this->assertSame('BS-ORDER-1', BiteshipShipments::orderId($shipment));
        Http::assertSentCount(2);
    }

    public function test_a_test_key_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse(Shipping::driver('biteship')->isConfigured());

        config(['biteship.allow_test_key_in_production' => true]);
        $this->assertTrue(Shipping::driver('biteship')->isConfigured());
    }
}
