<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier1;

use App\Actions\Shipping\NormalizeShipmentStatus;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F7TrackingAndLifecycleTest extends VendorPackageTierTestCase
{
    public function test_komship_delivery_tracking_updates_history_normalizes_status_and_completes_paid_order(): void
    {
        $this->applyTier('komship')
            ->withDeliveryDetailResponse([
                'order_no' => 'RO-TRK-1',
                'airway_bill' => 'JNE-TRK-1',
                'awb' => 'JNE-TRK-1',
                'last_status' => 'DELIVERED',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'JNE-TRK-1',
                'last_status' => 'DELIVERED',
                'history' => [
                    [
                        'desc' => 'Delivered to recipient Budi',
                        'date' => '2026-11-26 11:00:00',
                        'code' => '200',
                        'status' => 'DELIVERED',
                    ],
                ],
            ])
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-TRK-1',
                'tracking_number' => 'JNE-TRK-1',
                'external_reference' => 'RO-TRK-1',
                'status' => 'labeled',
                'metadata' => ['komerce' => ['order_no' => 'RO-TRK-1']],
            ],
        );

        $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', true)
            ->assertJsonPath('data.shipments.0.status', NormalizeShipmentStatus::DELIVERED)
            ->assertJsonPath('data.shipments.0.tracking_history.0.description', 'Delivered to recipient Budi');

        $order->refresh();
        $this->assertSame(ShippingStatus::Delivered, $order->shipping_status);
        $this->assertSame(OrderStatus::Completed, $order->status);
    }

    public function test_starter_cost_tracking_posts_waybill_on_web_and_persists_manifest_history(): void
    {
        $this->applyTier('starter')
            ->withCostTracking([
                'delivered' => false,
                'summary' => ['waybill_number' => 'JNE-STARTER-1', 'status' => 'ON PROCESS'],
                'delivery_status' => ['status' => 'ON PROCESS'],
                'manifest' => [
                    [
                        'manifest_code' => '100',
                        'manifest_description' => 'Picked up by courier at Cirebon',
                        'manifest_date' => '2026-11-25',
                        'manifest_time' => '14:20',
                        'city_name' => 'Cirebon',
                    ],
                ],
            ])
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-STARTER-1',
                'tracking_number' => 'JNE-STARTER-1',
                'status' => 'labeled',
            ],
        );

        $this->webTrackShipment($customer, $order, $shipment)
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionHasNoErrors();

        $shipment->refresh();
        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $shipment->status);
        $this->assertSame(
            'Picked up by courier at Cirebon',
            data_get($shipment->metadata, 'komerce.tracking_history.0.description'),
        );

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v1/track/waybill')
            && $req->hasHeader('key', 'cost-key')
            && $req->method() === 'POST'
            && ($req->data()['awb'] ?? null) === 'JNE-STARTER-1'
            && ($req->data()['courier'] ?? null) === 'jne');
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/history-airway-bill'));
    }

    public function test_customer_tracking_cooldown_and_terminal_status_prevent_redundant_provider_calls(): void
    {
        $this->applyTier('starter')
            ->withCostTracking([
                'delivered' => false,
                'summary' => ['waybill_number' => 'JNE-COOLDOWN-1', 'status' => 'ON PROCESS'],
                'delivery_status' => ['status' => 'ON PROCESS'],
                'manifest' => [
                    [
                        'manifest_code' => '100',
                        'manifest_description' => 'In transit',
                        'manifest_date' => '2026-11-25',
                        'manifest_time' => '10:00',
                        'city_name' => 'Cirebon',
                    ],
                ],
            ])
            ->apply();
        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-COOLDOWN-1',
                'tracking_number' => 'JNE-COOLDOWN-1',
                'status' => 'labeled',
            ],
        );

        $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', true);

        // Second tap within 15m uses cooldown without calling Cost API
        $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', false);

        Http::assertSentCount(1);

        // Delivered terminal shipment never calls provider even without cooldown
        ['order' => $doneOrder, 'shipment' => $doneShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-DONE-1',
                'tracking_number' => 'JNE-DONE-1',
                'status' => NormalizeShipmentStatus::DELIVERED,
            ],
        );

        $this->apiTrackShipment($customer, $doneOrder->number, $doneShipment->id)
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', false);

        Http::assertSentCount(1);
    }

    public function test_scheduled_refresh_tracking_runs_on_komship_and_is_noop_on_starter(): void
    {
        $customer = $this->fixtures->createVerifiedCustomer();

        // Starter: scheduled refresh is a no-op to preserve daily Cost quota
        $this->applyTier('starter');
        $this->fixtures->createOrderWithShipment($customer, [], [
            'awb' => 'JNE-SCHED-STARTER',
            'tracking_number' => 'JNE-SCHED-STARTER',
            'status' => 'labeled',
        ]);

        $this->artisan('shipping:refresh-tracking')->assertSuccessful();
        Http::assertNothingSent();

        // Komship: scheduled refresh updates active Delivery shipments
        $this->applyTier('komship')
            ->withDeliveryDetailResponse([
                'order_no' => 'RO-SCHED-1',
                'airway_bill' => 'JNE-SCHED-KOMSHIP',
                'awb' => 'JNE-SCHED-KOMSHIP',
                'last_status' => 'DELIVERED',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'JNE-SCHED-KOMSHIP',
                'last_status' => 'DELIVERED',
                'history' => [['desc' => 'Delivered', 'date' => '2026-11-26 12:00:00', 'code' => '200', 'status' => 'DELIVERED']],
            ])
            ->apply();

        ['shipment' => $komshipShipment] = $this->fixtures->createOrderWithShipment($customer, [], [
            'awb' => 'JNE-SCHED-KOMSHIP',
            'tracking_number' => 'JNE-SCHED-KOMSHIP',
            'external_reference' => 'RO-SCHED-1',
            'status' => 'labeled',
            'metadata' => ['komerce' => ['order_no' => 'RO-SCHED-1']],
        ]);

        $this->artisan('shipping:refresh-tracking')->assertSuccessful();

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $komshipShipment->fresh()->status);
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/history-airway-bill')
            && $req->hasHeader('x-api-key', 'delivery-key')
            && ($req->data()['airway_bill'] ?? null) === 'JNE-SCHED-KOMSHIP'
            && ($req->data()['shipping'] ?? null) === 'JNE Express');
    }

    public function test_customer_confirm_received_completes_paid_shipped_order_on_web_and_api(): void
    {
        $this->applyTier('keyless');
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $apiOrder, 'shipment' => $apiShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['shipping_status' => ShippingStatus::Shipped],
            ['awb' => 'MANUAL-AWB-1', 'tracking_number' => 'MANUAL-AWB-1', 'status' => 'shipped'],
        );

        $this->apiConfirmReceived($customer, $apiOrder->number)
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Completed->value)
            ->assertJsonPath('data.shipping_status', ShippingStatus::Delivered->value);

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $apiShipment->fresh()->status);

        ['order' => $webOrder, 'shipment' => $webShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['shipping_status' => ShippingStatus::Shipped],
            ['awb' => 'MANUAL-AWB-2', 'tracking_number' => 'MANUAL-AWB-2', 'status' => 'shipped'],
        );

        $this->webConfirmReceived($customer, $webOrder)
            ->assertRedirect(route('account.orders.show', $webOrder))
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Completed, $webOrder->fresh()->status);
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $webShipment->fresh()->status);
    }

    public function test_customer_cancel_pending_order_cancels_remote_payment_and_restores_stock(): void
    {
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-CANCEL-1', 'PENDING', 112000)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-CANCEL-1']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-CANCEL-1'],
            $product,
        );

        $product->decreaseStock($this->fixtures->inventory->id, 1);
        $this->assertSame(9, $product->fresh()->stockInventory($this->fixtures->inventory->id));

        $this->apiCancelOrder($customer, $order->number, 'Salah pilih metode bayar')
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stockInventory($this->fixtures->inventory->id));

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/user/payment/cancel')
            && $req->hasHeader('x-api-key', 'payment-key')
            && ($req->data()['payment_id'] ?? null) === 'KOMPAY-CANCEL-1');
    }
}
