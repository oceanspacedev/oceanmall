<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce\Cpanel;

use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Livewire\Shopper\Dashboard\OperationalAlerts;
use App\Livewire\Shopper\Pages\OrderDetail;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Livewire\Shopper\Pages\Settings\Carriers;
use App\Livewire\Shopper\Pages\Settings\PaymentMethods;
use App\Models\OrderShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Shipping\Facades\Shipping;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ShopperDriverAdminTest extends TestCase
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

    public function test_shopper_payment_and_shipping_pages_resolve_komerce_drivers(): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');

        $admin = $this->admin();

        PaymentMethod::query()->create([
            'title' => 'QRIS Komerce',
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        Carrier::query()->create([
            'name' => 'JNE Express',
            'slug' => 'jne',
            'driver' => 'rajaongkir',
            'is_enabled' => true,
        ]);

        $this->assertTrue(Payment::isConfigured('komerce'));
        $this->assertTrue(Shipping::isConfigured('rajaongkir'));
        $this->assertTrue(Shipping::isConfigured('komerce'));

        $this->actingAs($admin)
            ->get(route('shopper.settings.payment-methods'))
            ->assertOk()
            ->assertSee('QRIS Komerce', false)
            ->assertSee('Komerce', false);

        $this->actingAs($admin)
            ->get(route('shopper.settings.carriers'))
            ->assertOk()
            ->assertSee('JNE Express', false)
            ->assertSee('RajaOngkir', false);
    }

    public function test_operational_alerts_adapts_quota_badges_across_keyless_starter_and_delivery_only(): void
    {
        $admin = $this->admin();

        // 1. Keyless: no external integration quota card
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');

        Livewire::actingAs($admin)
            ->test(OperationalAlerts::class)
            ->assertDontSee('Status Integrasi Eksternal', false)
            ->assertDontSee('Kuota Cek Ongkir Harian', false)
            ->assertDontSee('Komerce Shipping Delivery', false);

        // 2. Starter: shows Cost quota, not Delivery
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        resolve(KomerceCapabilities::class)->forget();

        Livewire::actingAs($admin)
            ->test(OperationalAlerts::class)
            ->assertSee('Status Integrasi Eksternal', false)
            ->assertSee('Kuota Cek Ongkir Harian:', false)
            ->assertSee('0/100', false)
            ->assertDontSee('Komerce Shipping Delivery', false);

        // 3. Delivery-only: hides Cost quota and shows Delivery status (Normal -> Terbatas on 429)
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        resolve(KomerceCapabilities::class)->forget();

        Livewire::actingAs($admin)
            ->test(OperationalAlerts::class)
            ->assertDontSee('Kuota Cek Ongkir Harian', false)
            ->assertSee('Komerce Shipping Delivery:', false)
            ->assertSee('Normal', false);

        resolve(KomerceCapabilities::class)->observe('shipping_delivery', 429, json_encode(['meta' => ['message' => 'Too many requests']]) ?: '');

        Livewire::actingAs($admin)
            ->test(OperationalAlerts::class)
            ->assertSee('Komerce Shipping Delivery:', false)
            ->assertSee('Terbatas', false);
    }

    public function test_carriers_and_payment_methods_tables_handle_keyless_qrisly_only_delivery_only_and_unknown_drivers(): void
    {
        $admin = $this->admin();

        $komerceMethod = PaymentMethod::query()->create([
            'title' => 'QRIS Komerce',
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        $manualMethod = PaymentMethod::query()->create([
            'title' => 'Transfer Manual',
            'slug' => 'manual-bank',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $unknownMethod = PaymentMethod::query()->create([
            'title' => 'Legacy Pay',
            'slug' => 'legacy-pay',
            'driver' => 'acme',
            'is_enabled' => true,
        ]);

        $roCarrier = Carrier::query()->create([
            'name' => 'JNE Express',
            'slug' => 'jne',
            'driver' => 'rajaongkir',
            'is_enabled' => true,
        ]);
        $manualCarrier = Carrier::query()->create([
            'name' => 'Kurir Toko',
            'slug' => 'kurir-toko',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $unknownCarrier = Carrier::query()->create([
            'name' => 'Legacy Express',
            'slug' => 'legacy-express',
            'driver' => 'acme',
            'is_enabled' => true,
        ]);

        // Keyless: komerce & rajaongkir & unknown show Nonaktif (NOT Aktif), manual shows Aktif, no 500 on acme
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');

        Livewire::actingAs($admin)
            ->test(PaymentMethods::class)
            ->assertTableColumnStateSet('provider_status', 'Nonaktif', record: $komerceMethod)
            ->assertTableColumnStateSet('provider_status', 'Nonaktif', record: $unknownMethod)
            ->assertTableColumnStateSet('provider_status', 'Aktif', record: $manualMethod)
            ->assertSee('acme', false);

        Livewire::actingAs($admin)
            ->test(Carriers::class)
            ->assertTableColumnStateSet('provider_status', 'Nonaktif', record: $roCarrier)
            ->assertTableColumnStateSet('provider_status', 'Nonaktif', record: $unknownCarrier)
            ->assertTableColumnStateSet('provider_status', 'Aktif', record: $manualCarrier)
            ->assertSee('acme', false);

        // QRISLY-only & Delivery-only: komerce payment & rajaongkir carrier show Aktif
        config()->set('komerce.qrisly_api_key', 'test-qrisly-key');
        config()->set('komerce.qrisly_qris_id', '998877');
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');

        Livewire::actingAs($admin)
            ->test(PaymentMethods::class)
            ->assertTableColumnStateSet('provider_status', 'Aktif', record: $komerceMethod);

        Livewire::actingAs($admin)
            ->test(Carriers::class)
            ->assertTableColumnStateSet('provider_status', 'Aktif', record: $roCarrier);
    }

    public function test_order_detail_cancel_action_surfaces_notification_when_provider_lookup_fails(): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test');

        $admin = $this->admin();
        $method = PaymentMethod::query()->create([
            'title' => 'BCA VA',
            'slug' => 'komerce-va-bca',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $method->id,
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 100000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-FAIL-1',
        ]);

        Http::fake([
            'https://payment.example.test/*' => Http::response([
                'meta' => ['code' => 500, 'status' => 'error', 'message' => 'Upstream timeout'],
            ], 500),
        ]);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('cancelOrder', data: ['reason' => 'Test failure'])
            ->assertNotified('Gagal membatalkan pesanan.');

        $this->assertNotSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_order_shipments_table_hides_print_label_without_awb_and_shows_when_awb_filled(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_delivery_api_key', '');

        $admin = $this->admin();
        $inventory = Inventory::factory()->create();
        $carrier = Carrier::query()->create([
            'name' => 'JNE',
            'slug' => 'jne',
            'driver' => 'rajaongkir',
            'is_enabled' => true,
        ]);

        $orderWithoutAwb = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $orderWithoutAwb->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
        ]);
        $shippingWithoutAwb = OrderShipping::query()->create([
            'order_id' => $orderWithoutAwb->id,
            'carrier_id' => $carrier->id,
            'shipped_at' => now(),
            'tracking_number' => null,
        ]);

        $orderWithAwb = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $orderWithAwb->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'JNE-TBL-1',
            'tracking_number' => 'JNE-TBL-1',
        ]);
        $shippingWithAwb = OrderShipping::query()->create([
            'order_id' => $orderWithAwb->id,
            'carrier_id' => $carrier->id,
            'shipped_at' => now(),
            'tracking_number' => 'JNE-TBL-1',
        ]);

        Livewire::actingAs($admin)
            ->test(OrderShipments::class, ['order' => $orderWithoutAwb])
            ->assertTableActionHidden('printProviderLabel', $shippingWithoutAwb);

        Livewire::actingAs($admin)
            ->test(OrderShipments::class, ['order' => $orderWithAwb])
            ->assertTableActionVisible('printProviderLabel', $shippingWithAwb);
    }
}
