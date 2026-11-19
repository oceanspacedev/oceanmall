<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Models\OrderShipment;
use App\Models\Product;
use App\Shipping\InventoryOrigins;
use App\Support\EnabledCarriers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\OrderItem;
use Tests\TestCase;

/**
 * Shared setup for the Biteship module tests: hermetic config (a local
 * `.env` key must never plug Biteship in during a test run), no stray HTTP,
 * and a paid-order shipment ready for Biteship.
 */
abstract class BiteshipTestCase extends TestCase
{
    use RefreshDatabase;

    protected const BASE = 'https://api.biteship.com';

    protected const SECRET = 'whsec-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'biteship.api_key' => '',
            'biteship.base_url' => self::BASE,
            'biteship.webhook_secret' => '',
            'biteship.webhook_header' => 'X-Biteship-Secret',
            'biteship.allow_test_key_in_production' => false,
            'biteship.allow_live_key_outside_production' => false,
            'biteship.rate_cache_hours' => 12,
            'biteship.delivery_type' => 'now',
            'shopper.shipping.drivers.biteship.enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    protected function enableBiteship(): void
    {
        config([
            'biteship.api_key' => 'biteship_test.eyJhbGciOiJIUzI1NiJ9.test',
            'biteship.webhook_secret' => self::SECRET,
        ]);
    }

    /**
     * @param  list<string>  $codes
     */
    protected function biteshipCarriers(array $codes = ['jne', 'sicepat'], bool $enabled = true): void
    {
        foreach ($codes as $code) {
            Carrier::query()->create([
                'slug' => 'biteship-'.$code,
                'name' => strtoupper($code),
                'is_enabled' => $enabled,
                'driver' => 'biteship',
                'metadata' => [EnabledCarriers::COURIER_CODE => $code],
            ]);
        }
    }

    protected function warehouse(?string $areaId = 'IDNP9IDNC74IDND6IDZ45111'): Inventory
    {
        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Cirebon',
            'email' => 'gudang@oceanmall.test',
            'phone_number' => '02311234567',
            'street_address' => 'Jl. Gudang 10',
            'street_address_plus' => null,
            'city' => 'Cirebon',
            'postal_code' => '45111',
            'is_default' => true,
            'latitude' => -6.7366,
            'longitude' => 108.5523,
        ]);

        if ($areaId !== null) {
            resolve(InventoryOrigins::class)->put($inventory, 'biteship', $areaId, 'Kejaksan, Cirebon');
        }

        return $inventory;
    }

    /**
     * @param  array<string, mixed>  $shipmentOverrides
     * @return array{0: Order, 1: OrderShipment}
     */
    protected function paidShipment(array $shipmentOverrides = [], ?Inventory $inventory = null): array
    {
        $address = OrderAddress::query()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'phone' => '081234567890',
        ]);

        $order = Order::factory()->create([
            'number' => 'ORD-BS-1001',
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Paid,
            'shipping_address_id' => $address->id,
            'metadata' => json_encode([
                'shipping_address' => [
                    'country_id' => 1,
                    'destination_id' => 'IDNP6IDNC147IDND830IDZ10110',
                    'destination_driver' => 'biteship',
                    'destination_label' => 'Gambir, Jakarta Pusat',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $inventory ??= $this->warehouse();

        $shipment = OrderShipment::query()->create(array_merge([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'biteship',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'jne:reg',
            'service_name' => 'Reguler',
            'cost' => 18000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'metadata' => [
                'rate' => [
                    'service_code' => 'jne:reg',
                    'carrier_code' => 'jne',
                    'courier_company' => 'jne',
                    'courier_type' => 'reg',
                    'provider' => 'biteship',
                    'amount' => 18000,
                ],
            ],
        ], $shipmentOverrides));

        $product = Product::factory()->standard()->create([
            'name' => 'Kopi Cirebon',
            'sku' => 'KOPI-CIREBON-500',
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

        return [$order, $shipment];
    }

    /**
     * A Biteship order object as GET /v1/orders/{id} returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function biteshipOrder(string $status = 'confirmed', ?string $waybill = 'JNE-WB-001', array $overrides = []): array
    {
        return array_replace_recursive([
            'success' => true,
            'object' => 'order',
            'id' => 'BS-ORDER-1',
            'status' => $status,
            'price' => 18000,
            'courier' => [
                'tracking_id' => 'BS-TRACK-1',
                'waybill_id' => $waybill,
                'company' => 'jne',
                'type' => 'reg',
                'link' => null,
                'history' => [
                    ['service_type' => 'reg', 'status' => 'confirmed', 'note' => 'Order confirmed', 'updated_at' => '2026-11-19T09:00:00+07:00'],
                ],
            ],
        ], $overrides);
    }
}
