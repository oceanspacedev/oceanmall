<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Shipping\EnsureOrderShipments;
use App\Models\OrderShipment;
use App\Payment\Contracts\ReconcilesPayments;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

final class ShipmentDriverDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_komerce_order_number_is_mirrored_to_neutral_columns(): void
    {
        $this->requireModules('Komerce');

        $shipment = $this->shipment(['driver' => 'rajaongkir']);

        $this->assertSame('rajaongkir', $shipment->driver);
        $this->assertNull($shipment->external_reference);

        $shipment->update(['metadata' => ['komerce' => ['order_no' => ' KOM-77 ']]]);

        $shipment->refresh();
        // `driver` stays the quoting driver; registration shows in external_reference
        // and the fulfilling driver is derived (RajaOngkir DelegatesFulfillment to Komerce).
        $this->assertSame('rajaongkir', $shipment->driver);
        $this->assertSame('KOM-77', $shipment->external_reference);
        $this->assertSame('KOM-77', resolve(OrderShipmentOpsPresenter::class)->deliveryOrderNo($shipment));
        $this->assertSame('rajaongkir', resolve(ShippingCapabilities::class)->forShipment($shipment)?->code());
    }

    public function test_a_legacy_shipment_without_driver_is_stamped_komerce_once_registered(): void
    {
        $this->requireModules('Komerce');

        $shipment = $this->shipment(['driver' => null]);

        $shipment->update(['metadata' => ['komerce' => ['order_no' => 'KOM-78']]]);

        $this->assertSame('komerce', $shipment->refresh()->driver);
        $this->assertSame('KOM-78', $shipment->external_reference);
    }

    public function test_presenter_reads_the_external_reference_column_for_any_driver(): void
    {
        $shipment = $this->shipment(['driver' => 'komerce', 'external_reference' => 'KOM-COLUMN']);

        $this->assertSame('KOM-COLUMN', resolve(OrderShipmentOpsPresenter::class)->deliveryOrderNo($shipment));

        $foreign = $this->shipment(['driver' => 'biteship', 'external_reference' => 'BS-1']);
        $this->assertSame('BS-1', resolve(OrderShipmentOpsPresenter::class)->deliveryOrderNo($foreign));
        $this->assertTrue(resolve(OrderShipmentOpsPresenter::class)->isLockedForOverride($foreign));
    }

    public function test_komerce_cancel_shipment_delegates_to_the_delivery_cancel(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        Http::fake([
            'https://delivery.example.test/*' => Http::response(['meta' => ['code' => 200, 'status' => 'success']]),
        ]);

        $shipment = $this->shipment(['metadata' => ['komerce' => ['order_no' => 'KOM-9']]]);

        Shipping::driver('komerce')->cancelShipment($shipment);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'cancel')
            && str_contains($request->body(), 'KOM-9'));
        $this->assertNotNull(data_get($shipment->refresh()->metadata, 'komerce.cancelled_at'));
    }

    public function test_komerce_cancel_shipment_is_a_noop_without_a_key(): void
    {
        $this->requireModules('Komerce');

        Http::fake();
        $shipment = $this->shipment(['metadata' => ['komerce' => ['order_no' => 'KOM-9']]]);

        Shipping::driver('komerce')->cancelShipment($shipment);

        Http::assertNothingSent();
        $this->assertNull(data_get($shipment->refresh()->metadata, 'komerce.cancelled_at'));
    }

    public function test_keyless_zone_rates_are_tagged_with_the_manual_driver(): void
    {
        Http::fake();
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);
        $carrier = Carrier::query()->create(['slug' => 'kurir-toko', 'name' => 'Kurir Toko', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);
        CarrierOption::query()->create([
            'name' => 'Antar Hari Ini',
            'price' => 15000,
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'is_enabled' => true,
        ]);

        $rates = resolve(FetchDeliveryRates::class)->handle(
            ['country_id' => $country->id, 'city' => 'Cirebon', 'postal_code' => '45153'],
            [new Package(weight: 1.0, length: 10, width: 10, height: 10)],
        );

        $this->assertCount(1, $rates);
        $this->assertSame('manual', $rates[0]['driver']);
        $this->assertSame(15000, (int) $rates[0]['amount']);
        Http::assertNothingSent();
    }

    public function test_fallback_shipment_takes_the_carrier_driver(): void
    {
        $carrier = Carrier::query()->create(['slug' => 'kurir-toko', 'name' => 'Kurir Toko', 'driver' => 'manual', 'is_enabled' => true]);
        $option = CarrierOption::query()->create([
            'name' => 'Antar Hari Ini',
            'price' => 15000,
            'carrier_id' => $carrier->id,
            'zone_id' => Zone::factory()->create()->id,
            'is_enabled' => true,
        ]);
        Inventory::factory()->create(['is_default' => true]);
        $order = Order::factory()->create(['shipping_option_id' => $option->id]);

        $shipments = resolve(EnsureOrderShipments::class)->handle($order);

        $this->assertSame('manual', $shipments->first()?->driver);
    }

    public function test_komerce_payment_expiry_and_reconcile_through_the_contracts(): void
    {
        $this->requireModules('Komerce');

        $order = Order::factory()->create();
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'amount' => 10000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'reference' => 'KPAY-1',
            'metadata' => ['expiry_date' => '2026-11-20 10:00:00'],
        ]);

        $driver = Payment::driver('komerce');

        $this->assertSame('2026-11-20 10:00:00', $driver->paymentExpiresAt($order)?->format('Y-m-d H:i:s'));
        $this->assertNull($driver->paymentExpiresAt(Order::factory()->create()));
        $this->assertSame(ReconcilesPayments::NO_PAYMENT, $driver->reconcilePayment(Order::factory()->create()));
        $this->assertNull($driver->paymentCatalog(), 'No catalog without a payment key.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shipment(array $attributes = []): OrderShipment
    {
        return OrderShipment::query()->create([
            'order_id' => Order::factory()->create()->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
            ...$attributes,
        ]);
    }
}
