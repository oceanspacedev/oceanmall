<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Models\OrderShipment;
use App\Models\User;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PrintLabelTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDeliveryConfig(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
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

    /**
     * @return array{0: Order, 1: OrderShipment}
     */
    private function orderWithDeliveryOrder(?string $komerceOrderNo = 'RO-ORDER-777'): array
    {
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create();

        $metadata = $komerceOrderNo === null
            ? null
            : ['komerce' => ['order_no' => $komerceOrderNo, 'awb' => 'JNE-AWB-777']];

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'JNE-AWB-777',
            'metadata' => $metadata,
        ]);

        return [$order, $shipment];
    }

    public function test_print_label_client_posts_order_numbers_and_page_with_api_key(): void
    {
        $this->fakeDeliveryConfig();

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 200, 'message' => 'Generate Print Label Success', 'status' => 'success'],
                'data' => ['path' => 'https://delivery.example.test/storage/label/RO-1.pdf'],
            ]),
        ]);

        $response = resolve(ShippingDeliveryClient::class)->printLabel(['RO-1', 'RO-2'], 'page_5');

        $this->assertSame('https://delivery.example.test/storage/label/RO-1.pdf', data_get($response, 'data.path'));

        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://delivery.example.test/order/api/v1/orders/print-label')
                && $request->hasHeader('x-api-key', 'test-komerce-key')
                && ($query['order_no'] ?? null) === 'RO-1,RO-2'
                && ($query['page'] ?? null) === 'page_5';
        });
    }

    public function test_print_label_rejects_unsupported_page_format(): void
    {
        $this->fakeDeliveryConfig();
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        resolve(ShippingDeliveryClient::class)->printLabel(['RO-1'], 'page_99');
    }

    public function test_admin_can_print_shipment_label_and_is_redirected_to_pdf(): void
    {
        $this->fakeDeliveryConfig();
        [$order] = $this->orderWithDeliveryOrder();

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['path' => 'https://delivery.example.test/storage/label/RO-ORDER-777.pdf'],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertRedirect('https://delivery.example.test/storage/label/RO-ORDER-777.pdf');

        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://delivery.example.test/order/api/v1/orders/print-label')
                && ($query['order_no'] ?? null) === 'RO-ORDER-777'
                && ($query['page'] ?? null) === 'page_5';
        });
    }

    public function test_admin_print_label_resolves_relative_path_against_delivery_base(): void
    {
        $this->fakeDeliveryConfig();
        [$order] = $this->orderWithDeliveryOrder();

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['path' => '/storage/label-relative.pdf'],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertRedirect('https://delivery.example.test/order/storage/label-relative.pdf');
    }

    public function test_admin_print_label_streams_base64_pdf_when_path_missing(): void
    {
        $this->fakeDeliveryConfig();
        [$order] = $this->orderWithDeliveryOrder();

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['base_64' => base64_encode('%PDF-fake-label')],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('%PDF-fake-label', false);
    }

    public function test_print_label_requires_admin_role(): void
    {
        $this->configureShopperCpanel();
        $this->fakeDeliveryConfig();
        [$order] = $this->orderWithDeliveryOrder();
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->getJson(route('shopper.orders.fulfillment.print-label', $order))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_print_label_without_delivery_order_returns_error(): void
    {
        $this->fakeDeliveryConfig();
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['data' => []]),
        ]);
        [$order, $shipment] = $this->orderWithDeliveryOrder(komerceOrderNo: null);
        $shipment->update([
            'awb' => null,
            'tracking_number' => null,
            'status' => 'pending',
        ]);
        Http::fake();

        $this->from(route('shopper.orders.detail', $order))
            ->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSessionHasErrors('label');

        Http::assertNothingSent();
    }

    public function test_print_label_blocked_when_komerce_disabled(): void
    {
        config()->set('komerce.enabled', false);
        config()->set('komerce.shipping_delivery_api_key', '');
        [$order, $shipment] = $this->orderWithDeliveryOrder();
        $shipment->update([
            'awb' => null,
            'tracking_number' => null,
            'metadata' => ['komerce' => ['order_no' => 'RO-ORDER-777']],
        ]);
        Http::fake();

        $this->from(route('shopper.orders.detail', $order))
            ->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSessionHasErrors('label');

        Http::assertNothingSent();
    }

    public function test_komship_to_starter_downgrade_with_delivery_order_and_awb_prints_thermal_label_single_and_batch(): void
    {
        Http::fake();

        $admin = $this->admin();
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'number' => 'ORD-DOWN-LABEL',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inv1 = Inventory::factory()->create(['name' => 'Gudang Jakarta']);
        $inv2 = Inventory::factory()->create(['name' => 'Gudang Bandung']);

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', '');

        $labeledShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv1->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'external_reference' => 'RO-DOWNGRADE-1',
            'status' => 'labeled',
            'awb' => 'JNE-KOM-DOWNGRADE-1',
            'tracking_number' => 'JNE-KOM-DOWNGRADE-1',
            'metadata' => ['komerce' => ['order_no' => 'RO-DOWNGRADE-1', 'awb' => 'JNE-KOM-DOWNGRADE-1']],
        ]);

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv2->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
            'awb' => null,
        ]);

        // Single shipment route renders thermal-label 200 (not 422)
        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', ['order' => $order, 'shipment' => $labeledShipment->id]))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-KOM-DOWNGRADE-1', false);

        // Batch route renders thermal-label 200 including the labeled parcel
        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-KOM-DOWNGRADE-1', false);

        Http::assertNothingSent();
    }

    public function test_keyless_manual_checkout_order_with_rate_metadata_renders_thermal_label(): void
    {
        config()->set('komerce.enabled', false);
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');
        Http::fake();

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create();
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'status' => 'labeled',
            'awb' => 'MAN-KEYLESS-01',
            'tracking_number' => 'MAN-KEYLESS-01',
            'metadata' => [
                'rate' => [
                    'provider' => 'manual',
                    'carrier_code' => 'manual',
                    'service_name' => 'Reguler',
                    'shipping_cost' => 10000,
                ],
            ],
        ]);

        $this->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('MAN-KEYLESS-01', false);

        Http::assertNothingSent();
    }

    public function test_starter_manual_awb_renders_thermal_label_and_filters_unlabeled_shipments(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.shipping_delivery_api_key', '');
        Http::fake();

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'number' => 'ORD-STARTER-LBL',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventoryA = Inventory::factory()->create([
            'name' => 'Gudang A',
            'shipping_origins' => ['rajaongkir' => '501'],
        ]);
        $inventoryB = Inventory::factory()->create([
            'name' => 'Gudang B',
            'shipping_origins' => ['rajaongkir' => '502'],
        ]);

        $labeled = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventoryA->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'JNE-STARTER-1',
            'tracking_number' => 'JNE-STARTER-1',
            'metadata' => [
                'rate' => [
                    'provider' => 'rajaongkir',
                    'carrier_code' => 'jne',
                    'service_name' => 'REG',
                ],
            ],
        ]);

        $unlabeled = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventoryB->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
            'metadata' => [
                'rate' => [
                    'provider' => 'rajaongkir',
                    'carrier_code' => 'jne',
                    'service_name' => 'REG',
                ],
            ],
        ]);

        $admin = $this->admin();

        // Batch print renders only the labeled parcel, not the unlabeled one.
        $batch = $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-STARTER-1', false);

        $this->assertSame(1, substr_count($batch->getContent(), 'class="label-container"'));

        // Per-shipment print works for the labeled parcel and refuses the unlabeled parcel.
        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', ['order' => $order, 'shipment' => $labeled->id]))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-STARTER-1', false);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', ['order' => $order, 'shipment' => $unlabeled->id]))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSessionHasErrors('label');

        Http::assertNothingSent();
    }

    public function test_target_blank_label_error_without_referer_renders_422_error_page_without_redirecting_to_home(): void
    {
        $this->fakeDeliveryConfig();
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['data' => []]),
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 401, 'status' => 'error', 'message' => 'Invalid API Key'],
            ], 401),
        ]);

        [$order] = $this->orderWithDeliveryOrder('RO-REVOKED-1');

        $this->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSee($order->number, false)
            ->assertSee(route('shopper.orders.detail', $order), false)
            ->assertSee('Tutup Tab', false)
            ->assertSessionHasErrors('label');
    }

    public function test_manual_awb_renders_thermal_label_after_komerce_delivery_revoked_or_rajaongkir_keyless_downgrade(): void
    {
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');
        Http::fake();

        $admin = $this->admin();
        $presenter = resolve(OrderShipmentOpsPresenter::class);

        // 1. Komship -> Delivery revoked -> manual AWB fallback (no delivery order_no)
        $komerceOrder = Order::factory()->create([
            'currency_code' => 'IDR',
            'number' => 'ORD-KOM-DOWN',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $komerceInv = Inventory::factory()->create(['name' => 'Gudang Komerce']);
        $komerceShipment = OrderShipment::query()->create([
            'order_id' => $komerceOrder->id,
            'inventory_id' => $komerceInv->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'JNE-KOM-MANUAL-1',
            'tracking_number' => 'JNE-KOM-MANUAL-1',
        ]);

        $this->assertTrue($presenter->canPrintLabel($komerceShipment));
        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $komerceOrder))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-KOM-MANUAL-1', false);

        // 2. Starter -> Keyless downgrade -> manual AWB
        $rajaOrder = Order::factory()->create([
            'currency_code' => 'IDR',
            'number' => 'ORD-RAJA-DOWN',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $rajaShipment = OrderShipment::query()->create([
            'order_id' => $rajaOrder->id,
            'inventory_id' => $komerceInv->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'JNE-RAJA-KEYLESS-1',
            'tracking_number' => 'JNE-RAJA-KEYLESS-1',
        ]);

        $this->assertTrue($presenter->canPrintLabel($rajaShipment));
        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $rajaOrder))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-RAJA-KEYLESS-1', false);

        Http::assertNothingSent();
    }

    public function test_unregistered_driver_hides_print_button_and_refuses_label_print(): void
    {
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');
        Http::fake();

        $admin = $this->admin();
        $inventory = Inventory::factory()->create();
        $carrier = Carrier::query()->create([
            'name' => 'Acme Express',
            'slug' => 'acme-express',
            'driver' => 'acme',
            'is_enabled' => true,
        ]);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'acme',
            'carrier_code' => 'acme-express',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'ACME-AWB-99',
            'tracking_number' => 'ACME-AWB-99',
        ]);
        $shipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $carrier->id,
            'shipped_at' => now(),
            'tracking_number' => 'ACME-AWB-99',
        ]);

        $this->assertFalse(resolve(OrderShipmentOpsPresenter::class)->canPrintLabel($shipment));

        Livewire::actingAs($admin)
            ->test(OrderShipments::class, ['order' => $order])
            ->assertTableActionHidden('printProviderLabel', $shipping);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSessionHasErrors('label');
    }

    public function test_cancelled_shipments_and_invalid_page_query_return_clean_422_label_error(): void
    {
        $this->fakeDeliveryConfig();
        Http::fake();

        $admin = $this->admin();
        $inventory = Inventory::factory()->create();

        // 1. Single & batch cancelled Komship shipment with AWB + order_no
        $orderCancelled = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $cancelled = OrderShipment::query()->create([
            'order_id' => $orderCancelled->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'cancelled',
            'awb' => 'JNE-CANCEL-1',
            'tracking_number' => 'JNE-CANCEL-1',
            'external_reference' => 'RO-CANCEL-1',
            'metadata' => ['komerce' => ['order_no' => 'RO-CANCEL-1']],
        ]);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', ['order' => $orderCancelled, 'shipment' => $cancelled->id]))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSee('Stiker resi tidak tersedia untuk pengiriman yang sudah dibatalkan.', false)
            ->assertDontSee('Menunggu nomor resi (AWB)', false);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $orderCancelled))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSee('Stiker resi tidak tersedia untuk pengiriman yang sudah dibatalkan.', false);

        // 2. Mixed order: 1 active pending (no AWB/order_no) + 1 cancelled with order_no
        $orderMixed = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $orderMixed->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
        ]);
        OrderShipment::query()->create([
            'order_id' => $orderMixed->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'cancelled',
            'awb' => 'JNE-CANCEL-2',
            'external_reference' => 'RO-CANCEL-2',
            'metadata' => ['komerce' => ['order_no' => 'RO-CANCEL-2']],
        ]);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $orderMixed))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSee('Stiker resi tidak tersedia untuk pengiriman yang sudah dibatalkan.', false);

        // 3. Invalid ?page= query returns 422 error page directly instead of 302 redirect to /
        $validOrder = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $validOrder->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'JNE-PAGE-CHECK',
            'external_reference' => 'RO-VALID-PAGE-CHECK',
            'metadata' => ['komerce' => ['order_no' => 'RO-VALID-PAGE-CHECK', 'awb' => 'JNE-PAGE-CHECK']],
        ]);

        Http::fake();

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', ['order' => $validOrder, 'page' => 'bad!page']))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSessionHasErrors('label');

        Http::assertNothingSent();
    }

    public function test_http_error_on_print_label_sanitizes_raw_json_payload(): void
    {
        $this->fakeDeliveryConfig();
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['data' => []]),
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 401, 'status' => 'error', 'message' => 'Invalid API Key'],
            ], 401),
        ]);

        [$order] = $this->orderWithDeliveryOrder('RO-SANITIZE-401');

        $this->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertDontSee('HTTP request returned status code', false)
            ->assertDontSee('Invalid API Key', false)
            ->assertSee('Akses layanan cetak label pengiriman tidak aktif atau ditolak penyedia', false)
            ->assertSessionHasErrors('label');
    }
}
