<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Orders\RemitCodOrder;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Support\Code128Svg;
use App\Support\OrderPaymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Fase2LogisticsAndCodTest extends TestCase
{
    use RefreshDatabase;

    private function seedStoreCatalog(): array
    {
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create([
                'code' => shopper_currency(),
                'name' => shopper_currency(),
                'symbol' => shopper_currency(),
                'format' => '1,234.56',
            ]);

        $inventory = Inventory::factory()->create([
            'is_default' => true,
            'name' => 'Gudang Pusat',
        ]);

        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'name' => 'Produk COD Test',
            'sku' => 'COD-SKU-1',
        ]);
        $product->mutateStock($inventory->id, 10);

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 150000,
            'cost_amount' => 90000,
            'currency_id' => $currency->id,
        ]);

        $carrier = Carrier::factory()->create([
            'name' => 'Reguler Toko',
            'slug' => 'reguler-toko',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $zone->carriers()->attach($carrier->id);

        $nonCodOption = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Ekspres Non-COD',
            'price' => 20000,
            'is_enabled' => true,
            'metadata' => ['supports_cod' => false],
        ]);

        $codOption = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Kurir Toko COD',
            'price' => 15000,
            'is_enabled' => true,
            'metadata' => ['supports_cod' => true],
        ]);

        $codPayment = PaymentMethod::factory()->create([
            'title' => 'Bayar di Tempat (COD)',
            'slug' => 'cod',
            'driver' => 'manual',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'cod']),
        ]);
        $zone->paymentMethods()->attach($codPayment->id);

        return compact('zone', 'inventory', 'product', 'nonCodOption', 'codOption', 'codPayment');
    }

    public function test_api_cod_checkout_rejects_non_cod_courier_and_succeeds_with_cod_courier_and_remits(): void
    {
        $fixture = $this->seedStoreCatalog();
        $customer = User::factory()->create(['email' => 'cod-buyer@example.com']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $fixture['product']->id,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Siti',
            'last_name' => 'Rahma',
            'street_address' => 'Jl. Sudirman No. 1',
            'postal_code' => '10220',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
        ])->assertOk();

        $this->assertFalse(OrderPaymentType::shippingRatesSupportCod([
            'shipping_option' => [['service_code' => 'x', 'is_cod' => false]],
        ]));
        $this->assertTrue(OrderPaymentType::shippingRatesSupportCod([
            'shipping_option' => [['service_code' => 'y', 'is_cod' => true]],
        ]));

        $this->postJson('/api/v1/checkout/shipping-option', [
            'service_code' => $fixture['codOption']->id,
        ])->assertOk();

        $res = $this->postJson('/api/v1/checkout/place-order', [
            'payment_method_id' => $fixture['codPayment']->id,
        ])->assertCreated();

        $orderNumber = (string) $res->json('data.number');
        $order = Order::query()->where('number', $orderNumber)->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $order->payment_status);

        $staff = User::factory()->create(['email' => 'finance-cod@example.com']);
        Event::fake([OrderPaid::class]);

        $remitted = resolve(RemitCodOrder::class)->handle($order, $staff, 'COD-SETOR-001', 'Setoran kurir diterima');
        $this->assertSame(PaymentStatus::Paid, $remitted->payment_status);
        $this->assertNotNull($remitted->cod_remitted_at);
        $this->assertNotNull($remitted->paid_at);
        $this->assertSame($staff->id, (int) $remitted->cod_remitted_by);
        Event::assertDispatched(OrderPaid::class);
    }

    public function test_shipment_tracking_is_forward_only_and_never_regresses_delivered_status(): void
    {
        $this->assertFalse(NormalizeShipmentStatus::shouldTransition(
            NormalizeShipmentStatus::DELIVERED,
            NormalizeShipmentStatus::IN_TRANSIT,
        ));
        $this->assertTrue(NormalizeShipmentStatus::shouldTransition(
            NormalizeShipmentStatus::PICKED_UP,
            NormalizeShipmentStatus::IN_TRANSIT,
        ));
    }

    public function test_warehouse_docs_and_code128_svg_render_cleanly_for_authorized_staff(): void
    {
        $this->configureShopperCpanel();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);
        $admin = User::factory()->create(['email' => 'wh-admin@example.com']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create(['name' => 'Gudang Utama']);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'awb' => 'JNE-123456',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'ready',
        ]);

        $svg = Code128Svg::render('JNE-123456');
        $this->assertStringContainsString('<svg', $svg);

        $this->actingAs($admin)
            ->get(route('shopper.orders.warehouse.packing-slip', $order))
            ->assertOk()
            ->assertSee($order->number);

        $this->actingAs($admin)
            ->get(route('shopper.orders.warehouse.pick-list', ['orders' => $order->id]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('shopper.orders.warehouse.bulk-labels', ['orders' => $order->id]))
            ->assertOk()
            ->assertSee('JNE-123456');
    }
}
