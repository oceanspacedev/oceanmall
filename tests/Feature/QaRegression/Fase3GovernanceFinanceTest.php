<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\CreateOrder;
use App\Actions\Warehouse\OverrideAllocation;
use App\CheckoutSession;
use App\DTO\AllocationPlan;
use App\DTO\ShipmentDraft;
use App\Livewire\Shopper\OrderFinanceOpsPanel;
use App\Livewire\Shopper\Pages\FinanceReports;
use App\Models\AllocationOverrideLog;
use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use App\Support\ActiveProvider;
use App\Support\FinanceReportService;
use App\Support\OperatorSwitch;
use Database\Seeders\GranularPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Shopper\Cart\CartSessionManager;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Setting;
use Shopper\Core\Models\Zone;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Fase3GovernanceFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_granular_sod_permissions_separate_fulfillment_finance_and_refund_authorities(): void
    {
        $this->configureShopperCpanel();
        (new GranularPermissionsSeeder)->run();
        Permission::query()->firstOrCreate(['name' => 'access_dashboard', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'browse_orders', 'guard_name' => 'web']);

        $warehouseStaff = User::factory()->create(['email' => 'wh-sod@example.com']);
        $warehouseStaff->givePermissionTo(['access_dashboard', 'browse_orders', 'orders.fulfill']);

        $this->assertTrue(Gate::forUser($warehouseStaff)->allows('manage-order-fulfillment'));
        $this->assertTrue(Gate::forUser($warehouseStaff)->denies('orders-mark-paid'));
        $this->assertTrue(Gate::forUser($warehouseStaff)->denies('orders-cancel'));
        $this->assertTrue(Gate::forUser($warehouseStaff)->denies('orders-refund'));
        $this->assertTrue(Gate::forUser($warehouseStaff)->denies('finance-dashboard'));
        $this->assertTrue(Gate::forUser($warehouseStaff)->denies('override-allocation'));

        $order = Order::factory()->create(['currency_code' => 'IDR']);

        Livewire::actingAs($warehouseStaff)
            ->test(OrderFinanceOpsPanel::class, ['order' => $order])
            ->call('recordRefund')
            ->assertForbidden();

        Livewire::actingAs($warehouseStaff)
            ->test(FinanceReports::class)
            ->assertForbidden();

        $financeStaff = User::factory()->create(['email' => 'fin-sod@example.com']);
        $financeStaff->givePermissionTo(['access_dashboard', 'finance.view_dashboard', 'orders.mark_paid']);

        $this->assertTrue(Gate::forUser($financeStaff)->allows('finance-dashboard'));
        $this->assertTrue(Gate::forUser($financeStaff)->allows('orders-mark-paid'));
        $this->assertTrue(Gate::forUser($financeStaff)->denies('manage-order-fulfillment'));
    }

    public function test_staff_2fa_enforcement_and_account_suspension_block_unauthorized_access(): void
    {
        $this->configureShopperCpanel();
        Permission::query()->firstOrCreate(['name' => 'access_dashboard', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'browse_orders', 'guard_name' => 'web']);

        $staff = User::factory()->create([
            'email' => 'ops-2fa@example.com',
            'store_two_factor_secret' => null,
        ]);
        $staff->givePermissionTo(['access_dashboard', 'browse_orders']);

        config()->set('shopper.auth.require_two_factor', true);

        $this->actingAs($staff)
            ->get(route('shopper.orders.index'))
            ->assertRedirect(route('shopper.profile'));

        $staff->forceFill(['store_two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();
        $this->actingAs($staff->fresh())
            ->get(route('shopper.orders.index'))
            ->assertOk();

        // Suspension blocks cpanel, mobile login API, and web Fortify login.
        $staff->suspend();

        $this->actingAs($staff->fresh())
            ->get(route('shopper.orders.index'))
            ->assertRedirect(route('dashboard'));

        $this->postJson('/api/v1/auth/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertForbidden();

        auth()->logout();
        $this->flushSession();

        $this->post('/login', [
            Fortify::username() => $staff->email,
            'password' => 'password',
        ])->assertSessionHasErrors(Fortify::username());
    }

    public function test_active_provider_db_setting_and_allocation_override_reason_are_honored(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'active_payment_provider'],
            ['display_name' => 'Active Payment Provider', 'value' => 'none', 'locked' => true],
        );
        Cache::forget('shopper-setting.active_payment_provider');

        $this->assertSame('manual', ActiveProvider::family(OperatorSwitch::PAYMENT));

        $country = Country::factory()->create(['cca2' => 'ID', 'name' => 'Indonesia']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);
        $carrier = Carrier::factory()->create(['driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);
        $option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 10000,
            'is_enabled' => true,
        ]);

        $inv1 = Inventory::factory()->create(['is_default' => true, 'name' => 'Gudang A']);
        $inv2 = Inventory::factory()->create(['is_default' => false, 'name' => 'Gudang B']);
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $product->mutateStock($inv1->id, 5);
        $product->mutateStock($inv2->id, 5);

        $order = Order::factory()->create([
            'currency_code' => shopper_currency(),
            'status' => OrderStatus::New,
            'shipping_option_id' => $option->id,
            'zone_id' => $zone->id,
            'metadata' => json_encode(['shipping_address' => ['country_id' => $country->id]], JSON_THROW_ON_ERROR),
        ]);
        $addr = \Shopper\Core\Models\OrderAddress::query()->create([
            'first_name' => 'Andi',
            'last_name' => 'Wijaya',
            'street_address' => 'Jl. Asia Afrika',
            'postal_code' => '40111',
            'city' => 'Bandung',
            'phone' => '08123456789',
            'country_name' => 'Indonesia',
        ]);
        $order->forceFill(['shipping_address_id' => $addr->id])->save();
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => 'OVR-1',
            'quantity' => 2,
            'unit_price_amount' => 50000,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv1->id,
            'carrier_code' => 'manual',
            'service_code' => 'REG',
            'cost' => 10000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);
        $line = OrderShipmentLine::query()->create([
            'order_shipment_id' => $shipment->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        $actor = User::factory()->create(['email' => 'wh-actor@example.com']);
        resolve(OverrideAllocation::class)->handle(
            order: $order,
            moves: [[
                'shipment_line_id' => $line->id,
                'qty' => 2,
                'from_inventory_id' => $inv1->id,
                'to_inventory_id' => $inv2->id,
            ]],
            actor: $actor,
            reason: 'Dekat alamat tujuan',
        );

        $log = AllocationOverrideLog::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Dekat alamat tujuan', $log->payload['reason'] ?? null);

        $debitHistory = InventoryHistory::query()
            ->where('inventory_id', $inv2->id)
            ->where('event', 'allocation_override_debit')
            ->firstOrFail();
        $this->assertSame(-2, (int) $debitHistory->old_quantity);
    }

    public function test_cogs_snapshot_invoice_numbering_pkp_invoice_and_finance_exports(): void
    {
        $this->configureShopperCpanel();
        (new GranularPermissionsSeeder)->run();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);

        $admin = User::factory()->create(['email' => 'cfo@example.com']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $currency = Currency::query()->firstOrCreate(
            ['code' => shopper_currency()],
            [
                'name' => shopper_currency(),
                'symbol' => 'Rp',
                'format' => '1,234.56',
            ],
        );

        $inventory = Inventory::factory()->create(['is_default' => true]);
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'name' => 'Laptop Pro']);
        $product->mutateStock($inventory->id, 10);
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 1110000,
            'cost_amount' => 800000,
            'currency_id' => $currency->id,
        ]);

        $payment = PaymentMethod::factory()->create([
            'title' => 'Manual Transfer',
            'slug' => 'manual',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $zone->paymentMethods()->attach($payment->id);

        Setting::query()->updateOrCreate(['key' => 'is_pkp'], ['display_name' => 'PKP', 'value' => 1, 'locked' => true]);
        Setting::query()->updateOrCreate(['key' => 'company_npwp'], ['display_name' => 'NPWP', 'value' => '01.234.567.8-901.000', 'locked' => true]);
        Setting::query()->updateOrCreate(['key' => 'invoice_prefix'], ['display_name' => 'Prefix', 'value' => 'OMINV', 'locked' => true]);

        $customer = User::factory()->create(['email' => 'b2b-buyer@example.com']);
        $cart = Cart::query()->create(['currency_code' => $currency->code, 'customer_id' => $customer->id]);
        $cart->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 1110000,
        ]);

        $checkout = [
            'shipping_address' => [
                'first_name' => 'PT Maju',
                'last_name' => 'Jaya',
                'street_address' => 'Jl. Thamrin 10',
                'postal_code' => '10350',
                'city' => 'Jakarta',
                'state' => 'DKI Jakarta',
                'phone_number' => '081200001111',
                'country_id' => $country->id,
            ],
            'shipping_option' => [[
                'id' => null,
                'name' => 'Flat Ongkir',
                'price' => 0,
                'amount' => 0,
                'service_code' => 'flat',
                'carrier_code' => 'manual',
            ]],
            'allocation_plan' => new AllocationPlan([
                new ShipmentDraft($inventory->id, [['purchasable_type' => $product->getMorphClass(), 'purchasable_id' => $product->id, 'qty' => 1]], 1000),
            ]),
            'payment' => [['id' => $payment->id, 'driver' => 'manual', 'title' => 'Manual Transfer']],
        ];

        $this->actingAs($customer);
        $order = resolve(CreateOrder::class)->handle($checkout, $cart);

        $item = $order->items()->firstOrFail();
        $this->assertSame(800000, (int) $item->unit_cost_amount);

        $order->forceFill(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Processing])->save();
        event(new OrderPaid($order));

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertStringStartsWith('OMINV-'.now()->format('Ym').'-', (string) $order->invoice_number);

        // Printable invoice checks (customer + staff PKP view).
        $this->actingAs($customer)
            ->get(route('account.orders.invoice', $order))
            ->assertOk()
            ->assertSee($order->invoice_number)
            ->assertSee('01.234.567.8-901.000')
            ->assertSee('PPN 11%');

        $margin = resolve(FinanceReportService::class)->profitAndMargin(now()->subDay(), now());
        $this->assertSame(1110000, $margin['summary']['net_revenue']);
        $this->assertSame(800000, $margin['summary']['cogs']);
        $this->assertSame(310000, $margin['summary']['gross_profit']);

        // Reports + OpenSpout XLSX & CSV export checks.
        $this->actingAs($admin)
            ->get(route('shopper.reports.finance'))
            ->assertOk()
            ->assertSee($order->invoice_number);

        $csvResponse = $this->actingAs($admin)
            ->get(route('cpanel.reports.finance.export', ['tab' => 'margin', 'format' => 'csv']));
        $csvResponse->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('Content-Type'));

        $xlsxResponse = $this->actingAs($admin)
            ->get(route('cpanel.reports.finance.export', ['tab' => 'ppn', 'format' => 'xlsx']));
        $xlsxResponse->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $xlsxResponse->headers->get('Content-Type'));
    }
}
