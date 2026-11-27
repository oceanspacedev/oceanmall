<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Models\OrderShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Tests\Feature\Komerce\Concerns\SignsKomercePaymentCallbacks;
use Tests\TestCase;

final class DeliveryWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SignsKomercePaymentCallbacks;

    public function test_delivery_webhook_updates_shipment_status_from_komerce_payload(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create();
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'status' => 'labeled',
            'metadata' => [
                'komerce' => [
                    'order_no' => 'RO-ORDER-WH-1',
                    'awb' => 'JNE-OLD',
                ],
            ],
        ]);

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'order_no' => 'RO-ORDER-WH-1',
                    'airway_bill' => 'JNE-NEW-AWB',
                    'last_status' => 'Selesai',
                ],
            ]),
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'airway_bill' => 'JNE-NEW-AWB',
                    'last_status' => 'Selesai',
                    'history' => [[
                        'desc' => 'Paket telah diterima',
                        'date' => '2026-08-12 10:00:00',
                        'code' => '200',
                        'status' => 'Selesai',
                    ]],
                ],
            ]),
        ]);

        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-ORDER-WH-1',
            'cnote' => 'JNE-NEW-AWB',
            'status' => 'Selesai',
        ])
            ->assertOk()
            ->assertJson(['status' => 'handled']);

        $shipment->refresh();
        $this->assertSame('delivered', $shipment->status);
        $this->assertSame('JNE-NEW-AWB', $shipment->awb);
        $this->assertSame('Selesai', data_get($shipment->metadata, 'komerce.webhook_reported_status'));
        $this->assertSame('Selesai', data_get($shipment->metadata, 'komerce.tracking_status'));
    }

    public function test_delivery_webhook_finds_the_shipment_by_its_indexed_external_reference(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'order_no' => 'RO-REF-1',
                    'airway_bill' => 'JNE-REF-AWB',
                    'last_status' => 'ON_PROCESS',
                ],
            ]),
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['airway_bill' => 'JNE-REF-AWB', 'last_status' => 'ON_PROCESS', 'history' => []],
            ]),
        ]);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        // A RajaOngkir-quoted parcel registered with Delivery: the provider
        // order number lives only in the neutral column (no metadata bucket).
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => 'rajaongkir',
            'external_reference' => 'RO-REF-1',
            'carrier_code' => 'jne',
            'status' => 'labeled',
        ]);
        // Same number on a parcel of an unrelated driver is never matched.
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => 'otherprovider',
            'external_reference' => 'RO-REF-1',
            'carrier_code' => 'jne',
            'status' => 'labeled',
        ]);

        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-REF-1',
            'cnote' => 'JNE-REF-AWB',
            'status' => 'Dikirim',
        ])->assertOk()->assertJson(['status' => 'handled']);

        $shipment->refresh();
        $this->assertSame('JNE-REF-AWB', $shipment->awb);
        $this->assertSame('in_transit', $shipment->status);
        $this->assertSame('Dikirim', data_get($shipment->metadata, 'komerce.webhook_reported_status'));
    }

    public function test_delivery_webhook_returns_404_when_shipment_missing(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');

        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-MISSING',
            'cnote' => 'AWB-MISSING',
            'status' => 'Dikirim',
        ])->assertOk()->assertJson(['status' => 'handled']);
    }

    public function test_delivery_webhook_accepts_official_put_method(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');

        $this->putJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-MISSING',
            'cnote' => 'AWB-MISSING',
            'status' => 'Dikirim',
        ])->assertOk()->assertJson(['status' => 'handled']);
    }

    public function test_delivery_webhook_rejects_incomplete_official_payload(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');

        $this->postJson(route('webhooks.komerce.delivery'), [
            'cnote' => 'JNE-FORGED',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_no', 'status']);
    }

    public function test_delivery_webhook_does_not_apply_unverified_reported_status(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'order_no' => 'RO-VERIFY',
                    'airway_bill' => 'JNE-REAL-AWB',
                    'last_status' => 'ON_PROCESS',
                ],
            ]),
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'airway_bill' => 'JNE-REAL-AWB',
                    'last_status' => 'ON_PROCESS',
                    'history' => [],
                ],
            ]),
        ]);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'carrier_code' => 'jne',
            'status' => 'labeled',
            'awb' => 'JNE-REAL-AWB',
            'metadata' => ['komerce' => ['order_no' => 'RO-VERIFY']],
        ]);

        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-VERIFY',
            'cnote' => 'JNE-REAL-AWB',
            'status' => 'Selesai',
        ])->assertOk();

        $this->assertSame('in_transit', $shipment->refresh()->status);
        $this->assertSame('Selesai', data_get($shipment->metadata, 'komerce.webhook_reported_status'));
        $this->assertSame('ON_PROCESS', data_get($shipment->metadata, 'komerce.tracking_status'));
    }

    public function test_delivery_webhook_ignores_forged_cnote_and_cannot_mark_order_delivered(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'order_no' => 'RO-VICTIM-1',
                    'airway_bill' => 'JNE-VICTIM-REAL',
                    'last_status' => 'ON_PROCESS',
                ],
            ]),
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'airway_bill' => 'JNE-VICTIM-REAL',
                    'last_status' => 'ON_PROCESS',
                    'history' => [],
                ],
            ]),
        ]);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => 'rajaongkir',
            'external_reference' => 'RO-VICTIM-1',
            'carrier_code' => 'jne',
            'awb' => 'JNE-VICTIM-REAL',
            'tracking_number' => 'JNE-VICTIM-REAL',
            'status' => 'in_transit',
        ]);

        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-VICTIM-1',
            'cnote' => 'JNE-ATTACKER-DELIVERED',
            'status' => 'Selesai',
        ])->assertOk();

        $shipment->refresh();
        $this->assertSame('JNE-VICTIM-REAL', $shipment->awb);
        $this->assertSame('in_transit', $shipment->status);
        $this->assertNotSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_delivery_webhook_returns_503_with_retry_after_when_detail_lookup_fails(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create(['shipping_origins' => ['komerce' => '17248']])->id,
            'driver' => 'komerce',
            'external_reference' => 'RO-ERR-1',
            'carrier_code' => 'jne',
            'status' => 'labeled',
        ]);

        foreach ([401, 429, 500] as $status) {
            Http::fake([
                'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response(
                    ['meta' => ['code' => $status, 'status' => 'error', 'message' => "HTTP {$status}"]],
                    $status,
                ),
            ]);

            $this->postJson(route('webhooks.komerce.delivery'), [
                'order_no' => 'RO-ERR-1',
                'cnote' => 'JNE-ERR',
                'status' => 'Selesai',
            ])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '30')
                ->assertJson(['status' => 'lookup_failed']);

            $this->assertSame('labeled', $shipment->refresh()->status);
            resolve(KomerceCapabilities::class)->forget();
        }
    }

    public function test_delivery_webhook_returns_503_when_tracking_refresh_fails_after_detail_succeeds(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');

        $existingHistory = [['description' => 'Picked up', 'datetime' => '2026-08-12 09:00', 'location' => null]];
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create(['shipping_origins' => ['komerce' => '17248']])->id,
            'driver' => 'komerce',
            'external_reference' => 'RO-TRK-FAIL',
            'carrier_code' => 'jne',
            'awb' => 'JNE-TRK-FAIL',
            'status' => 'labeled',
            'metadata' => [
                'komerce' => [
                    'order_no' => 'RO-TRK-FAIL',
                    'tracking_history' => $existingHistory,
                ],
            ],
        ]);

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'order_no' => 'RO-TRK-FAIL',
                    'airway_bill' => 'JNE-TRK-FAIL',
                    'last_status' => 'ON_PROCESS',
                ],
            ]),
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response(
                ['meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded']],
                429,
            ),
        ]);

        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-TRK-FAIL',
            'cnote' => 'JNE-TRK-FAIL',
            'status' => 'Dikirim',
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        $this->assertSame($existingHistory, data_get($shipment->refresh()->metadata, 'komerce.tracking_history'));
    }

    public function test_delivery_webhook_does_not_persist_order_no_when_detail_verification_fails_but_adopts_when_verified(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create(['shipping_origins' => ['komerce' => '17248']])->id,
            'driver' => 'rajaongkir',
            'external_reference' => null,
            'carrier_code' => 'jne',
            'awb' => 'JNE-VERIFY-ADOPT',
            'tracking_number' => 'JNE-VERIFY-ADOPT',
            'status' => 'labeled',
            'metadata' => [],
        ]);

        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/detail*' => Http::sequence()
                ->push([
                    'meta' => ['code' => 404, 'status' => 'error', 'message' => 'Order not found'],
                ], 404)
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success'],
                    'data' => [
                        'order_no' => 'RO-REAL-ADOPT',
                        'airway_bill' => 'JNE-VERIFY-ADOPT',
                        'last_status' => 'ON_PROCESS',
                    ],
                ], 200),
            'https://delivery.example.test/order/api/v1/orders/history-airway-bill*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'airway_bill' => 'JNE-VERIFY-ADOPT',
                    'last_status' => 'ON_PROCESS',
                    'history' => [],
                ],
            ]),
        ]);

        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-SPOOF-404',
            'cnote' => 'JNE-VERIFY-ADOPT',
            'status' => 'ON_PROCESS',
        ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJson(['status' => 'lookup_failed']);

        $shipment->refresh();
        $this->assertNull($shipment->external_reference);
        $this->assertNull(data_get($shipment->metadata, 'komerce.order_no'));

        // Once detailOrder verifies the order_no AND matching AWB, it is safely adopted
        $this->postJson(route('webhooks.komerce.delivery'), [
            'order_no' => 'RO-REAL-ADOPT',
            'cnote' => 'JNE-VERIFY-ADOPT',
            'status' => 'Dikirim',
        ])
            ->assertOk()
            ->assertJson(['status' => 'handled']);

        $shipment->refresh();
        $this->assertSame('RO-REAL-ADOPT', $shipment->external_reference);
        $this->assertSame('RO-REAL-ADOPT', data_get($shipment->metadata, 'komerce.order_no'));
        $this->assertSame('in_transit', $shipment->status);
    }
}
