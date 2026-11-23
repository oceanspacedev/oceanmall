<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Shipping\EnsureOrderShipments;
use App\Actions\Shipping\PrintShipmentLabels;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Listeners\CancelShipmentsOnOrderCancelled;
use App\Models\OrderShipment;
use App\Shipping\CarrierSyncResult;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\ShippingCapabilities;
use App\Shipping\ShippingQuoteContext;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Zone;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\DataTransferObjects\Shipment;
use Shopper\Shipping\DataTransferObjects\ShippingRate;
use Shopper\Shipping\DataTransferObjects\TrackingEvent;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * A second shipping provider plugs in as a plain Shopper driver
 * (Shipping::extend) implementing the app's capability contracts: rates,
 * fulfilment, labels, cancel, tracking, carrier sync and the schedules reach
 * it without any edit to generic checkout / order / cpanel code, and it never
 * disturbs the Komerce / RajaOngkir drivers (or vice versa).
 */
final class ProviderDriverPipelineTest extends TestCase
{
    use RefreshDatabase;

    private AcmeShippingDriver $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = new AcmeShippingDriver;
        $acme = $this->acme;
        Shipping::extend('acme', static fn (): AcmeShippingDriver => $acme);
        // Shopper's convention: a driver is on only with its config entry.
        config()->set('shopper.shipping.drivers.acme.enabled', true);
    }

    public function test_quote_chains_follow_driver_delegation_not_generic_code(): void
    {
        $this->acme->configured = false;
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame([], $capabilities->quoteChains());

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        $this->assertSame(['rajaongkir' => ['rajaongkir']], $this->chainCodes());

        // Both Komerce products: Delivery's tariff first, Shipping Cost as fallback.
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        $this->assertSame(['rajaongkir' => ['komerce', 'rajaongkir']], $this->chainCodes());

        // Delivery alone still quotes the RajaOngkir carriers.
        config()->set('komerce.shipping_cost_api_key', '');
        $this->assertSame(['rajaongkir' => ['komerce']], $this->chainCodes());

        $this->acme->configured = true;
        $this->assertSame(['rajaongkir' => ['komerce'], 'acme' => ['acme']], $this->chainCodes());
    }

    public function test_a_second_provider_quotes_its_own_carriers_next_to_rajaongkir(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');
        config()->set('komerce.couriers', []);
        Http::preventStrayRequests();
        Http::fake([
            'https://cost.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [['code' => 'jne', 'name' => 'JNE', 'service' => 'REG', 'cost' => 18000, 'etd' => '2-3']],
            ]),
        ]);

        [$zone, $country] = $this->zone();
        $this->carrier($zone, 'jne', 'JNE Express', 'rajaongkir');
        // Acme's own courier happens to use an official RajaOngkir code: it must
        // never be sent to RajaOngkir, and RajaOngkir's sync must not own it.
        $this->carrier($zone, 'acme-sicepat', 'SiCepat via Acme', 'acme', ['courier_code' => 'sicepat']);
        $this->manualOption($zone);
        Inventory::factory()->create(['is_default' => true, 'rajaongkir_origin_id' => '501']);

        $this->acme->rates = [new ShippingRate('sicepat:BEST', 'BEST', 21000, 'IDR', 'sicepat', '1-2')];

        $rates = $this->rates($country);

        $this->assertSame(['rajaongkir', 'acme'], array_column($rates, 'driver'));
        $this->assertSame('jne:REG', $rates[0]['service_code']);
        $this->assertSame('sicepat:BEST', $rates[1]['service_code']);
        $this->assertSame('SiCepat via Acme', $rates[1]['carrier_name']);
        $this->assertSame('acme-row-1', $rates[1]['acme_reference'], 'Driver details reach the checkout row.');
        $this->assertSame(['sicepat'], $this->acme->quotedCouriers);
        $this->assertNotContains('manual', array_column($rates, 'driver'), 'Zone options are the fallback, not shown next to provider quotes.');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'domestic-cost')
            && $request['courier'] === 'jne');
    }

    public function test_zone_options_are_the_fallback_when_no_provider_returns_a_rate_and_the_store_opts_in(): void
    {
        [$zone, $country] = $this->zone();
        $this->carrier($zone, 'acme-reg', 'Acme', 'acme');
        $option = $this->manualOption($zone);
        $this->acme->rates = [];

        // Default: a configured provider without rates offers no flat zone rate.
        $this->assertSame([], $this->rates($country));

        config()->set('shopper.shipping.fallback_to_zone_options', true);
        $rates = $this->rates($country);

        $this->assertCount(1, $rates);
        $this->assertSame($option->id, $rates[0]['service_code']);
        $this->assertSame('manual', $rates[0]['driver']);
    }

    public function test_a_failing_provider_is_recorded_as_a_neutral_failure_and_falls_back(): void
    {
        [$zone, $country] = $this->zone();
        $this->carrier($zone, 'acme-reg', 'Acme', 'acme');
        $this->manualOption($zone);
        $this->acme->failQuotes = true;

        $this->assertSame([], $this->rates($country));
        $this->assertSame('acme', resolve(ShippingQuoteContext::class)->lastFailure()?->driver);

        config()->set('shopper.shipping.fallback_to_zone_options', true);
        $rates = $this->rates($country);

        $this->assertSame(['manual'], array_column($rates, 'driver'));
        $this->assertSame('acme', resolve(ShippingQuoteContext::class)->lastFailure()?->driver);
    }

    public function test_komerce_delivery_without_a_service_falls_back_to_shipping_cost(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('komerce.couriers', []);
        $this->acme->configured = false;
        Http::preventStrayRequests();
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['calculate_reguler' => [], 'calculate_cargo' => [], 'calculate_instant' => []],
            ]),
            'https://cost.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [['code' => 'jne', 'name' => 'JNE', 'service' => 'REG', 'cost' => 12000, 'etd' => '2']],
            ]),
        ]);

        [$zone, $country] = $this->zone();
        $this->carrier($zone, 'jne', 'JNE Express', 'rajaongkir');
        Inventory::factory()->create([
            'is_default' => true,
            'rajaongkir_origin_id' => '17248',
            'latitude' => -6.7366,
            'longitude' => 108.5414,
        ]);

        $rates = $this->rates($country, ['pin_point' => '-6.1754,106.8272']);

        $this->assertSame(['jne:REG'], array_column($rates, 'service_code'));
        $this->assertSame('rajaongkir', $rates[0]['driver']);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/tariff/api/v1/calculate'));
    }

    public function test_paid_shipments_are_fulfilled_by_their_own_driver(): void
    {
        // An open order: the factory picks a random status, and a cancelled /
        // completed / archived order is (rightly) never booked.
        $order = Order::factory()->create(['currency_code' => 'IDR', 'status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
        $acmeShipment = $this->shipment($order, 'acme');
        $manualShipment = $this->shipment($order, 'manual');

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame([$acmeShipment->id], $this->acme->fulfilled);
        $this->assertSame('ACME-'.$acmeShipment->id, $acmeShipment->refresh()->awb);
        $this->assertNull($manualShipment->refresh()->awb);
    }

    public function test_labels_cancel_and_tracking_go_through_the_driver_contracts(): void
    {
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        $shipment = $this->shipment($order, 'acme', ['awb' => 'ACME-AWB-1']);

        $label = resolve(PrintShipmentLabels::class)->handle($order);
        $this->assertSame('https://labels.acme.test/ACME-AWB-1.pdf', $label['label_url']);

        resolve(CancelShipmentsOnOrderCancelled::class)->handle(new OrderCancelled($order));
        $this->assertSame([$shipment->id], $this->acme->cancelled);

        // Acme only implements Shopper's track(): the history is stored neutrally.
        $tracked = resolve(RefreshShipmentTracking::class)->handle($shipment->refresh());

        $this->assertSame('in_transit', $tracked->status);
        $this->assertSame('Paket di hub Cirebon', data_get($tracked->metadata, 'tracking_history.0.description'));
        $this->assertSame('2026-11-17 09:30', data_get($tracked->metadata, 'tracking_history.0.datetime'));
        $this->assertSame('acme', data_get($tracked->metadata, 'tracked_by'));
    }

    public function test_labels_without_a_printing_provider_give_a_neutral_message(): void
    {
        $this->acme->configured = false;
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        $this->shipment($order, 'acme', ['awb' => 'ACME-AWB-1']);

        $this->expectExceptionMessage('Label pengiriman otomatis belum tersedia');

        resolve(PrintShipmentLabels::class)->handle($order);
    }

    public function test_carrier_sync_runs_every_syncing_driver(): void
    {
        $this->artisan('shipping:sync-carriers')
            ->expectsOutputToContain('[acme] acme synced')
            ->assertSuccessful();

        $this->acme->syncFails = true;

        $this->artisan('shipping:sync-carriers', ['--driver' => 'acme'])
            ->expectsOutputToContain('[acme] acme is down')
            ->assertFailed();
    }

    public function test_generic_commands_are_successful_no_ops_without_a_provider(): void
    {
        $this->acme->configured = false;
        Http::preventStrayRequests();
        Http::fake();

        foreach ([
            'shipping:fulfill-paid' => 'paid shipments are left to staff',
            'shipping:refresh-tracking' => 'skipping tracking refresh',
            'shipping:sync-carriers' => 'nothing to do',
            'orders:expire-unpaid' => 'Expired 0 order(s)',
            // The old Komerce names stay working for ops scripts.
            'komerce:fulfill-paid-orders' => 'paid shipments are left to staff',
            'komerce:refresh-shipment-tracking' => 'skipping tracking refresh',
            'komerce:sync-carriers' => 'nothing to do',
            'komerce:expire-unpaid-orders' => 'Expired 0 order(s)',
        ] as $command => $output) {
            $this->artisan($command)->expectsOutputToContain($output)->assertSuccessful();
        }

        Http::assertNothingSent();
    }

    public function test_schedules_only_run_when_a_configured_driver_has_the_capability(): void
    {
        $this->acme->configured = false;

        foreach (['shipping:fulfill-paid', 'shipping:refresh-tracking', 'shipping:sync-carriers', 'orders:expire-unpaid', 'komerce:capabilities'] as $command) {
            $this->assertFalse($this->scheduled($command)->filtersPass($this->app), "{$command} must not run keyless.");
        }

        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');

        $this->assertTrue($this->scheduled('shipping:fulfill-paid')->filtersPass($this->app));
        $this->assertTrue($this->scheduled('shipping:refresh-tracking')->filtersPass($this->app));
        $this->assertFalse($this->scheduled('shipping:sync-carriers')->filtersPass($this->app), 'Komerce Delivery alone syncs no carriers.');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        $this->assertTrue($this->scheduled('shipping:sync-carriers')->filtersPass($this->app));
    }

    public function test_fallback_shipment_copies_the_order_and_invents_no_courier(): void
    {
        $this->acme->configured = false;
        Inventory::factory()->create(['is_default' => true]);
        $order = Order::factory()->create(['currency_code' => 'IDR', 'shipping_option_id' => null]);

        $shipment = resolve(EnsureOrderShipments::class)->handle($order)->first();

        $this->assertInstanceOf(OrderShipment::class, $shipment);
        $this->assertNull($shipment->carrier_code);
        $this->assertNull($shipment->service_code);
        $this->assertNull($shipment->driver);
    }

    /**
     * @return array<string, list<string>>
     */
    private function chainCodes(): array
    {
        return array_map(
            static fn (array $drivers): array => array_map(static fn ($driver): string => $driver->code(), $drivers),
            resolve(ShippingCapabilities::class)->quoteChains(),
        );
    }

    private function scheduled(string $command): ScheduledEvent
    {
        $event = collect(app(Schedule::class)->events())
            ->first(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, $command));

        $this->assertInstanceOf(ScheduledEvent::class, $event, "{$command} is not scheduled.");

        return $event;
    }

    /**
     * @return array{0: Zone, 1: Country}
     */
    private function zone(): array
    {
        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        return [$zone, $country];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function carrier(Zone $zone, string $slug, string $name, string $driver, array $metadata = []): Carrier
    {
        $carrier = Carrier::query()->create([
            'slug' => $slug,
            'name' => $name,
            'driver' => $driver,
            'is_enabled' => true,
            'metadata' => $metadata,
        ]);
        $zone->carriers()->attach($carrier->id);

        return $carrier;
    }

    private function manualOption(Zone $zone): CarrierOption
    {
        $carrier = $this->carrier($zone, 'kurir-toko', 'Kurir Toko', 'manual');

        return CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<int, array<string, mixed>>
     */
    private function rates(Country $country, array $extra = []): array
    {
        return resolve(FetchDeliveryRates::class)->handle([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'country_id' => $country->id,
            'destination_id' => '17547',
            'destination_driver' => 'rajaongkir',
            ...$extra,
        ], [new Package(10, 10, 10, 0.5)], itemValue: 100000);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shipment(Order $order, string $driver, array $attributes = []): OrderShipment
    {
        return OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => $driver,
            'carrier_code' => 'sicepat',
            'service_code' => 'BEST',
            'status' => 'pending',
            'cost' => 21000,
            'currency_code' => 'IDR',
            ...$attributes,
        ]);
    }
}

/**
 * A stand-in for a second provider module (Biteship, Shipper.id...).
 */
final class AcmeShippingDriver extends Driver implements CancelsShipments, FulfillsShipments, PrintsLabels, SyncsCarriers
{
    public bool $configured = true;

    public bool $failQuotes = false;

    public bool $syncFails = false;

    /** @var list<ShippingRate> */
    public array $rates = [];

    /** @var list<string> */
    public array $quotedCouriers = [];

    /** @var list<int> */
    public array $fulfilled = [];

    /** @var list<int> */
    public array $cancelled = [];

    public function code(): string
    {
        return 'acme';
    }

    public function name(): string
    {
        return 'Acme';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        if ($this->failQuotes) {
            throw new \RuntimeException('acme timeout');
        }

        $context = resolve(ShippingQuoteContext::class);
        $this->quotedCouriers = $context->couriers();

        foreach ($this->rates as $index => $rate) {
            $context->describe($this->code(), $rate->serviceCode, ['acme_reference' => 'acme-row-'.($index + 1)]);
        }

        return collect($this->rates);
    }

    public function createShipment(Address $from, Address $to, array $packages, string $serviceCode): Shipment
    {
        return new Shipment(trackingNumber: 'unused', carrierCode: 'acme', serviceCode: $serviceCode);
    }

    public function fulfillShipment(OrderShipment $shipment): void
    {
        $this->fulfilled[] = (int) $shipment->id;
        $shipment->forceFill(['awb' => 'ACME-'.$shipment->id, 'external_reference' => 'acme-'.$shipment->id])->save();
    }

    public function printShipmentLabels(iterable $shipments, ?string $format = null): array
    {
        $awbs = [];

        foreach ($shipments as $shipment) {
            $awbs[] = (string) $shipment->awb;
        }

        return ['label_url' => 'https://labels.acme.test/'.implode('-', $awbs).'.pdf', 'pdf' => null];
    }

    public function cancelShipment(OrderShipment $shipment): void
    {
        $this->cancelled[] = (int) $shipment->id;
    }

    public function syncCarriers(bool $dryRun = false): CarrierSyncResult
    {
        return $this->syncFails
            ? CarrierSyncResult::failed('acme is down')
            : new CarrierSyncResult(true, 'acme synced');
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        return new TrackingInfo(
            trackingNumber: $trackingNumber,
            status: 'in_transit',
            statusDescription: 'On the way',
            events: [new TrackingEvent(
                status: 'HUB',
                description: 'Paket di hub Cirebon',
                occurredAt: Carbon::parse('2026-11-17 09:30:00'),
                location: 'Cirebon',
            )],
        );
    }
}
