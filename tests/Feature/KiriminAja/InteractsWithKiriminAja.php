<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Models\OrderShipment;
use App\Shipping\InventoryOrigins;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Zone;

/**
 * Shared fixtures of the KiriminAja module tests (sandbox-shaped answers from
 * developer.kiriminaja.com, fake host).
 */
trait InteractsWithKiriminAja
{
    protected const BASE_URL = 'https://ka.example.test';

    protected const API_KEY = 'ka-test-key';

    protected function configureKiriminAja(array $overrides = []): void
    {
        config()->set('kiriminaja.api_key', self::API_KEY);
        config()->set('kiriminaja.base_url', self::BASE_URL);
        config()->set('kiriminaja.webhook_secret', '');
        config()->set('kiriminaja.insurance', false);
        config()->set('kiriminaja.pickup.schedule', 'auto');
        config()->set('shopper.shipping.drivers.kiriminaja.enabled', true);
        // Other provider modules stay out of the way.
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');

        foreach ($overrides as $key => $value) {
            config()->set($key, $value);
        }
    }

    protected function disableKiriminAja(): void
    {
        config()->set('kiriminaja.api_key', '');
        config()->set('kiriminaja.base_url', self::BASE_URL);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function addressesResponse(): array
    {
        return [
            'status' => true,
            'text' => 'Success',
            'method' => 'getAddresses',
            'data' => [
                ['province_id' => 9, 'city_id' => 113, 'district_id' => 1535, 'subdistrict_id' => 22001, 'full_address' => 'Kesambi, Kesambi, Kota Cirebon, Jawa Barat, 45134'],
                ['province_id' => 9, 'city_id' => 113, 'district_id' => 1535, 'subdistrict_id' => 22002, 'full_address' => 'Pekiringan, Kesambi, Kota Cirebon, Jawa Barat, 45131'],
                ['province_id' => 9, 'city_id' => 113, 'district_id' => 1536, 'subdistrict_id' => 22010, 'full_address' => 'Lemahwungkuk, Lemahwungkuk, Kota Cirebon, Jawa Barat, 45111'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function priceResponse(): array
    {
        return [
            'status' => true,
            'method' => 'shipping_price',
            'text' => 'Success',
            'details' => ['origin' => 1536, 'destination' => 1535],
            'results' => [
                ['service' => 'jne', 'service_name' => 'JNE Reguler', 'service_type' => 'REG23', 'cost' => '12000', 'etd' => '2-3', 'cod' => true, 'group' => 'regular', 'drop' => true, 'force_insurance' => false, 'insurance' => 2500],
                ['service' => 'sicepat', 'service_name' => 'SiCepat REG', 'service_type' => 'REG', 'cost' => '11000', 'etd' => '1-2 hari', 'cod' => true, 'group' => 'regular', 'drop' => true, 'force_insurance' => true, 'insurance' => 500],
                ['service' => 'jnt', 'service_name' => 'J&T EZ', 'service_type' => 'EZ', 'cost' => '0', 'etd' => '2-3', 'group' => 'regular'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function trackingResponse(string $orderId, ?string $awb, int $statusCode = 100, bool $delivered = false, ?string $shippedAt = null): array
    {
        return [
            'status' => true,
            'text' => $delivered ? 'Delivered to BUDI' : 'Paket dibuat oleh KiriminAja',
            'method' => 'shTracking',
            'status_code' => $statusCode,
            'details' => [
                'awb' => $awb,
                'order_id' => $orderId,
                'service' => 'jne',
                'service_name' => 'REG',
                'shipped_at' => $shippedAt,
                'delivered' => $delivered,
                'delivered_at' => $delivered ? '2026-11-20 16:00:00' : null,
                'refunded' => false,
                'images' => ['camera_img' => 'https://example.test/pod.jpeg'],
            ],
            'histories' => array_values(array_filter([
                $delivered ? ['created_at' => '2026-11-20 16:00:00', 'status' => 'Delivered to BUDI', 'status_code' => 200] : null,
                $shippedAt !== null ? ['created_at' => '2026-11-19 18:34:00', 'status' => 'Received at sorting center CIREBON', 'status_code' => 100] : null,
                ['created_at' => '2026-11-19 14:40:42', 'status' => 'Paket dibuat oleh KiriminAja', 'status_code' => 100],
            ])),
        ];
    }

    protected function warehouse(?string $origin = '1536:22010'): Inventory
    {
        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Cirebon',
            'email' => 'gudang@example.com',
            'phone_number' => '0231 123 4567',
            'street_address' => 'Jl. Lemahwungkuk No. 10',
            'street_address_plus' => null,
            'city' => 'Kota Cirebon',
            'postal_code' => '45111',
            'latitude' => -6.7366,
            'longitude' => 108.5414,
            'is_default' => true,
        ]);

        if ($origin !== null) {
            resolve(InventoryOrigins::class)->put($inventory, KiriminAjaDriver::CODE, $origin, 'Lemahwungkuk');
        }

        return $inventory;
    }

    /**
     * A paid order with one KiriminAja shipment (2 × 500 g coffee, Rp50.000 each)
     * whose selected rate is JNE REG23 at Rp12.000.
     *
     * @param  array<string, mixed>  $shipmentOverrides
     * @param  array<string, mixed>  $orderOverrides
     * @return array{0: Order, 1: OrderShipment}
     */
    protected function paidShipment(array $shipmentOverrides = [], array $orderOverrides = []): array
    {
        $address = OrderAddress::query()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Kesambi Raya No. 5',
            'postal_code' => '45134',
            'city' => 'Kota Cirebon',
            'phone' => '+62 812-3456-7890',
        ]);

        $order = Order::factory()->create([
            'number' => 'ORD-KA-1',
            'price_amount' => 112000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Paid,
            'shipping_address_id' => $address->id,
            'metadata' => json_encode([
                'shipping_address' => [
                    'destination_id' => '1535:22001',
                    'destination_driver' => KiriminAjaDriver::CODE,
                    'pin_point' => '-6.7200,108.5500',
                ],
            ], JSON_THROW_ON_ERROR),
            ...$orderOverrides,
        ]);

        $inventory = Inventory::query()->where('is_default', true)->first() ?? $this->warehouse();

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => KiriminAjaDriver::CODE,
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE Express',
            'service_code' => 'jne:REG23',
            'service_name' => 'JNE Reguler',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'metadata' => [
                'rate' => [
                    'service_code' => 'jne:REG23',
                    'driver' => KiriminAjaDriver::CODE,
                    'amount' => 12000,
                    'kiriminaja_service' => 'jne',
                    'kiriminaja_service_type' => 'REG23',
                    'kiriminaja_cost' => 12000,
                    'kiriminaja_insurance' => 0,
                ],
            ],
            ...$shipmentOverrides,
        ]);

        $product = Product::factory()->standard()->create([
            'name' => 'Kopi Cirebon',
            'sku' => 'KOPI-500',
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

        return [$order, $shipment->refresh()];
    }

    /**
     * @return array{0: Zone, 1: Country}
     */
    protected function indonesiaZone(): array
    {
        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        return [$zone, $country];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function kiriminAjaCarrier(string $code, ?Zone $zone = null, array $attributes = []): Carrier
    {
        $carrier = Carrier::query()->create([
            'slug' => $code,
            'name' => strtoupper($code),
            'driver' => KiriminAjaDriver::CODE,
            'is_enabled' => true,
            'metadata' => ['courier_code' => $code],
            ...$attributes,
        ]);

        $zone?->carriers()->attach($carrier->id);

        return $carrier;
    }
}
