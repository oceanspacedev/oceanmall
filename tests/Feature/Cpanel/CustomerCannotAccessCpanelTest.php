<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use App\Livewire\Shopper\OrderAllocationOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Shopper\Livewire\Pages\Auth\Login;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CustomerCannotAccessCpanelTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function shopperCustomer(): User
    {
        $this->configureShopperCpanel();

        Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.user'),
            'guard_name' => 'web',
        ]);

        $customer = User::factory()->create();
        $customer->assignRole(config('shopper.admin.roles.user'));

        return $customer;
    }

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

    public function test_guest_can_open_cpanel_login(): void
    {
        $this->get(route('shopper.login'))
            ->assertOk();
    }

    public function test_storefront_customer_is_sent_back_to_account_from_cpanel(): void
    {
        $this->configureShopperCpanel();
        $customer = $this->customer();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $this->actingAs($customer)
            ->get(route('shopper.dashboard'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($customer)
            ->get(route('shopper.login'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($customer)
            ->get(route('shopper.orders.detail', $order))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($customer)
            ->get(route('shopper.forbidden'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_shopper_user_role_still_cannot_open_cpanel(): void
    {
        $customer = $this->shopperCustomer();

        $this->assertFalse($customer->canAccessDashboard());

        $this->actingAs($customer)
            ->get(route('shopper.dashboard'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_customer_cannot_print_or_override_via_cpanel_routes(): void
    {
        $this->configureShopperCpanel();
        $customer = $this->customer();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $this->actingAs($customer)
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($customer)
            ->getJson(route('shopper.orders.fulfillment.print-label', $order))
            ->assertForbidden();

        $this->actingAs($customer)
            ->postJson(route('shopper.orders.fulfillment.override-allocation', $order), [
                'moves' => [[
                    'qty' => 1,
                    'from_inventory_id' => 1,
                    'to_inventory_id' => 2,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_customer_credentials_cannot_login_to_cpanel(): void
    {
        $customer = $this->customer();

        try {
            Livewire::test(Login::class)
                ->set('data.email', $customer->email)
                ->set('data.password', 'password')
                ->call('authenticate');

            $this->fail('Customer credentials must not sign in to cpanel.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.email', $e->errors());
        }

        $this->assertGuest();
    }

    public function test_admin_can_open_cpanel_dashboard(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('shopper.dashboard'))
            ->assertOk();
    }

    public function test_admin_credentials_can_login_to_cpanel(): void
    {
        $admin = $this->admin();

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(route('shopper.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_real_browser_session_customer_is_blocked_from_cpanel_login_and_dashboard(): void
    {
        $this->configureShopperCpanel();
        $customer = $this->customer();

        $this->post('/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertRedirect();

        app('auth')->forgetGuards();

        $this->get(route('shopper.login'))
            ->assertRedirect(route('dashboard'));

        app('auth')->forgetGuards();

        $this->get(route('shopper.dashboard'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_read_only_browse_orders_staff_cannot_override_allocation(): void
    {
        $this->configureShopperCpanel();

        Permission::query()->firstOrCreate(['name' => 'access_dashboard', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'browse_orders', 'guard_name' => 'web']);

        $staff = User::factory()->create();
        $staff->givePermissionTo(['access_dashboard', 'browse_orders']);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $this->assertTrue(Gate::forUser($staff)->denies('override-allocation', $order));

        $this->actingAs($staff)
            ->postJson(route('shopper.orders.fulfillment.override-allocation', $order), [
                'moves' => [[
                    'qty' => 1,
                    'from_inventory_id' => 1,
                    'to_inventory_id' => 2,
                ]],
            ])
            ->assertRedirect(route('shopper.forbidden'));

        Livewire::actingAs($staff)
            ->test(OrderAllocationOverride::class, ['order' => $order])
            ->call('applyOverride')
            ->assertForbidden();
    }
}
