<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Livewire\Shopper\KomerceOrderShipping;
use App\Models\OrderShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Tests\TestCase;

final class CourierSimulationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_courier_scan_simulation_transitions_order_to_completed(): void
    {
        $admin = User::factory()->create();
        \Spatie\Permission\Models\Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.admin', 'administrator'),
            'guard_name' => 'web',
        ]);
        $admin->assignRole(config('shopper.admin.roles.admin', 'administrator'));

        $inventory = \Shopper\Core\Models\Inventory::factory()->create([
            'is_default' => true,
            'name' => 'Gudang Utama',
        ]);

        $order = Order::factory()->create([
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jnt',
            'carrier_name' => 'J&T Express',
            'service_name' => 'EZ',
            'status' => NormalizeShipmentStatus::PENDING,
            'cost' => 14000,
        ]);

        // 1. Generate AWB
        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('simulateGenerateAwb', $shipment->id)
            ->assertHasNoErrors();

        $shipment->refresh();
        $this->assertNotNull($shipment->awb);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $shipment->status);

        // 2. Courier Scan 1: Pickup / Diantar
        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('simulateScanPickup', $shipment->id)
            ->assertHasNoErrors();

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(NormalizeShipmentStatus::PICKED_UP, $shipment->status);
        $this->assertSame(ShippingStatus::Shipped, $order->shipping_status);
        $this->assertSame(OrderStatus::Processing, $order->status);

        // 3. Courier Scan 2: Delivered / Diterima Pembeli -> Automagically Completed!
        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('simulateScanDelivered', $shipment->id)
            ->assertHasNoErrors();

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $shipment->status);
        $this->assertSame(ShippingStatus::Delivered, $order->shipping_status);
        $this->assertSame(OrderStatus::Completed, $order->status);
    }
}
