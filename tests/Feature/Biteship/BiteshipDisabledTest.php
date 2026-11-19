<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Integrations\Biteship\Biteship;
use App\Integrations\Biteship\Services\BiteshipClient;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\ShippingCapabilities;
use Illuminate\Support\Facades\Http;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\Facades\Shipping;

/**
 * Plug and play: without a usable key (or with the operator switch off) the
 * module has no effect at all: no capability, no API call, webhooks
 * acknowledged harmlessly.
 */
final class BiteshipDisabledTest extends BiteshipTestCase
{
    public function test_keyless_biteship_is_not_configured_and_takes_no_part(): void
    {
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertFalse(Shipping::driver('biteship')->isConfigured());
        $this->assertFalse($capabilities->drivers()->has('biteship'));
        $this->assertFalse($capabilities->supporting(FulfillsShipments::class)->has('biteship'));
        $this->assertArrayNotHasKey('biteship', $capabilities->quoteChains());
        $this->assertNotSame('biteship', $capabilities->destinationDriverCode());
    }

    public function test_keyless_checkout_never_calls_biteship(): void
    {
        $this->biteshipCarriers();
        $this->warehouse();
        Http::fake();

        resolve(FetchDeliveryRates::class)->handle(
            ['postal_code' => '10110', 'city' => 'Jakarta', 'destination_id' => 'IDNP6', 'destination_driver' => 'biteship'],
            [new Package(10, 10, 10, 1.0)],
            null,
            100000,
        );

        Http::assertNothingSent();
    }

    public function test_keyless_carrier_sync_and_tracking_commands_are_no_ops_for_biteship(): void
    {
        Http::fake();

        $this->artisan('shipping:sync-carriers', ['--driver' => 'biteship'])->assertSuccessful();
        $this->artisan('shipping:refresh-tracking')->assertSuccessful();
        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertFalse(resolve(ShippingCapabilities::class)->supporting(SyncsCarriers::class)->has('biteship'));
        Http::assertNothingSent();
    }

    public function test_keyless_webhook_is_acknowledged_without_processing(): void
    {
        Http::fake();

        $this->postJson('/webhooks/biteship', ['event' => 'order.status', 'order_id' => 'BS-1', 'status' => 'delivered'])
            ->assertOk()
            ->assertExactJson(['status' => 'disabled']);

        Http::assertNothingSent();
    }

    public function test_operator_switch_off_removes_a_configured_driver(): void
    {
        $this->enableBiteship();
        config()->set('shopper.shipping.drivers.biteship.enabled', false);

        $this->assertTrue(Biteship::configured(), 'The key itself is fine');
        $this->assertFalse(resolve(ShippingCapabilities::class)->drivers()->has('biteship'));

        $this->postJson('/webhooks/biteship', ['event' => 'order.status', 'order_id' => 'BS-1'], ['X-Biteship-Secret' => self::SECRET])
            ->assertOk()
            ->assertExactJson(['status' => 'disabled']);
    }

    public function test_a_test_key_is_refused_in_production_unless_allowed(): void
    {
        $this->enableBiteship();
        $this->app['env'] = 'production';

        $this->assertFalse(Biteship::configured());
        $this->assertStringContainsString('BITESHIP_ALLOW_TEST_KEY', (string) Biteship::refusal());
        $this->assertFalse(resolve(ShippingCapabilities::class)->drivers()->has('biteship'));

        config()->set('biteship.allow_test_key_in_production', true);
        $this->assertTrue(Biteship::configured());
    }

    public function test_a_live_key_is_refused_in_local_and_testing_unless_allowed(): void
    {
        config()->set('biteship.api_key', 'biteship_live.real-key');

        $this->assertFalse(Biteship::configured());
        $this->assertStringContainsString('BITESHIP_ALLOW_LIVE_KEY', (string) Biteship::refusal());

        config()->set('biteship.allow_live_key_outside_production', true);
        $this->assertTrue(Biteship::configured());

        config()->set('biteship.allow_live_key_outside_production', false);
        $this->app['env'] = 'production';
        $this->assertTrue(Biteship::configured(), 'A live key is the normal production setup');
    }

    public function test_the_client_refuses_to_call_out_when_not_configured(): void
    {
        Http::fake();

        $this->expectExceptionMessage('Biteship is not configured');

        resolve(BiteshipClient::class)->couriers();
    }

    public function test_a_configured_driver_exposes_its_capabilities(): void
    {
        $this->enableBiteship();
        config()->set('shopper.shipping.destination_driver', 'biteship');
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertTrue($capabilities->drivers()->has('biteship'));
        $this->assertSame('biteship', $capabilities->destinationDriverCode());
        $this->assertTrue($capabilities->requiresDestination());
        $this->assertFalse($capabilities->requiresCoordinates());
        $this->assertTrue($capabilities->originDrivers()->has('biteship'));
        $this->assertArrayHasKey('biteship', $capabilities->quoteChains());
    }
}
