<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Jobs\FulfillOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShipmentNotFulfillable;
use App\Shipping\ShippingCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Shopper\Shipping\Facades\Shipping;
use Tests\Support\FulfilmentSafetyFixtures;
use Tests\TestCase;

/**
 * Go-live QA "fulfilment-safety" (shared fixtures: Tests\Support\FulfilmentSafetyFixtures).
 *
 * A paid Biteship shipment is booked through the neutral job, never for a
 * cancelled order.
 */
final class BiteshipFulfilmentSafetyTest extends TestCase
{
    use FulfilmentSafetyFixtures;
    use RefreshDatabase;

    private const BITESHIP = 'https://api.biteship.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->startFulfilmentSafety();
    }

    protected function tearDown(): void
    {
        $this->endFulfilmentSafety();

        parent::tearDown();
    }

    public function test_cancelled_paid_order_is_never_booked_with_biteship(): void
    {
        $this->configureBiteship();
        [$order, $shipment] = $this->biteshipShipment();
        Http::fake([self::BITESHIP.'/v1/orders' => Http::response($this->biteshipOrder())]);

        resolve(CancelOrderWithProviders::class)->handle($order);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        (new FulfillOrderShipment((int) $shipment->id))->handle(resolve(ShippingCapabilities::class));

        try {
            Shipping::driver('biteship')->fulfillShipment($shipment);
            $this->fail('A cancelled order must not be booked from a direct driver call either.');
        } catch (ShipmentNotFulfillable) {
        }

        $this->assertSame(0, $this->sent(self::BITESHIP.'/v1/orders'), 'A cancelled order must never book a Biteship pickup.');
        $this->assertNull($shipment->refresh()->awb);
    }

    public function test_open_paid_biteship_order_is_still_booked(): void
    {
        $this->configureBiteship();
        [, $shipment] = $this->biteshipShipment();
        Http::fake([self::BITESHIP.'/v1/orders' => Http::response($this->biteshipOrder())]);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame(1, $this->sent(self::BITESHIP.'/v1/orders'));
        $this->assertSame('JNE-WB-001', $shipment->refresh()->awb);
    }

    // ---- Fixtures --------------------------------------------------------------

    private function configureBiteship(): void
    {
        config()->set('biteship.api_key', 'biteship_test.eyJhbGciOiJIUzI1NiJ9.test');
        config()->set('biteship.base_url', self::BITESHIP);
        config()->set('biteship.webhook_secret', 'whsec-test-secret');
        config()->set('biteship.allow_test_key_in_production', false);
        config()->set('biteship.allow_live_key_outside_production', false);
        config()->set('biteship.delivery_type', 'now');
        config()->set('shopper.shipping.drivers.biteship.enabled', true);
    }

    /**
     * @return array{0: Order, 1: OrderShipment}
     */
    private function biteshipShipment(): array
    {
        [$order, $shipment] = $this->paidOrderShipment(
            [
                'driver' => 'biteship',
                'service_code' => 'jne:reg',
                'service_name' => 'Reguler',
                'cost' => 18000,
                'metadata' => ['rate' => [
                    'service_code' => 'jne:reg', 'carrier_code' => 'jne', 'courier_company' => 'jne',
                    'courier_type' => 'reg', 'provider' => 'biteship', 'amount' => 18000,
                ]],
            ],
            [
                'shipping_status' => ShippingStatus::Unfulfilled,
                'metadata' => json_encode(['shipping_address' => [
                    'country_id' => 1,
                    'destination_id' => 'IDNP6IDNC147IDND830IDZ10110',
                    'destination_driver' => 'biteship',
                    'destination_label' => 'Gambir, Jakarta Pusat',
                ]], JSON_THROW_ON_ERROR),
            ],
        );

        $order->shippingAddress?->forceFill(['phone' => '081234567890'])->save();
        resolve(InventoryOrigins::class)->put((int) $shipment->inventory_id, 'biteship', 'IDNP9IDNC74IDND6IDZ45111', 'Kejaksan, Cirebon');

        return [$order->refresh(), $shipment->refresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function biteshipOrder(): array
    {
        return [
            'success' => true,
            'object' => 'order',
            'id' => 'BS-ORDER-1',
            'status' => 'confirmed',
            'price' => 18000,
            'courier' => [
                'tracking_id' => 'BS-TRACK-1',
                'waybill_id' => 'JNE-WB-001',
                'company' => 'jne',
                'type' => 'reg',
                'link' => 'https://track.example.test/JNE-WB-001',
            ],
        ];
    }
}
