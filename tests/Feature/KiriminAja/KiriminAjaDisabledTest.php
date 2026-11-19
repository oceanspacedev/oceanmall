<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Models\OrderShipment;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\ShippingCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\CarrierOption;
use Shopper\Shipping\DataTransferObjects\Package;
use Tests\TestCase;

/**
 * Plug and play: without KIRIMINAJA_API_KEY (or with the operator switch off)
 * the module has no effect on checkout, the generic commands, cpanel
 * capabilities or callbacks, and never calls KiriminAja.
 */
final class KiriminAjaDisabledTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureKiriminAja();
        $this->disableKiriminAja();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_the_driver_takes_no_part_without_a_key(): void
    {
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertFalse($capabilities->drivers()->has(KiriminAjaDriver::CODE));
        $this->assertArrayNotHasKey(KiriminAjaDriver::CODE, $capabilities->quoteChains());
        $this->assertArrayNotHasKey(KiriminAjaDriver::CODE, $capabilities->originDrivers()->all());
        $this->assertFalse($capabilities->supporting(FulfillsShipments::class)->has(KiriminAjaDriver::CODE));
        $this->assertNull(resolve(KiriminAjaDriver::class)->resolveOrigin($this->warehouse(null)));
    }

    public function test_the_operator_switch_takes_a_configured_driver_out(): void
    {
        $this->configureKiriminAja(['shopper.shipping.drivers.kiriminaja.enabled' => false]);

        $this->assertFalse(resolve(ShippingCapabilities::class)->drivers()->has(KiriminAjaDriver::CODE));

        $this->postJson('/webhooks/kiriminaja', ['method' => 'processed_packages', 'data' => [['order_id' => 'OM1-X']]])
            ->assertOk()
            ->assertExactJson(['status' => 'disabled']);
    }

    public function test_checkout_uses_zone_options_and_kiriminaja_carriers_are_ignored(): void
    {
        [$zone, $country] = $this->indonesiaZone();
        $this->kiriminAjaCarrier('jne', $zone);
        $manual = $this->kiriminAjaCarrier('kurir-toko', $zone, ['driver' => 'manual', 'metadata' => []]);
        CarrierOption::query()->create(['carrier_id' => $manual->id, 'zone_id' => $zone->id, 'name' => 'Reguler', 'price' => 15000, 'is_enabled' => true]);
        $this->warehouse();

        $rates = resolve(FetchDeliveryRates::class)->handle([
            'first_name' => 'Budi', 'street_address' => 'Jl. Kesambi 5', 'postal_code' => '45134', 'city' => 'Cirebon',
            'country_id' => $country->id, 'destination_id' => '1535:22001', 'destination_driver' => KiriminAjaDriver::CODE,
        ], [new Package(10, 10, 10, 0.5)], itemValue: 100000);

        $this->assertSame(['manual'], array_values(array_unique(array_column($rates, 'driver'))));
        Http::assertNothingSent();
    }

    public function test_generic_commands_do_not_touch_kiriminaja_shipments(): void
    {
        [, $shipment] = $this->paidShipment();

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        $this->artisan('shipping:refresh-tracking')->assertSuccessful();
        $this->artisan('shipping:sync-carriers')->assertSuccessful();

        $shipment->refresh();
        $this->assertNull($shipment->external_reference);
        $this->assertNull(data_get($shipment->metadata, 'fulfillment_error'));
        Http::assertNothingSent();
    }

    public function test_cancel_and_reconcile_are_no_ops(): void
    {
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-ABC', 'awb' => 'JNE123']);
        $driver = resolve(KiriminAjaDriver::class);

        $driver->cancelShipment($shipment);
        $this->assertInstanceOf(OrderShipment::class, $driver->reconcileShipment($shipment));
        $this->assertNull(data_get($shipment->refresh()->metadata, 'kiriminaja.cancel_requested_at'));
        Http::assertNothingSent();
    }

    public function test_callbacks_are_acknowledged_without_processing(): void
    {
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-ABC']);

        $this->postJson('/webhooks/kiriminaja', [
            'method' => 'processed_packages',
            'data' => [['order_id' => 'OM1-ABC', 'awb' => 'FORGED']],
        ])->assertOk()->assertExactJson(['status' => 'disabled']);

        $this->assertNull($shipment->refresh()->awb);
        Http::assertNothingSent();
    }

    public function test_set_callback_refuses_to_run(): void
    {
        $this->artisan('kiriminaja:set-callback')->assertFailed();
        Http::assertNothingSent();
    }
}
