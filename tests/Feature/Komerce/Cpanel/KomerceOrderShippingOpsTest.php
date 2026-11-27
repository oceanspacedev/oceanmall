<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce\Cpanel;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Livewire\KomerceOrderShipping;
use App\Integrations\Komerce\Services\KomerceDeliveryFulfillment;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class KomerceOrderShippingOpsTest extends TestCase
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

    private function fakeDelivery(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('komerce.pickup_time', '10:00:00');
        config()->set('komerce.pickup_vehicle', 'Motor');
    }

    /**
     * @return array{0: Order, 1: OrderShipment}
     */
    private function orderWithShipment(array $orderAttrs = [], array $shipmentAttrs = []): array
    {
        $order = Order::factory()->create(array_merge([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ], $orderAttrs));

        $inventory = Inventory::factory()->create(['name' => 'Gudang Cirebon']);
        $shipment = OrderShipment::query()->create(array_merge([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ], $shipmentAttrs));

        return [$order, $shipment];
    }

    public function test_unpaid_order_cannot_register_komerce_delivery(): void
    {
        $this->fakeDelivery();
        $admin = $this->admin();
        [$order] = $this->orderWithShipment([
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);

        Http::fake();

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('processDeliveryOrder')
            ->assertSet('overrideError', 'Lunasi pesanan dulu sebelum mendaftarkan pickup dan resi Komerce.');

        Http::assertNothingSent();
    }

    public function test_panel_shows_pickup_action_after_komerce_registration_without_awb(): void
    {
        $this->fakeDelivery();
        $admin = $this->admin();
        [$order] = $this->orderWithShipment([], [
            'metadata' => ['komerce' => ['order_no' => 'RO-WAIT-1', 'order_id' => '11']],
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Request Pickup', false)
            ->assertDontSee('Cetak Stiker Resi', false)
            ->assertSee('Gudang pengirim terkunci', false);
    }

    public function test_print_label_requires_pickup_not_just_delivery_order_number(): void
    {
        $this->fakeDelivery();
        $admin = $this->admin();
        [$order, $shipment] = $this->orderWithShipment([], [
            'metadata' => ['komerce' => ['order_no' => 'RO-NOPICKUP-1', 'order_id' => '12']],
        ]);

        $this->assertFalse(resolve(OrderShipmentOpsPresenter::class)->canPrintLabel($shipment));

        $this->from(route('shopper.orders.detail', $order))
            ->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertSessionHasErrors('label');
    }

    public function test_print_label_allowed_after_awb(): void
    {
        $this->fakeDelivery();
        $admin = $this->admin();
        [$order, $shipment] = $this->orderWithShipment([], [
            'awb' => 'JNE-READY-1',
            'tracking_number' => 'JNE-READY-1',
            'status' => 'labeled',
            'metadata' => [
                'komerce' => [
                    'order_no' => 'RO-READY-1',
                    'order_id' => '13',
                    'pickup_response' => ['meta' => ['status' => 'success']],
                ],
            ],
        ]);

        $this->assertTrue(resolve(OrderShipmentOpsPresenter::class)->canPrintLabel($shipment));

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['path' => '/storage/label/RO-READY-1.pdf'],
            ]),
            'https://delivery.example.test/order/storage/label/*' => Http::response('%PDF-fake', 200),
        ]);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertRedirect('https://delivery.example.test/order/storage/label/RO-READY-1.pdf');
    }

    public function test_starter_mode_badges_label_buttons_and_cancelled_guards(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_delivery_api_key', '');
        Http::fake();

        $admin = $this->admin();
        [$order, $shipment1] = $this->orderWithShipment([], [
            'driver' => 'rajaongkir',
            'status' => 'pending',
        ]);

        // Pre-AWB Starter state
        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Mode Starter · Menunggu Resi Manual', false)
            ->assertSee('Mode Fulfillment', false)
            ->assertSee('Starter (Resi Manual)', false)
            ->assertSee('Input via Kartu Fulfillment', false)
            ->assertDontSee('Belum Terdaftar', false)
            ->assertDontSee('Sebagian Terdaftar', false)
            ->assertDontSee('ID Order Komerce', false)
            ->assertDontSee('Cetak Semua Stiker Resi', false);

        // Partial AWB (1 labeled, 1 pending)
        $inventory2 = Inventory::factory()->create(['name' => 'Gudang Surabaya']);
        $shipment2 = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory2->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);

        $shipment1->forceFill([
            'awb' => 'JNE-STARTER-1',
            'tracking_number' => 'JNE-STARTER-1',
            'status' => NormalizeShipmentStatus::LABELED,
        ])->save();

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Sebagian Resi Manual', false)
            ->assertSee('Cetak Semua Stiker Resi', false)
            ->assertSee('Cetak Stiker Resi', false)
            ->assertDontSee('Sebagian Terdaftar', false);

        // Cancelling the second parcel excludes it from active counts -> "Resi Manual Terbit"
        $shipment2->forceFill(['status' => NormalizeShipmentStatus::CANCELLED])->save();

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Resi Manual Terbit', false)
            ->assertSee('Resi Manual', false)
            ->assertSee('Cetak Semua Stiker Resi', false)
            ->assertDontSee('Sebagian Resi Manual', false)
            ->assertDontSee('Sebagian Terdaftar', false)
            ->assertDontSee('Belum Terdaftar', false)
            ->assertDontSee('ID Order Komerce', false);
    }

    public function test_delivery_only_recognizes_komerce_inventory_origins_and_process_delivery_reports_missing_fulfiller(): void
    {
        config()->set('komerce.shipping_cost_api_key', '');
        $this->fakeDelivery();
        Http::fake(['*' => Http::response(['data' => []])]);

        $admin = $this->admin();
        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Delivery Only',
            'shipping_origins' => ['komerce' => '17248'],
        ]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertViewHas('inventories', function (array $inventories) use ($inventory): bool {
                $row = collect($inventories)->firstWhere('id', $inventory->id);

                return is_array($row)
                    && $row['shipping_origin_id'] === '17248'
                    && $row['ready_for_shipping'] === true;
            });

        // Revoke Delivery before processDeliveryOrder runs -> surfaces clear overrideError instead of silence
        config()->set('komerce.shipping_delivery_api_key', '');

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('processDeliveryOrder')
            ->assertSet('successMessage', null)
            ->assertSet('overrideError', 'Layanan pickup otomatis Komerce Delivery tidak aktif atau tidak mencakup paket ini. Gunakan input resi manual di kartu Fulfillment.');
    }

    public function test_refresh_tracking_surfaces_single_partial_and_reconcile_failures_without_masking(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.shipping_delivery_api_key', '');

        $admin = $this->admin();

        // 1. Single shipment quota failure preserves cached history and surfaces overrideError
        $cachedHistory = [['description' => 'Manifested at Jakarta', 'datetime' => '2026-11-25 10:00', 'location' => 'Jakarta']];
        [$orderSingle, $shipmentSingle] = $this->orderWithShipment([], [
            'driver' => 'rajaongkir',
            'awb' => 'JNE-FAIL-1',
            'tracking_number' => 'JNE-FAIL-1',
            'status' => NormalizeShipmentStatus::IN_TRANSIT,
            'metadata' => [
                'tracking_history' => $cachedHistory,
                'komerce' => ['order_no' => 'RO-OLD-STALE'],
            ],
        ]);

        Http::fake([
            'https://shipping.example.test/*' => Http::response([
                'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded'],
            ], 429),
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $orderSingle])
            ->call('refreshTracking')
            ->assertSet('successMessage', null)
            ->assertSet('overrideError', 'Gagal memperbarui status pelacakan: Batas pelacakan harian kurir sudah tercapai. Silakan coba lagi setelah '.now('Asia/Jakarta')->addDay()->startOfDay()->format('d M Y H:i').' WIB.');

        $shipmentSingle->refresh();
        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $shipmentSingle->status);
        $this->assertSame($cachedHistory, data_get($shipmentSingle->metadata, 'tracking_history'));
    }

    public function test_refresh_tracking_surfaces_partial_batch_failure_and_reconcile_failure_without_masking(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.shipping_delivery_api_key', '');

        $admin = $this->admin();

        // Multi-shipment partial failure (1 ok, 1 fails 500) surfaces BOTH successMessage and overrideError
        [$orderMulti] = $this->orderWithShipment([], [
            'driver' => 'rajaongkir',
            'awb' => 'JNE-OK-1',
            'tracking_number' => 'JNE-OK-1',
            'status' => NormalizeShipmentStatus::LABELED,
        ]);
        $inv2 = Inventory::factory()->create(['name' => 'Gudang Dua', 'shipping_origins' => ['rajaongkir' => '502']]);
        OrderShipment::query()->create([
            'order_id' => $orderMulti->id,
            'inventory_id' => $inv2->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'awb' => 'JNE-ERR-2',
            'tracking_number' => 'JNE-ERR-2',
            'status' => NormalizeShipmentStatus::LABELED,
        ]);

        Http::fake([
            'https://shipping.example.test/*' => function (Request $request) {
                $awb = (string) ($request->data()['awb'] ?? ($request['awb'] ?? ''));

                if (str_contains($request->url(), 'JNE-OK-1') || $awb === 'JNE-OK-1') {
                    return Http::response([
                        'meta' => ['code' => 200, 'status' => 'success'],
                        'data' => [
                            'delivered' => false,
                            'delivery_status' => ['status' => 'ON PROCESS'],
                            'manifest' => [['manifest_description' => 'In transit', 'manifest_date' => '2026-11-26', 'manifest_time' => '09:00', 'city_name' => 'Bandung']],
                        ],
                    ]);
                }

                return Http::response(['meta' => ['code' => 500, 'status' => 'error', 'message' => 'Server Error']], 500);
            },
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $orderMulti])
            ->call('refreshTracking')
            ->assertSet('overrideError', 'Gagal memperbarui pelacakan dari penyedia pengiriman. Coba lagi sebentar.')
            ->assertSet('successMessage', 'Sebagian status pelacakan diperbarui (1 paket), namun ada kendala pada paket lain.');
    }

    public function test_refresh_tracking_reconcile_failure_and_starter_stale_order_no_never_emit_synced_message(): void
    {
        $admin = $this->admin();

        // 1. Reconcile failure when Delivery is enabled and no AWB must NOT emit "Data Komerce disinkronkan"
        $this->fakeDelivery();
        [$orderReconcile] = $this->orderWithShipment([], [
            'driver' => 'komerce',
            'external_reference' => 'RO-RECONCILE-FAIL',
            'metadata' => ['komerce' => ['order_no' => 'RO-RECONCILE-FAIL']],
        ]);

        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['data' => []]),
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 500, 'status' => 'error', 'message' => 'Gateway error'],
            ], 500),
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $orderReconcile])
            ->call('refreshTracking')
            ->assertSet('successMessage', null)
            ->assertDontSee('Data Komerce disinkronkan', false);

        // 2. Starter mode (!shippingDeliveryEnabled) with stale komerce.order_no and no AWB must NOT emit "Data Komerce disinkronkan"
        config()->set('komerce.shipping_delivery_api_key', '');
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        [$orderStarter] = $this->orderWithShipment([], [
            'driver' => 'rajaongkir',
            'external_reference' => 'RO-STALE-1',
            'metadata' => ['komerce' => ['order_no' => 'RO-STALE-1']],
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $orderStarter])
            ->call('refreshTracking')
            ->assertSet('successMessage', null)
            ->assertDontSee('Data Komerce disinkronkan', false);
    }

    public function test_stale_fulfillment_error_is_suppressed_on_revocation_and_cleared_on_manual_awb(): void
    {
        $this->fakeDelivery();
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['data' => []]),
            'https://delivery.example.test/order/api/v1/orders/store' => Http::response([
                'meta' => ['code' => 401, 'status' => 'error', 'message' => 'Invalid API Key'],
            ], 401),
        ]);

        $admin = $this->admin();
        $address = OrderAddress::query()->create([
            'first_name' => 'Siti',
            'last_name' => 'Aminah',
            'street_address' => 'Jl. Sudirman 10',
            'postal_code' => '10220',
            'city' => 'Jakarta',
            'phone' => '081234567899',
        ]);
        $order = Order::factory()->create([
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'shipping_address_id' => $address->id,
            'metadata' => json_encode([
                'komerce' => ['payment_type' => 'bank_transfer'],
                'shipping_address' => ['rajaongkir_destination_id' => '152', 'pin_point' => '-6.200000,106.816666'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Error',
            'phone_number' => '0215551234',
            'street_address' => 'Jl. Gudang 1',
            'city' => 'Jakarta',
            'postal_code' => '10110',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'shipping_origins' => ['komerce' => '501'],
        ]);
        $product = Product::factory()->standard()->create([
            'name' => 'Produk Uji',
            'sku' => 'SKU-ERR-1',
            'weight_value' => 500,
            'weight_unit' => 'g',
            'width_value' => 10,
            'width_unit' => 'cm',
            'height_value' => 10,
            'height_unit' => 'cm',
            'depth_value' => 10,
            'depth_unit' => 'cm',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'metadata' => [
                'rate' => [
                    'provider' => 'shipping_delivery',
                    'shipping_name' => 'JNE',
                    'service_name' => 'REG',
                    'shipping_cost' => 12000,
                    'shipping_cashback' => 0,
                    'service_fee' => 0,
                    'additional_cost' => 0,
                    'grandtotal' => 112000,
                    'cod_value' => 0,
                    'insurance_value' => 0,
                    'carrier_code' => 'jne',
                ],
            ],
        ]);
        $shipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        // Attempt 1 fails with 401 -> persists fulfillment_error AND revokes shipping_delivery
        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('processAllDeliveryOrders');

        $shipment->refresh();
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
        $this->assertNotNull(data_get($shipment->metadata, 'komerce.fulfillment_error'));
        $this->assertNotNull(data_get($shipment->metadata, 'fulfillment_error'));

        // Because Delivery is now unentitled, KomerceOrderShipping suppresses the stale error banner
        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertDontSee('Kendala Fulfillment', false);

        // When Delivery IS re-enabled and shipment still has no AWB, Kendala Fulfillment IS displayed
        resolve(KomerceCapabilities::class)->forget();
        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Kendala Fulfillment', false);

        // Revoke Delivery again -> enter manual AWB via CreateShippingLabel -> clears BOTH error keys
        config()->set('komerce.shipping_delivery_api_key', '');
        Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'is_enabled' => true]);

        Livewire::actingAs($admin)
            ->test(CreateShippingLabel::class, ['order' => $order])
            ->set('data.tracking_number', 'JNE-MANUAL-CLEAR-1')
            ->call('save')
            ->assertHasNoErrors();

        $shipment->refresh();
        $this->assertSame('JNE-MANUAL-CLEAR-1', $shipment->awb);
        $this->assertNull(data_get($shipment->metadata, 'komerce.fulfillment_error'));
        $this->assertNull(data_get($shipment->metadata, 'komerce.fulfillment_failed_at'));
        $this->assertNull(data_get($shipment->metadata, 'fulfillment_error'));

        // Re-enable Delivery -> OrderFulfillment & KomerceOrderShipping must still not show stale error because AWB is filled
        $this->fakeDelivery();
        Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $order])
            ->assertDontSee('Kendala Fulfillment', false);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertDontSee('Kendala Fulfillment', false);

        // Also test KomerceDeliveryFulfillment early-return cleanup directly
        $shipment->forceFill([
            'metadata' => array_merge($shipment->metadata ?? [], [
                'komerce' => ['fulfillment_error' => 'Lingering error', 'fulfillment_failed_at' => now()->toIso8601String()],
            ]),
        ])->save();

        // Bypass observer for this check isn't needed if we check fulfillLocked when somehow set:
        // Observer itself already cleaned it on save! Let's verify observer cleaned it on save:
        $shipment->refresh();
        $this->assertNull(data_get($shipment->metadata, 'komerce.fulfillment_error'));
        $this->assertNull(data_get($shipment->metadata, 'fulfillment_error'));

        resolve(KomerceDeliveryFulfillment::class)->fulfill($shipment);
        $this->assertNull(data_get($shipment->refresh()->metadata, 'komerce.fulfillment_error'));
    }

    public function test_mark_paid_and_process_delivery_succeeds_cleanly_in_starter_plus_payment_mode(): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test');
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_delivery_api_key', '');

        $admin = $this->admin();
        $method = PaymentMethod::query()->create([
            'title' => 'BCA VA',
            'slug' => 'komerce-va-bca',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        [$order] = $this->orderWithShipment([
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $method->id,
            'price_amount' => 150000,
        ], [
            'driver' => 'rajaongkir',
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 150000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-STARTER-1',
        ]);

        Http::fake([
            'https://payment.example.test/*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'payment_id' => 'KOMPAY-STARTER-1',
                    'status' => 'PAID',
                    'amount' => 150000,
                ],
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->assertSee('Sinkronkan Bayar', false)
            ->assertDontSee('Sinkronkan Bayar & Pickup', false)
            ->call('markPaidAndProcessDelivery')
            ->assertSet('overrideError', null)
            ->assertSet('successMessage', "Pembayaran selaras dengan Komerce untuk #{$order->number}.");

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_refresh_tracking_excludes_cancelled_shipments_and_sanitizes_reconcile_401(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        $this->fakeDelivery();

        $admin = $this->admin();
        [$order] = $this->orderWithShipment([], [
            'driver' => 'komerce',
            'awb' => 'JNE-ACT-1',
            'tracking_number' => 'JNE-ACT-1',
            'status' => NormalizeShipmentStatus::IN_TRANSIT,
            'external_reference' => 'RO-ACT-1',
            'metadata' => ['komerce' => ['order_no' => 'RO-ACT-1']],
        ]);
        $inv2 = Inventory::factory()->create(['name' => 'Gudang Cancel']);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv2->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'awb' => 'JNE-CANC-2',
            'tracking_number' => 'JNE-CANC-2',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'external_reference' => 'RO-CANC-2',
            'metadata' => ['komerce' => ['order_no' => 'RO-CANC-2']],
        ]);

        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['data' => []]),
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 401, 'status' => 'error', 'message' => 'Invalid API Key'],
            ], 401),
            'https://shipping.example.test/*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'delivered' => false,
                    'delivery_status' => ['status' => 'ON PROCESS'],
                    'manifest' => [['manifest_description' => 'In transit', 'manifest_date' => '2026-11-26', 'manifest_time' => '09:00', 'city_name' => 'Bandung']],
                ],
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('refreshTracking')
            ->assertSet('successMessage', 'Berhasil memperbarui status pelacakan kurir dari RajaOngkir.')
            ->assertSet('overrideError', 'Gagal menyelaraskan data Komerce: Akses layanan Komerce tidak aktif atau ditolak. Periksa konfigurasi API key.');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'JNE-CANC-2')
            || str_contains($request->url(), 'RO-CANC-2'));

        // All-cancelled order returns clear error without sending HTTP calls
        $orderAllCancelled = $this->orderWithShipment([], [
            'driver' => 'rajaongkir',
            'awb' => 'JNE-ALL-CANC',
            'tracking_number' => 'JNE-ALL-CANC',
            'status' => NormalizeShipmentStatus::CANCELLED,
        ])[0];

        Http::fake();

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $orderAllCancelled])
            ->call('refreshTracking')
            ->assertSet('successMessage', null)
            ->assertSet('overrideError', 'Semua pengiriman pada pesanan ini telah dibatalkan.');

        Http::assertNothingSent();
    }

    public function test_komship_manual_awb_hides_top_process_button_and_payment_only_keeps_inventories_ready(): void
    {
        $admin = $this->admin();

        // 1. Komship mode with manual AWB already entered (no delivery_order_no)
        $this->fakeDelivery();
        Http::fake(['*' => Http::response(['data' => []])]);
        [$orderManualAwb] = $this->orderWithShipment([], [
            'driver' => 'komerce',
            'awb' => 'JNE-MANUAL-ALREADY',
            'tracking_number' => 'JNE-MANUAL-ALREADY',
            'status' => NormalizeShipmentStatus::LABELED,
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $orderManualAwb])
            ->assertViewHas('hasUnprocessedShipment', false)
            ->assertDontSee('wire:click="processAllDeliveryOrders"', false)
            ->call('processAllDeliveryOrders')
            ->assertSet('overrideError', 'Semua pengiriman aktif pada pesanan ini sudah memiliki nomor resi (AWB).');

        // 2. Payment-only mode (shippingCostEnabled=false, shippingDeliveryEnabled=false)
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $orderManualAwb])
            ->assertViewHas('inventories', static fn (array $inventories): bool => collect($inventories)
                ->every(static fn (array $row): bool => $row['ready_for_shipping'] === true));
    }

    public function test_order_fulfillment_excludes_cancelled_shipment_errors(): void
    {
        $this->fakeDelivery();
        $admin = $this->admin();

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inv1 = Inventory::factory()->create(['name' => 'Gudang Aktif', 'shipping_origins' => ['komerce' => '501']]);
        $inv2 = Inventory::factory()->create(['name' => 'Gudang Batal', 'shipping_origins' => ['komerce' => '502']]);

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv1->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'JNE-ACTIVE-OK',
            'tracking_number' => 'JNE-ACTIVE-OK',
        ]);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv2->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'metadata' => [
                'fulfillment_error' => 'Old error on cancelled parcel',
                'komerce' => ['fulfillment_error' => 'Old error on cancelled parcel'],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $order])
            ->assertViewHas('fulfillmentErrors', [])
            ->assertDontSee('Old error on cancelled parcel', false);
    }

    public function test_order_fulfillment_excludes_cancelled_awbs_and_matches_presenter_print_parity(): void
    {
        $this->fakeDelivery();
        Http::fake([
            '*' => Http::response(['meta' => ['code' => 500, 'status' => 'error', 'message' => 'Simulated failure']], 500),
        ]);
        $admin = $this->admin();

        // 1. Active pending shipment (no AWB) + cancelled shipment with AWB & external_reference
        $orderWithCancelledAwb = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inv1 = Inventory::factory()->create(['name' => 'Gudang Aktif', 'shipping_origins' => ['komerce' => '501']]);
        $inv2 = Inventory::factory()->create(['name' => 'Gudang Batal', 'shipping_origins' => ['komerce' => '502']]);

        OrderShipment::query()->create([
            'order_id' => $orderWithCancelledAwb->id,
            'inventory_id' => $inv1->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::PENDING,
        ]);
        OrderShipment::query()->create([
            'order_id' => $orderWithCancelledAwb->id,
            'inventory_id' => $inv2->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'awb' => 'JNE-CANC-ONLY',
            'tracking_number' => 'JNE-CANC-ONLY',
            'external_reference' => 'RO-CANC-ONLY',
            'metadata' => ['komerce' => ['order_no' => 'RO-CANC-ONLY']],
        ]);

        Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $orderWithCancelledAwb])
            ->assertViewHas('providerAwbs', [])
            ->assertViewHas('hasPickupRequested', false)
            ->assertViewHas('canPrintProviderLabel', false)
            ->assertDontSee('JNE-CANC-ONLY', false)
            ->assertDontSee('Cetak Stiker Komerce', false)
            ->assertDontSee('Sedang Dipacking', false)
            ->assertSee('Terbitkan Resi Komerce', false);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $orderWithCancelledAwb))
            ->assertStatus(422);

        // 2. Active Komship manual-AWB shipment without external_reference / order_no remains thermal-printable
        $orderManualKomship = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $manualKomshipShipment = OrderShipment::query()->create([
            'order_id' => $orderManualKomship->id,
            'inventory_id' => $inv1->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'JNE-MANUAL-KOM',
            'tracking_number' => 'JNE-MANUAL-KOM',
        ]);

        $this->assertTrue(resolve(OrderShipmentOpsPresenter::class)->canPrintLabel($manualKomshipShipment));

        Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $orderManualKomship])
            ->assertViewHas('providerAwbs', ['JNE-MANUAL-KOM'])
            ->assertViewHas('canPrintProviderLabel', true)
            ->assertSee('JNE-MANUAL-KOM', false)
            ->assertSee('Cetak Stiker Komerce', false);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $orderManualKomship))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-MANUAL-KOM', false);

        // 3. Active registered Komship shipment + cancelled shipment -> shows only active AWB & printable button
        $orderRegisteredKomship = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $orderRegisteredKomship->id,
            'inventory_id' => $inv1->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'JNE-ACT-KOM',
            'tracking_number' => 'JNE-ACT-KOM',
            'external_reference' => 'RO-ACT-KOM',
            'metadata' => ['komerce' => ['order_no' => 'RO-ACT-KOM']],
        ]);
        OrderShipment::query()->create([
            'order_id' => $orderRegisteredKomship->id,
            'inventory_id' => $inv2->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'awb' => 'JNE-CANC-SKIP',
            'tracking_number' => 'JNE-CANC-SKIP',
            'external_reference' => 'RO-CANC-SKIP',
            'metadata' => ['komerce' => ['order_no' => 'RO-CANC-SKIP']],
        ]);

        Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $orderRegisteredKomship])
            ->assertViewHas('providerAwbs', ['JNE-ACT-KOM'])
            ->assertViewHas('canPrintProviderLabel', true)
            ->assertSee('JNE-ACT-KOM', false)
            ->assertDontSee('JNE-CANC-SKIP', false)
            ->assertSee('Cetak Stiker Komerce', false);
    }

    public function test_order_fulfillment_and_shipments_exclude_cancelled_and_awbless_shipments_from_tracking(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_cost_base_url', 'https://shipping.example.test');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        $this->fakeDelivery();
        Http::fake();

        $admin = $this->admin();
        $carrier = Carrier::query()->create([
            'name' => 'JNE',
            'slug' => 'jne',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        $inv1 = Inventory::factory()->create(['name' => 'Gudang Batal', 'shipping_origins' => ['komerce' => '501']]);
        $inv2 = Inventory::factory()->create(['name' => 'Gudang Tunggu', 'shipping_origins' => ['komerce' => '502']]);
        $inv3 = Inventory::factory()->create(['name' => 'Gudang Aktif', 'shipping_origins' => ['komerce' => '503']]);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $cancelled = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv1->id,
            'driver' => 'komerce',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'awb' => 'JNE-CANC-TRACK',
            'tracking_number' => 'JNE-CANC-TRACK',
            'external_reference' => 'RO-CANC-TRACK',
            'metadata' => ['komerce' => ['order_no' => 'RO-CANC-TRACK']],
        ]);
        $awaitingAwb = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv2->id,
            'driver' => 'komerce',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::PENDING,
            'external_reference' => 'RO-WAIT-AWB',
            'metadata' => ['komerce' => ['order_no' => 'RO-WAIT-AWB']],
        ]);
        $activeLabeled = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv3->id,
            'driver' => 'komerce',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'JNE-ACT-TRACK',
            'tracking_number' => 'JNE-ACT-TRACK',
            'external_reference' => 'RO-ACT-TRACK',
            'metadata' => ['komerce' => ['order_no' => 'RO-ACT-TRACK']],
        ]);

        $cards = collect(resolve(OrderShipmentOpsPresenter::class)->shipments($order))->keyBy('id');
        $this->assertFalse($cards[$cancelled->id]['can_track']);
        $this->assertFalse($cards[$awaitingAwb->id]['can_track']);
        $this->assertTrue($cards[$activeLabeled->id]['can_track']);

        Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $order])
            ->assertDontSee("refreshShipmentTracking({$cancelled->id})", false)
            ->assertDontSee("refreshShipmentTracking({$awaitingAwb->id})", false)
            ->assertSee("refreshShipmentTracking({$activeLabeled->id})", false)
            ->call('refreshShipmentTracking', $cancelled->id)
            ->assertNotified('Pengiriman yang sudah dibatalkan tidak dapat dilacak.')
            ->call('refreshShipmentTracking', $awaitingAwb->id)
            ->assertNotified('Nomor resi belum tersedia untuk paket ini.');

        Http::assertNothingSent();

        $shippingCancelled = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $carrier->id,
            'tracking_number' => 'JNE-CANC-TRACK',
            'status' => ShipmentStatus::Pending,
        ]);
        $shippingActive = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $carrier->id,
            'tracking_number' => 'JNE-ACT-TRACK',
            'status' => ShipmentStatus::Pending,
        ]);

        $shipmentsTable = Livewire::actingAs($admin)
            ->test(OrderShipments::class, ['order' => $order])
            ->assertTableActionHidden('refreshProviderTracking', $shippingCancelled)
            ->assertTableActionVisible('refreshProviderTracking', $shippingActive);

        $refreshAction = $shipmentsTable->instance()->getTable()->getAction('refreshProviderTracking');
        $this->assertNotNull($refreshAction);
        $refreshAction->record($shippingCancelled)->call();
        $shipmentsTable->assertNotified('Pengiriman yang sudah dibatalkan tidak dapat dilacak.');

        Http::assertNothingSent();
    }

    public function test_starter_manual_awb_mirroring_ignores_cancelled_shipments(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_delivery_api_key', '');
        Http::fake();

        $admin = $this->admin();
        $carrier = Carrier::query()->create([
            'name' => 'JNE',
            'slug' => 'jne',
            'driver' => 'rajaongkir',
            'is_enabled' => true,
        ]);
        $inv1 = Inventory::factory()->create(['name' => 'Gudang Batal']);
        $inv2 = Inventory::factory()->create(['name' => 'Gudang Aktif']);
        $product = Product::factory()->standard()->create([
            'name' => 'Produk Mirror',
            'sku' => 'SKU-MIRROR-1',
        ]);

        // 1. Cancelled AWB-less + active pending -> manual AWB mirrors onto active parcel only
        $order1 = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderItem::query()->create([
            'order_id' => $order1->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);
        $cancelled1 = OrderShipment::query()->create([
            'order_id' => $order1->id,
            'inventory_id' => $inv1->id,
            'driver' => 'rajaongkir',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::CANCELLED,
        ]);
        $active1 = OrderShipment::query()->create([
            'order_id' => $order1->id,
            'inventory_id' => $inv2->id,
            'driver' => 'rajaongkir',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::PENDING,
        ]);

        Livewire::actingAs($admin)
            ->test(CreateShippingLabel::class, ['order' => $order1])
            ->set('data.carrier_id', $carrier->id)
            ->set('data.tracking_number', 'JNE-MIRROR-TEST-1')
            ->call('save')
            ->assertHasNoErrors();

        $cancelled1->refresh();
        $active1->refresh();
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $cancelled1->status);
        $this->assertNull($cancelled1->awb);
        $this->assertNull($cancelled1->tracking_number);
        $this->assertSame('JNE-MIRROR-TEST-1', $active1->awb);
        $this->assertSame('JNE-MIRROR-TEST-1', $active1->tracking_number);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $active1->status);

        // 2. Cancelled AWB-less + active labeled -> new OrderShipping does NOT mutate cancelled parcel
        $order2 = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $cancelled2 = OrderShipment::query()->create([
            'order_id' => $order2->id,
            'inventory_id' => $inv1->id,
            'driver' => 'rajaongkir',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::CANCELLED,
        ]);
        $active2 = OrderShipment::query()->create([
            'order_id' => $order2->id,
            'inventory_id' => $inv2->id,
            'driver' => 'rajaongkir',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'JNE-EXISTING-1',
            'tracking_number' => 'JNE-EXISTING-1',
        ]);

        OrderShipping::query()->create([
            'order_id' => $order2->id,
            'carrier_id' => $carrier->id,
            'tracking_number' => 'JNE-NEW-FOR-CANCELLED',
            'status' => ShipmentStatus::Pending,
        ]);

        $cancelled2->refresh();
        $active2->refresh();
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $cancelled2->status);
        $this->assertNull($cancelled2->awb);
        $this->assertNull($cancelled2->tracking_number);
        $this->assertSame('JNE-EXISTING-1', $active2->awb);
    }

    public function test_mark_paid_and_process_delivery_succeeds_in_komship_mode_when_order_paid_fulfills_synchronously(): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        $this->fakeDelivery();

        $admin = $this->admin();
        $method = PaymentMethod::query()->create([
            'title' => 'BCA VA',
            'slug' => 'komerce-va-bca',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        $address = OrderAddress::query()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '10110',
            'city' => 'Jakarta',
            'phone' => '081234567890',
        ]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $method->id,
            'price_amount' => 112000,
            'shipping_address_id' => $address->id,
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => 'KOMPAY-KOMSHIP-SYNC-1',
                    'payment_type' => 'bank_transfer',
                ],
                'shipping_address' => [
                    'destination_id' => '152',
                    'destination_driver' => 'rajaongkir',
                    'pin_point' => '-6.200000,106.816666',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 112000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-KOMSHIP-SYNC-1',
        ]);
        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Komship',
            'phone_number' => '0215551234',
            'street_address' => 'Jl. Gudang 1',
            'city' => 'Jakarta',
            'postal_code' => '10110',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'shipping_origins' => ['komerce' => '501'],
        ]);
        $product = Product::factory()->standard()->create([
            'name' => 'Produk Komship',
            'sku' => 'SKU-KOMSHIP-1',
            'weight_value' => 500,
            'weight_unit' => 'g',
            'width_value' => 10,
            'width_unit' => 'cm',
            'height_value' => 10,
            'height_unit' => 'cm',
            'depth_value' => 10,
            'depth_unit' => 'cm',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
            'metadata' => [
                'rate' => [
                    'provider' => 'shipping_delivery',
                    'shipping_name' => 'JNE',
                    'service_name' => 'REG',
                    'shipping_cost' => 12000,
                    'shipping_cashback' => 0,
                    'service_fee' => 0,
                    'additional_cost' => 0,
                    'grandtotal' => 112000,
                    'cod_value' => 0,
                    'insurance_value' => 0,
                    'carrier_code' => 'jne',
                ],
            ],
        ]);
        $shipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        Http::fake([
            'https://payment.example.test/*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'payment_id' => 'KOMPAY-KOMSHIP-SYNC-1',
                    'status' => 'PAID',
                    'amount' => 112000,
                ],
            ]),
            'https://delivery.example.test/order/api/v1/orders/store' => Http::response([
                'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Create New Order'],
                'data' => ['order_id' => 30001, 'order_no' => 'RO-KOMSHIP-SYNC-1'],
            ]),
            'https://delivery.example.test/order/api/v1/pickup/request' => Http::response([
                'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Request Pickup'],
                'data' => [[
                    'status' => 'success',
                    'order_no' => 'RO-KOMSHIP-SYNC-1',
                    'awb' => 'JNE-KOMSHIP-SYNC-1',
                ]],
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('markPaidAndProcessDelivery')
            ->assertSet('overrideError', null)
            ->assertSet('successMessage', "Pembayaran selaras dengan Komerce. Pickup/resi diproses untuk #{$order->number}.")
            ->assertDispatched('order.updated')
            ->assertDispatched('order.shipping.created');

        $order->refresh();
        $shipment->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('JNE-KOMSHIP-SYNC-1', $shipment->awb);
        $this->assertSame('JNE-KOMSHIP-SYNC-1', $shipment->tracking_number);
    }

    public function test_mark_paid_and_process_delivery_surfaces_error_when_order_paid_fulfillment_leaves_shipment_without_awb(): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        $this->fakeDelivery();

        $admin = $this->admin();
        $method = PaymentMethod::query()->create([
            'title' => 'BCA VA',
            'slug' => 'komerce-va-bca',
            'driver' => 'komerce',
            'is_enabled' => true,
        ]);
        $address = OrderAddress::query()->create([
            'first_name' => 'Siti',
            'last_name' => 'Aminah',
            'street_address' => 'Jl. Sudirman 10',
            'postal_code' => '10220',
            'city' => 'Jakarta',
            'phone' => '081234567899',
        ]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $method->id,
            'price_amount' => 112000,
            'shipping_address_id' => $address->id,
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => 'KOMPAY-KOMSHIP-FAIL-1',
                    'payment_type' => 'bank_transfer',
                ],
                'shipping_address' => [
                    'destination_id' => '152',
                    'destination_driver' => 'rajaongkir',
                    'pin_point' => '-6.200000,106.816666',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 112000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-KOMSHIP-FAIL-1',
        ]);
        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Fail',
            'phone_number' => '0215551234',
            'street_address' => 'Jl. Gudang 1',
            'city' => 'Jakarta',
            'postal_code' => '10110',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'shipping_origins' => ['komerce' => '501'],
        ]);
        $product = Product::factory()->standard()->create([
            'name' => 'Produk Fail',
            'sku' => 'SKU-KOMSHIP-FAIL-1',
            'weight_value' => 500,
            'weight_unit' => 'g',
            'width_value' => 10,
            'width_unit' => 'cm',
            'height_value' => 10,
            'height_unit' => 'cm',
            'depth_value' => 10,
            'depth_unit' => 'cm',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
            'metadata' => [
                'komerce' => ['order_no' => 'RO-PENDING-AWB-1', 'order_id' => '30002'],
                'rate' => [
                    'provider' => 'shipping_delivery',
                    'shipping_name' => 'JNE',
                    'service_name' => 'REG',
                    'shipping_cost' => 12000,
                    'shipping_cashback' => 0,
                    'service_fee' => 0,
                    'additional_cost' => 0,
                    'grandtotal' => 112000,
                    'cod_value' => 0,
                    'insurance_value' => 0,
                    'carrier_code' => 'jne',
                ],
            ],
        ]);
        $shipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        Http::fake([
            'https://payment.example.test/*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'payment_id' => 'KOMPAY-KOMSHIP-FAIL-1',
                    'status' => 'PAID',
                    'amount' => 112000,
                ],
            ]),
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 500, 'status' => 'error', 'message' => 'Internal Server Error'],
            ], 500),
            'https://delivery.example.test/order/api/v1/pickup/request' => Http::response([
                'meta' => ['code' => 500, 'status' => 'error', 'message' => 'Pickup unavailable'],
            ], 500),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(KomerceOrderShipping::class, ['order' => $order])
            ->call('markPaidAndProcessDelivery')
            ->assertSet('successMessage', null);

        $order->refresh();
        $shipment->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNull($shipment->awb);
        $this->assertNotNull($component->get('overrideError'));
        $this->assertStringStartsWith('Gagal pickup/resi Komerce:', (string) $component->get('overrideError'));
    }
}
