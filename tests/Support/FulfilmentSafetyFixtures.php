<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\FulfillOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\ShipmentNotFulfillable;
use App\Shipping\ShippingCapabilities;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\Product;
use Throwable;

/**
 * Provider-neutral fixtures of the go-live "fulfilment-safety" QA: a shipment
 * is booked with its provider at most once, and never for a cancelled,
 * completed, delivered or historic order. The generic part lives in
 * tests/Feature/QaRegression/FulfilmentSafetyTest; each provider module has
 * its own <Module>FulfilmentSafetyTest next to its other tests, so removing a
 * module's test folder removes exactly its cases.
 */
trait FulfilmentSafetyFixtures
{
    protected function startFulfilmentSafety(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'Asia/Jakarta'));
        Http::preventStrayRequests();
    }

    protected function endFulfilmentSafety(): void
    {
        Carbon::setTestNow();
    }

    protected function runJobExpectingFailure(OrderShipment $shipment): void
    {
        $thrown = null;

        try {
            (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The fulfilment attempt was expected to fail (so the queue retries it).');
        $this->assertNotInstanceOf(ShipmentNotFulfillable::class, $thrown);
    }

    protected function sent(string $path): int
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $path))->count();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function connectionFailure(string $method, string $message, array $context = []): ConnectionException
    {
        return new ConnectionException($message, 0, new ConnectException($message, new PsrRequest($method, 'https://delivery.example.test'), null, $context));
    }

    /**
     * A paid, open order with one shipment from the default warehouse
     * (2 × 500 g coffee, Rp50.000 each). Callers pass the shipment's driver,
     * rate and destination through the overrides.
     *
     * @param  array<string, mixed>  $shipmentOverrides
     * @param  array<string, mixed>  $orderOverrides
     * @return array{0: Order, 1: OrderShipment}
     */
    protected function paidOrderShipment(array $shipmentOverrides = [], array $orderOverrides = []): array
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
            'number' => 'ORD-FS-1',
            'price_amount' => 112000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Paid,
            'shipping_address_id' => $address->id,
            ...$orderOverrides,
        ]);

        $inventory = Inventory::query()->where('is_default', true)->first() ?? Inventory::factory()->create([
            'name' => 'Gudang Cirebon',
            'email' => 'gudang@example.com',
            'phone_number' => '0231 123 4567',
            'street_address' => 'Jl. Lemahwungkuk No. 10',
            'city' => 'Kota Cirebon',
            'postal_code' => '45111',
            'latitude' => -6.7366,
            'longitude' => 108.5414,
            'is_default' => true,
        ]);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE Express',
            'service_code' => 'jne:REG',
            'service_name' => 'JNE Reguler',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
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
}
