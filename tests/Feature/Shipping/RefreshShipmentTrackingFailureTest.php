<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\RefreshShipmentTracking;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Models\OrderShipment;
use App\Models\User;
use App\Shipping\ShipmentMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Tests\TestCase;

final class RefreshShipmentTrackingFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_refresh_surfaces_indonesian_error_preserves_history_and_does_not_spend_cooldown(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');

        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Shipped,
        ]);
        $inventory = Inventory::factory()->create(['rajaongkir_origin_id' => '17248']);

        $existingHistory = [
            ['description' => 'Paket dijemput kurir', 'datetime' => '2026-10-12 10:00', 'location' => 'Jakarta'],
        ];

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'in_transit',
            'awb' => 'JNE-HIST-1',
            'tracking_number' => 'JNE-HIST-1',
            'metadata' => [
                ShipmentMetadata::TRACKING_HISTORY => $existingHistory,
                'komerce' => [
                    'tracking_history' => $existingHistory,
                ],
            ],
        ]);

        Http::fake([
            'https://cost.example.test/api/v1/track/waybill*' => Http::sequence()
                ->push(['meta' => ['code' => 500, 'status' => 'error', 'message' => 'Upstream error']], 500)
                ->push(['meta' => ['code' => 500, 'status' => 'error', 'message' => 'Upstream error']], 500)
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success'],
                    'data' => [
                        'summary' => ['waybill_number' => 'JNE-HIST-1'],
                        'delivery_status' => ['status' => 'DELIVERED'],
                        'delivered' => true,
                        'manifest' => [[
                            'manifest_code' => '200',
                            'manifest_description' => 'Diterima oleh Budi',
                            'manifest_date' => '2026-10-13',
                            'manifest_time' => '12:00',
                            'city_name' => 'Bandung',
                        ]],
                    ],
                ], 200),
        ]);

        // Direct handle() throws Indonesian error instead of silently returning stale history.
        try {
            resolve(RefreshShipmentTracking::class)->handle($shipment);
            $this->fail('Expected RuntimeException when tracking provider fails.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('API error from', $e->getMessage());
            $this->assertStringContainsString('Gagal memperbarui pelacakan', $e->getMessage());
        }

        // Existing history and status are preserved intact.
        $shipment->refresh();
        $this->assertSame('in_transit', $shipment->status);
        $this->assertSame($existingHistory, data_get($shipment->metadata, 'komerce.tracking_history'));
        $this->assertSame($existingHistory, data_get($shipment->metadata, ShipmentMetadata::TRACKING_HISTORY));

        // Web customer route returns error (and does NOT consume the 15-min cooldown).
        $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.shipments.track', ['order' => $order, 'shipment' => $shipment]))
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionHasErrors('tracking');

        // Immediately retrying after recovery succeeds because cooldown was freed on failure.
        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', true);

        $this->assertSame('delivered', $shipment->refresh()->status);
    }

    public function test_quota_and_structural_tracking_errors_map_to_indonesian_messages(): void
    {
        $this->requireModules('Komerce');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 15:00:00', 'Asia/Jakarta'));
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');

        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Shipped,
        ]);
        $inventory = Inventory::factory()->create(['rajaongkir_origin_id' => '17248']);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'status' => 'in_transit',
            'awb' => 'JNE-QUOTA-1',
            'tracking_number' => 'JNE-QUOTA-1',
            'metadata' => [
                'komerce' => [
                    'tracking_history' => [['description' => 'Old event', 'datetime' => '2026-10-12 09:00', 'location' => null]],
                ],
            ],
        ]);

        Http::fake([
            'https://cost.example.test/api/v1/track/waybill*' => Http::response(
                ['meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded']],
                429,
            ),
        ]);

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json
                ->where('message', fn (string $msg): bool => str_contains($msg, 'Batas pelacakan harian kurir')
                    && str_contains($msg, 'WIB')));

        // Subsequent tap when quota already exhausted makes zero HTTP calls and still returns Indonesian copy.
        $this->assertTrue(resolve(KomerceCapabilities::class)->quotaExhausted('shipping_cost'));
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")
            ->assertStatus(422);
        Http::assertSentCount(1);

        // Missing courier maps to Indonesian courier message.
        $noCourier = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => null,
            'status' => 'in_transit',
            'awb' => 'JNE-NOCOURIER',
        ]);

        try {
            resolve(RefreshShipmentTracking::class)->handle($noCourier);
            $this->fail('Expected RuntimeException for missing courier.');
        } catch (RuntimeException $e) {
            $this->assertSame('Data kurir pengiriman belum lengkap untuk pelacakan.', $e->getMessage());
        }

        CarbonImmutable::setTestNow();
    }

    public function test_http_200_quota_envelope_preserves_history_and_cooldown_for_cost_and_delivery_tracking(): void
    {
        $this->requireModules('Komerce');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 15:00:00', 'Asia/Jakarta'));
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');

        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Shipped,
        ]);
        $inventory = Inventory::factory()->create(['rajaongkir_origin_id' => '17248']);
        $existingHistory = [
            ['description' => 'Paket sedang dikirim', 'datetime' => '2026-10-12 09:00', 'location' => 'Cirebon'],
        ];

        $costShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'status' => 'in_transit',
            'awb' => 'JNE-200-ERR',
            'tracking_number' => 'JNE-200-ERR',
            'metadata' => ['komerce' => ['tracking_history' => $existingHistory]],
        ]);

        $deliveryShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'external_reference' => 'KOM-ORD-1',
            'status' => 'in_transit',
            'awb' => 'JNE-DEL-200-ERR',
            'tracking_number' => 'JNE-DEL-200-ERR',
            'metadata' => ['komerce' => ['tracking_history' => $existingHistory]],
        ]);

        Http::fake([
            'https://cost.example.test/api/v1/track/waybill*' => Http::response([
                'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded'],
                'data' => null,
            ], 200),
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded'],
                'data' => null,
            ], 200),
        ]);

        $action = resolve(RefreshShipmentTracking::class);

        foreach ([$costShipment, $deliveryShipment] as $shipment) {
            try {
                $action->handleForCustomer($shipment);
                $this->fail('Expected RuntimeException on HTTP 200 quota envelope.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Batas pelacakan harian kurir', $e->getMessage());
                $this->assertStringContainsString('WIB', $e->getMessage());
            }

            $shipment->refresh();
            $this->assertSame($existingHistory, data_get($shipment->metadata, 'komerce.tracking_history'));
            $this->assertFalse(Cache::has('shipment-track-cooldown:'.$shipment->getKey()));
        }

        $capabilities = resolve(KomerceCapabilities::class);
        $this->assertTrue($capabilities->quotaExhausted('shipping_cost'));
        $this->assertTrue($capabilities->quotaExhausted('shipping_delivery'));

        CarbonImmutable::setTestNow();
    }
}
