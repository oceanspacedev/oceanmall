<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Actions\Checkout\SelectedShipmentRates;
use App\DTO\AllocationPlan;
use App\DTO\ShipmentDraft;
use App\Models\OrderShipment;
use App\Payment\PaymentMethodCatalog;
use App\Shipping\Contracts\DelegatesQuotes;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\ShippingCapabilities;
use App\Support\ActiveProvider;
use App\Support\EnabledCarriers;
use App\Support\OperatorSwitch;
use Database\Seeders\CarrierSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Drivers\ManualDriver;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * The store sells through ONE payment provider and ONE shipping provider at
 * a time, however many are installed and keyed (App\Support\ActiveProvider):
 * the others list no payment methods, quote no couriers and show no
 * carriers, so nothing is offered twice. They still finish the orders they
 * already hold.
 *
 * The first half uses plain fake drivers (no provider module needed); the
 * second half checks the real modules against the same rule.
 */
final class SingleActiveProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        SingleProviderShippingDriver::$configured = [];
    }

    public function test_without_a_setting_the_first_configured_shipping_provider_sells_alone(): void
    {
        $this->shippingProviders('alpha', 'beta');
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame('alpha', ActiveProvider::family(OperatorSwitch::SHIPPING));
        $this->assertSame(['alpha'], $capabilities->drivers()->keys()->all());
        $this->assertSame(['alpha'], array_keys($capabilities->quoteChains()));
        $this->assertTrue(OperatorSwitch::on(OperatorSwitch::SHIPPING, 'alpha'));
        $this->assertFalse(OperatorSwitch::on(OperatorSwitch::SHIPPING, 'beta'));
        // Its switch is still on: it may finish what it holds.
        $this->assertTrue(OperatorSwitch::switchedOn(OperatorSwitch::SHIPPING, 'beta'));

        // The first one loses its key: the next configured provider takes over.
        SingleProviderShippingDriver::$configured['alpha'] = false;
        $this->assertSame(['beta'], $capabilities->drivers()->keys()->all());
    }

    public function test_the_store_setting_names_the_shipping_provider(): void
    {
        $this->shippingProviders('alpha', 'beta');
        $capabilities = resolve(ShippingCapabilities::class);

        config()->set('shopper.shipping.provider', ' Beta ');
        $this->assertSame(['beta'], $capabilities->drivers()->keys()->all());
        $this->assertSame(['beta'], array_keys($capabilities->quoteChains()));

        // 'none' / 'manual': Shopper's zone options only.
        foreach (['none', 'manual'] as $nothing) {
            config()->set('shopper.shipping.provider', $nothing);
            $this->assertSame([], $capabilities->drivers()->keys()->all());
            $this->assertSame([], $capabilities->quoteChains());
        }

        // A named provider that is not configured is never replaced by
        // another keyed one, and neither is an unknown name.
        SingleProviderShippingDriver::$configured['beta'] = false;

        foreach (['beta', 'no-such-provider'] as $missing) {
            config()->set('shopper.shipping.provider', $missing);
            $this->assertSame([], $capabilities->drivers()->keys()->all(), $missing);
        }
    }

    public function test_drivers_that_delegate_to_each_other_are_one_provider(): void
    {
        $this->shippingProviders('alpha', 'beta');
        Shipping::extend('alpha-cost', static fn (): DelegatingShippingDriver => new DelegatingShippingDriver('alpha-cost', 'alpha'));
        config()->set('shopper.shipping.drivers.alpha-cost.enabled', true);
        $capabilities = resolve(ShippingCapabilities::class);

        // Either code of the family selects the whole family.
        foreach ([null, 'alpha', 'alpha-cost'] as $setting) {
            config()->set('shopper.shipping.provider', $setting);
            $this->assertSame(['alpha', 'alpha-cost'], $capabilities->drivers()->keys()->all(), var_export($setting, true));
            $this->assertSame(['alpha-cost'], array_keys($capabilities->quoteChains()));
        }

        config()->set('shopper.shipping.provider', 'beta');
        $this->assertSame(['beta'], $capabilities->drivers()->keys()->all());
    }

    public function test_a_provider_that_no_longer_sells_still_finishes_its_parcels(): void
    {
        $this->shippingProviders('alpha', 'beta');
        config()->set('shopper.shipping.provider', 'beta');
        $capabilities = resolve(ShippingCapabilities::class);

        $old = (new OrderShipment)->forceFill(['driver' => 'alpha', 'external_reference' => 'ALPHA-1', 'awb' => 'AWB1']);
        $new = (new OrderShipment)->forceFill(['driver' => 'beta']);

        $this->assertSame(['beta'], $capabilities->supporting(FulfillsShipments::class)->keys()->all());
        $this->assertSame('alpha', $capabilities->fulfillerFor($old)?->code());
        $this->assertSame('alpha', $capabilities->trackerFor($old)?->code());
        $this->assertSame('alpha', $capabilities->forShipmentSupporting($old, TracksShipments::class)?->code());
        $this->assertSame('beta', $capabilities->fulfillerFor($new)?->code());
        $this->assertSame(['alpha', 'beta'], $capabilities->fulfilledDriverCodes());
        $this->assertTrue($capabilities->services(FulfillsShipments::class));

        // The previous provider's bare AWB is tracked by itself, never
        // handed to the provider the store sells through now.
        $bare = (new OrderShipment)->forceFill(['driver' => 'alpha', 'awb' => 'AWB2']);
        $this->assertSame('alpha', $capabilities->trackerFor($bare)?->code());

        // Lifecycle work continues while only a previous provider holds a key.
        SingleProviderShippingDriver::$configured['beta'] = false;
        $this->assertFalse($capabilities->supports(FulfillsShipments::class));
        $this->assertTrue($capabilities->services(FulfillsShipments::class));

        // Its switch stops that too.
        config()->set('shopper.shipping.drivers.alpha.enabled', false);
        $this->assertNull($capabilities->fulfillerFor($old));
        $this->assertNull($capabilities->trackerFor($old));
        $this->assertFalse($capabilities->services(FulfillsShipments::class));
    }

    public function test_only_one_payment_gateway_offers_its_methods(): void
    {
        $this->paymentGateways('gate-a', 'gate-b');
        $catalog = new PaymentMethodCatalog;

        $this->assertTrue($catalog->driverReady('gate-a'));
        $this->assertFalse($catalog->driverReady('gate-b'));
        $this->assertSame('gate-a,manual', $catalog->signature());
        $this->assertTrue($catalog->driverReady('manual'));

        config()->set('shopper.payment.provider', 'gate-b');
        $this->assertFalse($catalog->driverReady('gate-a'));
        $this->assertTrue($catalog->driverReady('gate-b'));

        // The gateway that stopped selling still settles what it issued.
        $this->assertTrue($catalog->driverServiceable('gate-a'));
        config()->set('shopper.payment.drivers.gate-a.enabled', false);
        $this->assertFalse($catalog->driverServiceable('gate-a'));

        config()->set('shopper.payment.provider', 'none');
        $this->assertFalse($catalog->driverReady('gate-b'));
        $this->assertTrue($catalog->driverReady('manual'));
        $this->assertSame('manual', $catalog->signature());
    }

    public function test_an_off_value_means_no_provider_and_shoppers_own_drivers_are_the_last_default(): void
    {
        $this->shippingProviders('alpha');

        foreach ([false, 'false', 'off', '0', 'no'] as $off) {
            config()->set('shopper.shipping.provider', $off);
            $this->assertSame('manual', ActiveProvider::family(OperatorSwitch::SHIPPING), var_export($off, true));
        }

        // Shopper registers Stripe before any provider module: as a default it
        // still comes last, so an enabled Stripe never takes a gateway's slot.
        Payment::extend('stripe', static fn (): ManualDriver => new ManualDriver);
        config()->set('shopper.payment.drivers.stripe.enabled', true);
        $this->assertSame('stripe', ActiveProvider::family(OperatorSwitch::PAYMENT));

        $this->paymentGateways('gate-a');
        $this->assertSame('gate-a', ActiveProvider::family(OperatorSwitch::PAYMENT));
        $this->assertSame(['gate-a', 'stripe'], ActiveProvider::configuredFamilies(OperatorSwitch::PAYMENT));

        config()->set('shopper.payment.provider', 'stripe');
        $this->assertTrue((new PaymentMethodCatalog)->driverReady('stripe'));
        $this->assertFalse((new PaymentMethodCatalog)->driverReady('gate-a'));
    }

    public function test_a_rate_picked_from_a_provider_that_stopped_selling_is_picked_again(): void
    {
        $this->shippingProviders('alpha', 'beta');
        $rates = resolve(SelectedShipmentRates::class);
        $plan = new AllocationPlan([new ShipmentDraft(7, [['purchasable_type' => 'product', 'purchasable_id' => 1, 'qty' => 1]])]);
        $picked = static fn (?string $driver): array => ['shipping_option' => [array_filter([
            'service_code' => 'jne:REG', 'amount' => 18000, 'inventory_id' => 7, 'driver' => $driver,
        ], static fn (mixed $value): bool => $value !== null)]];

        $this->assertTrue($rates->coverPlan($plan, $picked('alpha')));
        // Zone options and rates without a driver are never a provider's.
        $this->assertTrue($rates->coverPlan($plan, $picked('manual')));
        $this->assertTrue($rates->coverPlan($plan, $picked(null)));

        // The store moves to another provider while the checkout is open.
        config()->set('shopper.shipping.provider', 'beta');
        $this->assertFalse($rates->coverPlan($plan, $picked('alpha')));
        $this->assertTrue($rates->coverPlan($plan, $picked('beta')));
        $this->assertTrue($rates->coverPlan($plan, $picked('manual')));

        $capabilities = resolve(ShippingCapabilities::class);
        $this->assertFalse($capabilities->sells('alpha'));
        $this->assertTrue($capabilities->sells('beta'));
        $this->assertTrue($capabilities->sells(null));
    }

    public function test_a_driver_less_parcel_is_claimed_only_while_one_provider_could_have_sold_it(): void
    {
        $this->shippingProviders('alpha');
        $capabilities = resolve(ShippingCapabilities::class);
        $legacy = new OrderShipment;

        $this->assertSame('alpha', $capabilities->fulfillerFor($legacy)?->code());

        // A second keyed provider: the parcel names neither, staff decide.
        $this->shippingProviders('beta');
        config()->set('shopper.shipping.provider', 'beta');
        $this->assertNull($capabilities->fulfillerFor($legacy));

        config()->set('shopper.shipping.drivers.alpha.enabled', false);
        $this->assertSame('beta', $capabilities->fulfillerFor($legacy)?->code());
    }

    public function test_payment_and_shipping_are_chosen_independently(): void
    {
        $this->paymentGateways('gate-a', 'gate-b');
        $this->shippingProviders('alpha', 'beta');
        config()->set('shopper.payment.provider', 'gate-b');

        $this->assertTrue((new PaymentMethodCatalog)->driverReady('gate-b'));
        $this->assertSame(['alpha'], resolve(ShippingCapabilities::class)->drivers()->keys()->all());
    }

    public function test_a_lone_driver_keeps_shoppers_switch_rule(): void
    {
        // No provider is configured at all: there is nothing to choose
        // between, so the switch alone answers, as before.
        Shipping::extend('alpha', static fn (): SingleProviderShippingDriver => new SingleProviderShippingDriver('alpha'));
        SingleProviderShippingDriver::$configured['alpha'] = false;
        config()->set('shopper.shipping.drivers.alpha.enabled', true);

        $this->assertNull(ActiveProvider::family(OperatorSwitch::SHIPPING));
        $this->assertTrue(OperatorSwitch::on(OperatorSwitch::SHIPPING, 'alpha'));
        $this->assertFalse(OperatorSwitch::on(OperatorSwitch::SHIPPING, 'unregistered'));
        $this->assertSame([], resolve(ShippingCapabilities::class)->drivers()->keys()->all());
    }

    public function test_carriers_of_a_provider_that_does_not_sell_are_not_displayed(): void
    {
        $this->shippingProviders('alpha', 'beta');

        foreach (['alpha' => 'jne', 'beta' => 'beta-jne', 'manual' => 'kurir-toko', 'gone' => 'old-courier'] as $driver => $slug) {
            Carrier::query()->create(['slug' => $slug, 'name' => strtoupper($slug), 'is_enabled' => true, 'driver' => $driver]);
        }

        $shown = static fn (): array => EnabledCarriers::forDisplay()->pluck('slug')->sort()->values()->all();

        // 'gone' is no registered driver: the carrier is the store's own.
        $this->assertSame(['jne', 'kurir-toko', 'old-courier'], $shown());

        config()->set('shopper.shipping.provider', 'beta');
        $this->assertSame(['beta-jne', 'kurir-toko', 'old-courier'], $shown());

        // Rows are kept for the day the provider is selected again.
        $this->assertTrue(Carrier::query()->where('slug', 'jne')->value('is_enabled'));
    }

    public function test_the_status_command_shows_who_sells_and_who_stands_by(): void
    {
        $this->paymentGateways('gate-a', 'gate-b');
        $this->shippingProviders('alpha', 'beta');

        $this->artisan('providers:status')
            ->expectsOutputToContain('Several providers hold a key')
            ->assertSuccessful();

        config()->set('shopper.payment.provider', 'gate-b');
        config()->set('shopper.shipping.provider', 'alpha');

        $this->artisan('providers:status')
            ->doesntExpectOutputToContain('Several providers hold a key')
            ->assertSuccessful();

        // A setting that sells nothing is called out.
        config()->set('shopper.payment.provider', 'no-such-gateway');
        $this->artisan('providers:status')
            ->expectsOutputToContain('names no payment provider of this store')
            ->assertSuccessful();
    }

    public function test_only_the_selected_payment_module_offers_and_seeds_methods(): void
    {
        $this->requireModules('Xendit', 'Midtrans');

        config()->set('xendit.secret_key', 'xnd_development_abc');
        config()->set('midtrans.server_key', 'SB-Mid-server-test');
        config()->set('midtrans.is_production', false);
        $this->assertTrue(Payment::isConfigured('xendit'));
        $this->assertTrue(Payment::isConfigured('midtrans'));

        $catalog = new PaymentMethodCatalog;

        // Registered first (bootstrap/providers.php): Xendit sells.
        $this->assertTrue($catalog->driverReady('xendit'));
        $this->assertFalse($catalog->driverReady('midtrans'));

        config()->set('shopper.payment.provider', 'midtrans');
        $this->assertFalse($catalog->driverReady('xendit'));
        $this->assertTrue($catalog->driverReady('midtrans'));

        $this->seed(PaymentMethodSeeder::class);

        // Both gateways keep their rows (ready for the day they are selected);
        // only the selected one is offered.
        $offered = PaymentMethod::query()->where('is_enabled', true)->get()
            ->filter(static fn (PaymentMethod $method): bool => $catalog->available($method))
            ->pluck('driver')->unique()->values()->all();
        $this->assertContains('midtrans', $offered);
        $this->assertNotContains('xendit', $offered);
        $this->assertGreaterThan(0, PaymentMethod::query()->where('driver', 'xendit')->where('is_enabled', true)->count());
        // A gateway sells: COD is seeded off.
        $this->assertFalse((bool) PaymentMethod::query()->where('slug', 'cod')->value('is_enabled'));
    }

    public function test_only_the_selected_shipping_module_quotes_and_seeds_couriers(): void
    {
        $this->requireModules('Biteship', 'KiriminAja');

        config()->set('biteship.api_key', 'biteship_test.eyJhbGciOiJIUzI1NiJ9.test');
        config()->set('biteship.base_url', 'https://biteship.example.test');
        config()->set('kiriminaja.api_key', 'ka-test-key');
        config()->set('kiriminaja.base_url', 'https://ka.example.test');
        $this->assertTrue(Shipping::driver('biteship')->isConfigured());
        $this->assertTrue(Shipping::driver('kiriminaja')->isConfigured());

        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame(['biteship'], $capabilities->drivers()->keys()->all());
        $this->assertSame('biteship', $capabilities->destinationDriverCode());

        config()->set('shopper.shipping.provider', 'kiriminaja');
        $this->assertSame(['kiriminaja'], $capabilities->drivers()->keys()->all());
        $this->assertSame(['kiriminaja'], array_keys($capabilities->quoteChains()));
        $this->assertSame('kiriminaja', $capabilities->destinationDriverCode());

        $this->seed(CarrierSeeder::class);

        $this->assertGreaterThan(0, Carrier::query()->where('driver', 'biteship')->count());
        $this->assertNotContains('biteship', EnabledCarriers::forDisplay()->pluck('driver')->all());

        // Parcels Biteship already holds keep receiving their webhooks.
        $this->assertStringNotContainsString('disabled', (string) $this->postJson('/webhooks/biteship', [])->getContent());
        config()->set('shopper.shipping.drivers.biteship.enabled', false);
        $this->postJson('/webhooks/biteship', [])->assertOk()->assertJson(['status' => 'disabled']);

        // The same for KiriminAja once the store sells through Biteship.
        config()->set('shopper.shipping.drivers.biteship.enabled', true);
        config()->set('shopper.shipping.provider', 'biteship');
        $this->assertStringNotContainsString('disabled', (string) $this->postJson('/webhooks/kiriminaja', [])->getContent());
        config()->set('shopper.shipping.drivers.kiriminaja.enabled', false);
        $this->postJson('/webhooks/kiriminaja', [])->assertOk()->assertJson(['status' => 'disabled']);
    }

    public function test_komerce_shipping_drivers_are_one_provider(): void
    {
        $this->requireModules('Komerce', 'Biteship');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('biteship.api_key', 'biteship_test.eyJhbGciOiJIUzI1NiJ9.test');
        config()->set('biteship.base_url', 'https://biteship.example.test');

        $capabilities = resolve(ShippingCapabilities::class);

        foreach ([null, 'komerce', 'rajaongkir'] as $setting) {
            config()->set('shopper.shipping.provider', $setting);
            $this->assertEqualsCanonicalizing(['rajaongkir', 'komerce'], $capabilities->drivers()->keys()->all(), var_export($setting, true));
        }

        config()->set('shopper.shipping.provider', 'biteship');
        $this->assertSame(['biteship'], $capabilities->drivers()->keys()->all());

        // A Komerce parcel sold before the switch is still Komerce's to register.
        $old = (new OrderShipment)->forceFill(['driver' => 'rajaongkir']);
        $this->assertSame('komerce', $capabilities->fulfillerFor($old)?->code());
    }

    private function shippingProviders(string ...$codes): void
    {
        foreach ($codes as $code) {
            SingleProviderShippingDriver::$configured[$code] = true;
            Shipping::extend($code, static fn (): SingleProviderShippingDriver => new SingleProviderShippingDriver($code));
            config()->set("shopper.shipping.drivers.{$code}.enabled", true);
        }
    }

    private function paymentGateways(string ...$codes): void
    {
        foreach ($codes as $code) {
            Payment::extend($code, static fn (): ManualDriver => new ManualDriver);
            config()->set("shopper.payment.drivers.{$code}.enabled", true);
        }
    }
}

final class SingleProviderShippingDriver extends Driver implements FulfillsShipments, TracksShipments
{
    /** @var array<string, bool> */
    public static array $configured = [];

    public function __construct(private readonly string $driverCode) {}

    public function code(): string
    {
        return $this->driverCode;
    }

    public function name(): string
    {
        return ucfirst($this->driverCode);
    }

    public function isConfigured(): bool
    {
        return self::$configured[$this->driverCode] ?? false;
    }

    public function supportsRealTimeRates(): bool
    {
        return true;
    }

    public function supportsLabels(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }

    public function fulfillShipment(OrderShipment $shipment): void {}

    public function trackShipment(OrderShipment $shipment): TrackingInfo
    {
        return new TrackingInfo(trackingNumber: (string) $shipment->awb, status: 'in_transit');
    }
}

final class DelegatingShippingDriver extends Driver implements DelegatesQuotes
{
    public function __construct(private readonly string $driverCode, private readonly string $preferred) {}

    public function code(): string
    {
        return $this->driverCode;
    }

    public function name(): string
    {
        return ucfirst($this->driverCode);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsRealTimeRates(): bool
    {
        return true;
    }

    public function supportsLabels(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return false;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }

    public function preferredQuoteDriver(): string
    {
        return $this->preferred;
    }
}
