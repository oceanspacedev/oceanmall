<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderShipmentCreated;
use Shopper\Core\Events\Orders\OrderShipmentDelivered;
use Shopper\Core\Events\Orders\OrderShipped;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Tests\TestCase;

/**
 * Generic code routes a shipment to the driver that owns it, and provider
 * shipments mirrored into Shopper fire Shopper's shipment events.
 */
final class FulfillmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipments_are_routed_by_their_own_driver(): void
    {
        $this->requireModules('Komerce');

        $capabilities = resolve(ShippingCapabilities::class);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        // Keyless: no shipment has a provider.
        foreach (['komerce', 'rajaongkir', 'manual', null] as $driver) {
            $this->assertNull($capabilities->fulfillerFor($this->shipment($order, $driver)));
        }

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');

        $this->assertSame('komerce', $capabilities->fulfillerFor($this->shipment($order, 'komerce'))?->code());
        // Cost quotes are fulfilled by Komerce Delivery (DelegatesFulfillment).
        $this->assertSame('komerce', $capabilities->fulfillerFor($this->shipment($order, 'rajaongkir'))?->code());
        // Legacy rows without a driver go to the single configured fulfiller.
        $this->assertSame('komerce', $capabilities->fulfillerFor($this->shipment($order, null))?->code());
        // Shopper zone options stay with staff even next to a provider.
        $this->assertNull($capabilities->fulfillerFor($this->shipment($order, 'manual')));
        $this->assertNull($capabilities->fulfillerFor($this->shipment($order, 'unknown-driver')));
    }

    public function test_order_fulfiller_comes_from_its_shipments(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        $capabilities = resolve(ShippingCapabilities::class);

        $manualOrder = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $this->shipment($manualOrder, 'manual');
        $this->assertNull($capabilities->fulfillerForOrder($manualOrder));

        $providerOrder = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $this->shipment($providerOrder, 'komerce');
        $this->assertSame('komerce', $capabilities->fulfillerForOrder($providerOrder)?->code());
    }

    public function test_mirroring_provider_shipments_fires_shoppers_shipment_events_once(): void
    {
        Event::fake([OrderShipmentCreated::class, OrderShipped::class, OrderShipmentDelivered::class]);
        Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'is_enabled' => true]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            // The factory picks a random shipping status; a freshly paid order is unfulfilled.
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $shipment = $this->shipment($order, 'komerce');
        $sync = resolve(SyncOrderShippingFromShipments::class);

        $shipment->forceFill(['awb' => 'JNE-EVT-1', 'tracking_number' => 'JNE-EVT-1', 'status' => 'labeled'])->save();
        $sync->handle($order->refresh());
        $sync->handle($order->refresh());

        Event::assertDispatchedTimes(OrderShipmentCreated::class, 1);
        Event::assertDispatchedTimes(OrderShipped::class, 1);
        Event::assertNotDispatched(OrderShipmentDelivered::class);
        $this->assertSame(ShippingStatus::Shipped, $order->refresh()->shipping_status);

        $shipment->forceFill(['status' => 'delivered'])->save();
        $sync->handle($order->refresh());
        $sync->handle($order->refresh());

        Event::assertDispatchedTimes(OrderShipmentDelivered::class, 1);
        Event::assertDispatchedTimes(OrderShipmentCreated::class, 1);
        Event::assertDispatched(
            OrderShipmentDelivered::class,
            fn (OrderShipmentDelivered $event): bool => $event->shipment->tracking_number === 'JNE-EVT-1'
                && $event->shipment->received_at !== null,
        );
    }

    private function shipment(Order $order, ?string $driver): OrderShipment
    {
        return OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create([
                'shipping_origins' => ['komerce' => '17248'],
            ])->id,
            'driver' => $driver,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);
    }
}
