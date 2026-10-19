<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Jobs\CreateRajaOngkirDeliveryForShipment;
use App\Models\OrderShipment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\OrderItem;
use Tests\TestCase;

/**
 * A long-lived queue:work process runs several delivery jobs with one memoized
 * Shopper komerce driver; Laravel flushes scoped instances between jobs.
 */
final class DeliveryJobScopedContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_delivery_jobs_in_one_worker_both_get_an_awb(): void
    {
        config()->set('komerce.shipping_delivery_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('komerce.pickup_time', '10:00:00');
        config()->set('komerce.pickup_vehicle', 'Motor');

        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Cirebon',
            'email' => 'gudang@oceanmall.test',
            'phone_number' => '02311234567',
            'street_address' => 'Jl. Gudang 10',
            'city' => 'Cirebon',
            'postal_code' => '45111',
            'rajaongkir_origin_id' => '501',
        ]);
        $first = $this->shipmentReadyForDelivery('ORDER-2001', $inventory);
        $second = $this->shipmentReadyForDelivery('ORDER-2002', $inventory);

        $stored = 0;
        Http::fake([
            'https://delivery.example.test/order/api/v1/orders/store' => function () use (&$stored) {
                $stored++;

                return Http::response([
                    'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Create New Order'],
                    'data' => ['order_id' => 9000 + $stored, 'order_no' => "RO-ORDER-{$stored}"],
                ]);
            },
            'https://delivery.example.test/order/api/v1/pickup/request' => fn (Request $request) => Http::response([
                'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Request Pickup'],
                'data' => [[
                    'status' => 'success',
                    'order_no' => data_get($request->data(), 'orders.0.order_no'),
                    'awb' => 'AWB-'.data_get($request->data(), 'orders.0.order_no'),
                ]],
            ]),
            'https://delivery.example.test/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['path' => 'https://delivery.example.test/storage/label/label.pdf'],
            ]),
        ]);

        resolve(CreateRajaOngkirDeliveryForShipment::class, ['orderShipmentId' => $first->id])->handle();

        // What the queue worker does before every job (QueueServiceProvider).
        app()->forgetScopedInstances();

        resolve(CreateRajaOngkirDeliveryForShipment::class, ['orderShipmentId' => $second->id])->handle();

        $this->assertSame('AWB-RO-ORDER-1', $first->refresh()->awb);
        $this->assertSame('AWB-RO-ORDER-2', $second->refresh()->awb);
    }

    private function shipmentReadyForDelivery(string $orderNumber, Inventory $inventory): OrderShipment
    {
        $address = OrderAddress::query()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '10110',
            'city' => 'Jakarta',
            'phone' => '081234567890',
        ]);

        $order = Order::factory()->create([
            'number' => $orderNumber,
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_address_id' => $address->id,
            'metadata' => json_encode([
                'komerce' => ['payment_type' => 'bank_transfer'],
                'shipping_address' => ['country_id' => 1, 'rajaongkir_destination_id' => '152'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Reguler',
            'cost' => 18000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'metadata' => [
                'rate' => [
                    'provider' => 'shipping_delivery',
                    'shipping_name' => 'JNE',
                    'service_name' => 'REG23',
                    'shipping_cost' => 18000,
                    'shipping_cashback' => 4500,
                    'service_fee' => 0,
                    'additional_cost' => 0,
                    'grandtotal' => 118000,
                    'cod_value' => 0,
                    'insurance_value' => 0,
                ],
            ],
        ]);

        $product = Product::factory()->standard()->create([
            'name' => 'Kopi Cirebon',
            'weight_value' => 500,
            'weight_unit' => 'g',
            'width_value' => 12,
            'width_unit' => 'cm',
            'height_value' => 8,
            'height_unit' => 'cm',
            'depth_value' => 20,
            'depth_unit' => 'cm',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price_amount' => 50000,
        ]);

        $shipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        return $shipment;
    }
}
