<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use App\Addons\Store\OceanMallStoreAddon;
use App\Integrations\Komerce\KomerceAddon;
use App\Integrations\Komerce\Livewire\KomerceOrderShipping;
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
use Shopper\Facades\Shopper;
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

    public function test_keyless_order_detail_shows_store_cards_without_komerce_ui(): void
    {
        $this->requireModules('Komerce');

        $admin = $this->admin();
        $order = $this->orderWithShipment();

        $this->actingAs($admin)
            ->get(route('shopper.orders.detail', $order))
            ->assertOk()
            ->assertDontSee('Pengiriman RajaOngkir / Komerce', false)
            ->assertDontSee('Integrasi Komerce belum aktif', false)
            ->assertDontSee('Sinkronkan Bayar', false)
            ->assertSeeLivewire(OrderFulfillment::class)
            ->assertSeeLivewire(OrderSummary::class)
            ->assertSeeLivewire(OrderCustomer::class);

        $this->assertFalse((new KomerceAddon)->isEnabled(), 'No Komerce key: the komerce addon stays off.');
        $this->assertTrue(Shopper::hasAddon(OceanMallStoreAddon::ID));
    }

    public function test_order_detail_shows_komerce_panel_for_komerce_fulfilled_orders(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        $this->registerKomerceAddon();
        $admin = $this->admin();
        $order = $this->orderWithShipment();

        $this->assertSame('komerce', Payment::driver('komerce')->code());

        $this->actingAs($admin)
            ->get(route('shopper.orders.detail', $order))
            ->assertOk()
            ->assertSee('Pengiriman RajaOngkir / Komerce', false)
            ->assertSee('QRIS Komerce', false)
            ->assertDontSee('Integrasi Komerce belum aktif', false)
            ->assertSeeLivewire(OrderFulfillment::class)
            ->assertSeeLivewire(OrderSummary::class)
            ->assertSeeLivewire(OrderCustomer::class);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Gudang Jakarta');
    }

    public function test_komerce_panel_skips_orders_shipped_with_a_manual_carrier(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        $this->registerKomerceAddon();
        $admin = $this->admin();
        $order = $this->orderWithShipment(['driver' => 'manual']);

        $this->actingAs($admin)
            ->get(route('shopper.orders.detail', $order))
            ->assertOk()
            ->assertDontSee('Pengiriman RajaOngkir / Komerce', false);
    }

    public function test_komerce_panel_shows_on_the_starter_plan_for_rajaongkir_quoted_orders(): void
    {
        $this->requireModules('Komerce');

        // Production today: Shipping Cost only, no Shipping Delivery API.
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        $this->registerKomerceAddon();
        $admin = $this->admin();
        $order = $this->orderWithShipment(['driver' => 'rajaongkir', 'awb' => 'JNE-MANUAL-1']);

        $this->assertTrue(KomerceAddon::rendersFor($order));

        $this->actingAs($admin)
            ->get(route('shopper.orders.detail', $order))
            ->assertOk()
            ->assertSee('Pengiriman RajaOngkir / Komerce', false)
            // Delivery-only actions stay hidden without the Delivery API.
            ->assertDontSee('wire:click="processAllDeliveryOrders"', false);
    }

    public function test_order_customer_keeps_shoppers_shipping_address_without_a_provider_panel(): void
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
            ->assertSee(__('shopper::pages/customers.addresses.shipping'), false)
            ->assertSee('Jl. Merdeka 1', false);
    }

    public function test_order_customer_hides_shipping_address_and_identical_billing_under_the_komerce_panel(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        $this->registerKomerceAddon();
        $admin = $this->admin();
        $address = $this->orderAddress('Jl. Merdeka 1');
        $order = $this->orderWithShipment([], [
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

    public function test_order_customer_shows_billing_address_when_it_differs_under_the_komerce_panel(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        $this->registerKomerceAddon();
        $admin = $this->admin();
        $order = $this->orderWithShipment([], [
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

    /**
     * @param  array<string, mixed>  $shipment
     * @param  array<string, mixed>  $order
     */
    private function orderWithShipment(array $shipment = [], array $order = []): Order
    {
        $paymentMethod = PaymentMethod::factory()->create([
            'title' => 'QRIS Komerce',
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        $model = Order::factory()->create([
            'currency_code' => 'IDR',
            'payment_method_id' => $paymentMethod->id,
            ...$order,
        ]);
        $inventory = Inventory::factory()->create(['name' => 'Gudang Jakarta']);
        OrderShipment::query()->create([
            'order_id' => $model->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            ...$shipment,
        ]);

        return $model;
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
