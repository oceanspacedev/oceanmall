<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\Product;
use Shopper\Livewire\Pages\Order\Index as OrderIndex;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CpanelOrderPriceFormatTest extends TestCase
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

    public function test_cpanel_orders_page_displays_rupiah_price_without_division_by_100(): void
    {
        $admin = $this->admin();

        $product = Product::factory()->create([
            'name' => 'Realme Buds T310',
        ]);

        $order = Order::factory()->create([
            'number' => 'ORD-TEST-000001',
            'currency_code' => 'IDR',
            'price_amount' => 406000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Realme Buds T310',
            'unit_price_amount' => 399000,
            'quantity' => 1,
        ]);

        // Test Livewire table for Order Index
        Livewire::actingAs($admin)
            ->test(OrderIndex::class)
            ->assertSee('ORD-TEST-000001')
            ->assertSee('Rp')
            ->assertSee('399.000')
            ->assertDontSee('IDR 3,990.00')
            ->assertDontSee('3,990.00');

        // Test HTTP GET to /cpanel/orders
        $response = $this->actingAs($admin)->get(route('shopper.orders.index'));
        $response->assertOk();
        $response->assertSee('Rp');
        $response->assertSee('399.000');
        $response->assertDontSee('IDR 3,990.00');
    }

    public function test_cpanel_order_detail_displays_rupiah_prices(): void
    {
        $admin = $this->admin();

        $product = Product::factory()->create([
            'name' => 'Realme Buds T310',
        ]);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'price_amount' => 406000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Realme Buds T310',
            'unit_price_amount' => 399000,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($admin)->get(route('shopper.orders.detail', $order));
        $response->assertOk();
        $response->assertSee('Rp');
        $response->assertSee('399.000');
        $response->assertDontSee('IDR 3,990.00');
    }
}
