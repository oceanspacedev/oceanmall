<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use App\Livewire\Shopper\KomerceOrderShipping;
use App\Livewire\Shopper\OrderCustomer;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\OrderSummary;
use App\Models\OrderShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CpanelOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->configureShopperCpanel();

        $admin = User::factory()->create();
        Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.admin'),
            'guard_name' => 'web',
        ]);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        return $admin;
    }

    private function orderAddress(string $street): OrderAddress
    {
        return OrderAddress::query()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => $street,
            'postal_code' => '10110',
            'city' => 'Jakarta',
            'phone' => '081234567890',
        ]);
    }

    public function test_admin_path_is_not_a_backoffice_route(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create(['currency_code' => 'IDR']);

        $this->actingAs($admin)
            ->get('/admin/orders/'.$order->id)
            ->assertNotFound();
    }

    public function test_cpanel_order_detail_includes_komerce_shipping_panel_for_admin(): void
    {
        $admin = $this->admin();
        $paymentMethod = PaymentMethod::factory()->create([
            'title' => 'QRIS Komerce',
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'payment_method_id' => $paymentMethod->id,
        ]);
        $inventory = Inventory::factory()->create(['name' => 'Gudang Jakarta']);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);

        $this->assertSame('komerce', Payment::driver('komerce')->code());

        $this->actingAs($admin)
            ->get(route('shopper.orders.detail', $order))
            ->assertOk()
            ->assertSee('Pengiriman RajaOngkir / Komerce', false)
            ->assertSee('QRIS Komerce', false)
            ->assertSeeLivewire(OrderFulfillment::class)
            ->assertSeeLivewire(OrderSummary::class)
            ->assertSeeLivewire(OrderCustomer::class);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Gudang Jakarta');
    }

    public function test_order_customer_hides_shipping_address_and_identical_billing(): void
    {
        $admin = $this->admin();
        $address = $this->orderAddress('Jl. Merdeka 1');
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'shipping_address_id' => $address->id,
            'billing_address_id' => $address->id,
        ]);

        Livewire::actingAs($admin)
            ->test(OrderCustomer::class, ['order' => $order])
            ->assertSee(__('shopper::pages/orders.customer_infos'), false)
            ->assertDontSee(__('shopper::pages/customers.addresses.shipping'), false)
            ->assertDontSee(__('shopper::pages/customers.addresses.billing'), false)
            ->assertDontSee(__('shopper::words.same_address'), false)
            ->assertDontSee('Jl. Merdeka 1', false);
    }

    public function test_order_customer_shows_billing_address_when_it_differs(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'shipping_address_id' => $this->orderAddress('Jl. Merdeka 1')->id,
            'billing_address_id' => $this->orderAddress('Jl. Kantor 9')->id,
        ]);

        Livewire::actingAs($admin)
            ->test(OrderCustomer::class, ['order' => $order])
            ->assertDontSee(__('shopper::pages/customers.addresses.shipping'), false)
            ->assertDontSee('Jl. Merdeka 1', false)
            ->assertSee(__('shopper::pages/customers.addresses.billing'), false)
            ->assertSee('Jl. Kantor 9', false);
    }

    public function test_non_admin_cannot_print_fulfillment_label(): void
    {
        $this->configureShopperCpanel();
        $user = User::factory()->create();
        $order = Order::factory()->create();

        $this->actingAs($user)
            ->getJson(route('shopper.orders.fulfillment.print-label', $order))
            ->assertForbidden();
    }
}
