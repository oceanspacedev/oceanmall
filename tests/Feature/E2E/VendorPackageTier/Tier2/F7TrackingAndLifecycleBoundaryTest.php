<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier2;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\StoreFixtureFactory;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F7TrackingAndLifecycleBoundaryTest extends VendorPackageTierTestCase
{
    public function test_can_track_is_false_when_awb_is_blank_on_both_web_and_api(): void
    {
        $this->applyTier('starter');
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => null,
                'tracking_number' => null,
                'status' => 'pending',
            ],
        );

        // API order detail must emit can_track === false when AWB is blank
        $this->apiShowOrder($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.shipments.0.can_track', false);

        // Web order detail Inertia prop must emit can_track === false when AWB is blank
        $this->webShowOrder($customer, $order)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shipments.0.can_track', false)
            );

        // Attempting to track blank AWB returns localized Indonesian error cleanly (never 500 or raw English)
        $trackApi = $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertStatus(422);
        $this->assertStringNotContainsString('airway bill', strtolower((string) $trackApi->json('message')));

        $this->webTrackShipment($customer, $order, $shipment)
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionHasErrors('tracking');
    }

    public function test_tracking_failure_with_existing_history_surfaces_error_preserves_history_and_skips_cooldown(): void
    {
        $this->applyTier('starter')
            ->withCostOutage(500)
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $existingEvent = [
            'description' => 'Prior manifest checkpoint',
            'datetime' => '2026-11-24 08:00',
            'location' => 'Jakarta',
        ];

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-HIST-1',
                'tracking_number' => 'JNE-HIST-1',
                'status' => 'in_transit',
                'metadata' => [
                    'tracking_history' => [$existingEvent],
                    'komerce' => ['tracking_history' => [$existingEvent]],
                ],
            ],
        );

        // Customer API tracking must surface failure (422 Indonesian error), NOT mask it as success
        $apiResponse = $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertStatus(422);
        $this->assertNotEmpty($apiResponse->json('message'));

        // Cooldown cache key must NOT be set on failure so customer can retry
        $this->assertFalse(Cache::has('shipment-track-cooldown:'.$shipment->id));

        // Existing tracking_history must be preserved untouched
        $shipment->refresh();
        $this->assertSame('Prior manifest checkpoint', data_get($shipment->metadata, 'komerce.tracking_history.0.description'));

        // Cpanel KomerceOrderShipping refreshTracking must also surface overrideError instead of false success
        $this->cpanelOrderShipping($admin, $order)
            ->call('refreshTracking')
            ->assertSet('successMessage', null)
            ->assertSet('overrideError', fn ($err): bool => is_string($err) && $err !== '');
    }

    public function test_starter_cost_tracking_respects_already_exhausted_quota_without_http_call(): void
    {
        $this->applyTier('quota_exhausted');
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-QUOTA-TRK',
                'tracking_number' => 'JNE-QUOTA-TRK',
                'status' => 'labeled',
            ],
        );

        $response = $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertStatus(422);

        $this->assertMatchesRegularExpression('/kuota|batas|gagal/i', (string) $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_delivery_webhook_handles_401_and_429_from_detail_order_without_500(): void
    {
        $customer = $this->fixtures->createVerifiedCustomer();
        $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'external_reference' => 'RO-WH-ERR-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-WH-ERR-1']],
            ],
        );

        foreach ([401, 429] as $status) {
            $this->applyTier('komship')
                ->withDeliveryEntitled(false, $status, $status === 401 ? 'Invalid API Key' : 'Too Many Requests')
                ->apply();

            $response = $this->postDeliveryWebhook([
                'order_no' => 'RO-WH-ERR-1',
                'cnote' => 'JNE-ERR-1',
                'status' => 'ON_PROCESS',
            ]);

            $this->assertNotSame(500, $response->getStatusCode(), "Delivery webhook returned HTTP 500 on upstream {$status}");
            $this->assertSame(503, $response->getStatusCode());
        }
    }

    public function test_waybill_mismatch_in_tracking_response_is_rejected_and_does_not_overwrite_history(): void
    {
        $this->applyTier('komship')
            ->withDeliveryDetailResponse([
                'order_no' => 'RO-MISMATCH-1',
                'airway_bill' => 'JNE-ORIGINAL-1',
                'awb' => 'JNE-ORIGINAL-1',
                'last_status' => 'ON_PROCESS',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'WRONG-AWB-999',
                'last_status' => 'DELIVERED',
                'history' => [
                    ['desc' => 'Wrong parcel delivered', 'date' => '2026-11-26 10:00:00', 'code' => '200', 'status' => 'DELIVERED'],
                ],
            ])
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-ORIGINAL-1',
                'tracking_number' => 'JNE-ORIGINAL-1',
                'external_reference' => 'RO-MISMATCH-1',
                'status' => 'labeled',
                'metadata' => ['komerce' => ['order_no' => 'RO-MISMATCH-1']],
            ],
        );

        $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertStatus(422);

        $shipment->refresh();
        $this->assertSame('JNE-ORIGINAL-1', $shipment->awb);
        $this->assertSame('labeled', $shipment->status);
        $this->assertEmpty(data_get($shipment->metadata, 'komerce.tracking_history', []));
    }

    public function test_confirm_received_rejects_partial_multi_warehouse_shipments_unpaid_and_returned_orders(): void
    {
        $this->fixtures = StoreFixtureFactory::seedBaseStore(withManualCarrier: true, withSecondInventory: true);
        $this->applyTier('keyless');
        $customer = $this->fixtures->createVerifiedCustomer();

        // 1. Multi-warehouse order where second shipment still lacks AWB
        ['order' => $splitOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['shipping_status' => ShippingStatus::PartiallyShipped],
            ['awb' => 'AWB-1', 'tracking_number' => 'AWB-1', 'status' => 'shipped'],
        );
        OrderShipment::query()->create([
            'order_id' => $splitOrder->id,
            'inventory_id' => $this->fixtures->secondInventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'awb' => null,
            'tracking_number' => null,
        ]);

        $this->apiConfirmReceived($customer, $splitOrder->number)->assertStatus(422);
        $this->assertSame(OrderStatus::Processing, $splitOrder->fresh()->status);

        // 2. Unpaid non-COD order
        ['order' => $unpaidOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['payment_status' => PaymentStatus::Pending, 'shipping_status' => ShippingStatus::Shipped],
            ['awb' => 'AWB-2', 'tracking_number' => 'AWB-2', 'status' => 'shipped'],
        );
        $this->apiConfirmReceived($customer, $unpaidOrder->number)->assertStatus(422);

        // 3. Returned shipment
        ['order' => $returnedOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['shipping_status' => ShippingStatus::Shipped],
            ['awb' => 'AWB-3', 'tracking_number' => 'AWB-3', 'status' => NormalizeShipmentStatus::RETURNED],
        );
        $this->apiConfirmReceived($customer, $returnedOrder->number)->assertStatus(422);
    }

    public function test_confirm_received_works_for_cod_order_and_non_owner_cannot_track_or_cancel(): void
    {
        $this->applyTier('keyless');
        $owner = $this->fixtures->createVerifiedCustomer();
        $stranger = $this->fixtures->createVerifiedCustomer();

        ['order' => $codOrder, 'shipment' => $codShipment] = $this->fixtures->createOrderWithShipment(
            $owner,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['cod']->id,
                'shipping_status' => ShippingStatus::Shipped,
            ],
            [
                'awb' => 'COD-AWB-1',
                'tracking_number' => 'COD-AWB-1',
                'status' => 'shipped',
            ],
            null,
        );

        // Stranger cannot track or cancel owner's order
        $this->apiTrackShipment($stranger, $codOrder->number, $codShipment->id)->assertStatus(404);
        $this->apiCancelOrder($stranger, $codOrder->number)->assertStatus(404);

        // Owner confirms COD receipt -> shipping_status becomes Delivered, payment_status remains Pending (not prematurely Paid)
        $this->apiConfirmReceived($owner, $codOrder->number)
            ->assertOk()
            ->assertJsonPath('data.shipping_status', ShippingStatus::Delivered->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::Pending->value);

        $codOrder->refresh();
        $this->assertSame(ShippingStatus::Delivered, $codOrder->shipping_status);
        $this->assertSame(PaymentStatus::Pending, $codOrder->payment_status);
    }
}
