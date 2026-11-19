<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Models\OrderShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\OrderShippingEvent;
use Tests\TestCase;

/**
 * A store without a shipping provider ships with Shopper's own cpanel flow
 * ("Buat Label Pengiriman" + "Tambah acara"). The AWB and status staff record
 * there must reach the warehouse shipment the customer page, the mobile API
 * and "Pesanan diterima" read (regression: the customer saw "Label menunggu"
 * and could not confirm receipt).
 */
final class ManualShopperShippingMirrorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Order, 2: OrderShipment}
     */
    private function paidManualOrder(string $driver = 'manual', ?int $carrierId = null): array
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'currency_code' => 'IDR',
        ]);
        $shipment = $this->shipment($order, $driver, $carrierId);

        return [$customer, $order, $shipment];
    }

    private function shipment(Order $order, string $driver = 'manual', ?int $carrierId = null): OrderShipment
    {
        return OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => $driver,
            'carrier_id' => $carrierId,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::PENDING,
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);
    }

    /**
     * What Shopper's CreateShippingLabel (manual carrier) saves.
     */
    private function label(Order $order, string $awb, ?int $carrierId = null): OrderShipping
    {
        return OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $carrierId,
            'tracking_number' => $awb,
            'status' => ShipmentStatus::Pending,
        ]);
    }

    public function test_a_manual_label_puts_the_awb_on_the_customer_shipment(): void
    {
        [$customer, $order, $shipment] = $this->paidManualOrder();

        $shipping = $this->label($order, 'KTOKO-0001');
        OrderShippingEvent::query()->create([
            'order_shipping_id' => $shipping->id,
            'status' => ShipmentStatus::Pending,
            'description' => 'Shipping label created',
            'occurred_at' => now()->subMinutes(5),
        ]);

        $shipment->refresh();
        $this->assertSame('KTOKO-0001', $shipment->awb);
        $this->assertSame('KTOKO-0001', $shipment->tracking_number);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $shipment->status);

        $this->withoutVite();
        $this->actingAs($customer)
            ->get(route('account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shipments.0.awb', 'KTOKO-0001')
                ->where('shipments.0.status', NormalizeShipmentStatus::LABELED)
                ->where('shipments.0.tracking_history.0.description', 'Shipping label created'));

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/orders/'.$order->number)
            ->assertOk()
            ->assertJsonPath('data.shipments.0.awb', 'KTOKO-0001');
    }

    public function test_staff_events_move_the_shipment_forward_only(): void
    {
        [, $order, $shipment] = $this->paidManualOrder();
        $shipping = $this->label($order, 'KTOKO-0002');

        $shipping->forceFill(['status' => ShipmentStatus::PickedUp])->save();
        $this->assertSame(NormalizeShipmentStatus::PICKED_UP, $shipment->refresh()->status);

        $shipping->forceFill(['status' => ShipmentStatus::OutForDelivery])->save();
        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $shipment->refresh()->status);

        // A failed attempt or an older status never moves it back.
        $shipping->forceFill(['status' => ShipmentStatus::DeliveryFailed])->save();
        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $shipment->refresh()->status);
        $shipping->forceFill(['status' => ShipmentStatus::Pending])->save();
        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $shipment->refresh()->status);

        $shipping->forceFill(['status' => ShipmentStatus::Delivered])->save();
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $shipment->refresh()->status);
    }

    public function test_the_customer_can_confirm_receipt_of_a_manual_parcel(): void
    {
        [$customer, $order, $shipment] = $this->paidManualOrder();
        $this->label($order, 'KTOKO-0003');

        $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.confirm-received', $order))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('account.orders.show', $order));

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $shipment->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame(1, OrderShipping::query()->where('order_id', $order->id)->count(), 'The sync reuses the staff label, no second Shopper shipment.');
    }

    public function test_an_existing_different_awb_is_never_overwritten(): void
    {
        [, $order, $shipment] = $this->paidManualOrder();
        $shipment->forceFill(['awb' => 'TYPED-BY-STAFF', 'tracking_number' => 'TYPED-BY-STAFF'])->save();

        $this->label($order, 'OTHER-AWB');

        $shipment->refresh();
        $this->assertSame('TYPED-BY-STAFF', $shipment->awb);
        $this->assertSame(NormalizeShipmentStatus::PENDING, $shipment->status);
    }

    public function test_several_open_shipments_are_matched_by_carrier_or_left_alone(): void
    {
        $carrierA = Carrier::query()->create(['name' => 'Kurir A', 'slug' => 'kurir-a', 'driver' => 'manual', 'is_enabled' => true]);
        $carrierB = Carrier::query()->create(['name' => 'Kurir B', 'slug' => 'kurir-b', 'driver' => 'manual', 'is_enabled' => true]);
        [, $order, $first] = $this->paidManualOrder('manual', (int) $carrierA->id);
        $second = $this->shipment($order, 'manual', (int) $carrierB->id);

        $this->label($order, 'AWB-B', (int) $carrierB->id);
        $this->assertNull($first->refresh()->awb);
        $this->assertSame('AWB-B', $second->refresh()->awb);

        // Two open parcels and nothing to tell them apart: do not guess.
        $third = $this->shipment($order, 'manual', (int) $carrierA->id);
        $this->label($order, 'AWB-?');
        $this->assertNull($first->refresh()->awb);
        $this->assertNull($third->refresh()->awb);
    }

    public function test_the_forward_sync_is_not_mirrored_back(): void
    {
        [, $order, $shipment] = $this->paidManualOrder();
        $shipment->forceFill(['awb' => 'HAND-1', 'status' => NormalizeShipmentStatus::PICKED_UP])->save();

        // The sync records picked_up as Shopper's InTransit; that must not
        // move the warehouse shipment to in_transit.
        resolve(SyncOrderShippingFromShipments::class)->handle($order);

        $this->assertSame(ShipmentStatus::InTransit, OrderShipping::query()->where('order_id', $order->id)->sole()->status);
        $this->assertSame(NormalizeShipmentStatus::PICKED_UP, $shipment->refresh()->status);

        // A later staff event is mirrored again.
        OrderShipping::query()->where('order_id', $order->id)->sole()->forceFill(['status' => ShipmentStatus::Delivered])->save();
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $shipment->refresh()->status);
    }

    public function test_the_backfill_migration_mirrors_labels_recorded_before_the_fix(): void
    {
        [, $order, $shipment] = $this->paidManualOrder();

        // A label + pickup recorded before the observer existed.
        OrderShipping::withoutEvents(fn () => $this->label($order, 'OLD-AWB')->forceFill(['status' => ShipmentStatus::PickedUp])->save());
        $this->assertNull($shipment->refresh()->awb);

        $migration = require database_path('migrations/2026_11_19_000100_mirror_manual_shopper_shipping_to_order_shipments.php');
        $migration->up();
        $migration->up();

        $shipment->refresh();
        $this->assertSame('OLD-AWB', $shipment->awb);
        $this->assertSame(NormalizeShipmentStatus::PICKED_UP, $shipment->status);
    }

    public function test_provider_fulfilled_shipments_are_left_to_their_provider(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        [, $order, $shipment] = $this->paidManualOrder('komerce');

        $this->label($order, 'TYPED-IN-CPANEL')->forceFill(['status' => ShipmentStatus::Delivered])->save();

        $shipment->refresh();
        $this->assertNull($shipment->awb);
        $this->assertSame(NormalizeShipmentStatus::PENDING, $shipment->status);
    }
}
