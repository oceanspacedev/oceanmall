<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Livewire\Shopper\InventoryForm;
use App\Models\OrderShipment;
use App\Models\User;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\RequiresOriginId;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShipmentTimeline;
use App\Shipping\ShippingCapabilities;
use App\Shipping\ShippingQuoteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderCompleted;
use Shopper\Core\Events\Orders\OrderShipmentCreated;
use Shopper\Core\Events\Orders\OrderShipmentDelivered;
use Shopper\Core\Events\Orders\OrderShipped;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\OrderShippingEvent;
use Shopper\Core\Models\Zone;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\DataTransferObjects\ShippingRate;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Provider-driven shipment lifecycle for any driver: Shopper events and the
 * Shopper shipment event log, status normalisation, tracking of hand-entered
 * AWBs, carrier ownership of quotes, origin fields, a second destination
 * driver and zone options next to provider quotes.
 */
final class ProviderShipmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_scans_are_recorded_once_in_the_shopper_event_log_and_not_shown_twice(): void
    {
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        $shipment = $this->shipment($order, 'plainship', [
            'awb' => 'AWB-LOG',
            'status' => 'in_transit',
            'metadata' => ['tracking_history' => [
                ['description' => 'Paket di hub Cirebon', 'datetime' => '2026-11-18 10:00', 'location' => 'Cirebon'],
                ['description' => 'Paket diterima kurir', 'datetime' => '2026-11-18 08:00', 'location' => 'Jakarta'],
                ['description' => 'Tanpa waktu', 'datetime' => null, 'location' => null],
            ]],
        ]);

        $sync = resolve(SyncOrderShippingFromShipments::class);
        $sync->handle($order);
        $sync->handle($order->refresh());

        $events = OrderShippingEvent::query()->orderBy('occurred_at')->get();
        $this->assertCount(2, $events);
        $this->assertSame('Paket diterima kurir', $events[0]->description);
        $this->assertSame(ShipmentStatus::InTransit, $events[1]->status);
        $this->assertSame('Cirebon', $events[1]->location);
        $this->assertSame(ShipmentTimeline::PROVIDER_SOURCE, data_get($events[1]->metadata, 'source'));
        $this->assertSame((int) OrderShipping::query()->where('tracking_number', 'AWB-LOG')->value('id'), (int) $events[0]->order_shipping_id);

        // A new scan is appended; known ones are not recorded again.
        $shipment->forceFill(['metadata' => ['tracking_history' => [
            ['description' => 'Package delivered to receiver', 'datetime' => '2026-11-19 09:00', 'location' => 'Cirebon'],
            ...$shipment->metadata['tracking_history'],
        ]]])->save();
        $sync->handle($order->refresh());

        $this->assertSame(3, OrderShippingEvent::query()->count());
        $this->assertSame(ShipmentStatus::Delivered, OrderShippingEvent::query()->latest('occurred_at')->first()->status);

        // The customer timeline shows the provider history once.
        $timeline = ShipmentTimeline::forShipments((int) $order->id, collect([$shipment->refresh()]))[$shipment->id];
        $this->assertCount(4, $timeline);
    }

    public function test_provider_transitions_fire_shopper_events_once_including_a_jump_to_delivered(): void
    {
        Event::fake([OrderShipmentCreated::class, OrderShipped::class, OrderShipmentDelivered::class, OrderCompleted::class]);
        $order = Order::factory()->create(['currency_code' => 'IDR', 'status' => OrderStatus::Processing, 'shipping_status' => ShippingStatus::Unfulfilled]);
        $this->shipment($order, 'plainship', ['awb' => 'AWB-JUMP', 'status' => 'delivered']);

        $sync = resolve(SyncOrderShippingFromShipments::class);
        $sync->handle($order);
        $sync->handle($order->refresh());

        Event::assertDispatchedTimes(OrderShipmentCreated::class, 1);
        Event::assertDispatchedTimes(OrderShipped::class, 1);
        Event::assertDispatchedTimes(OrderShipmentDelivered::class, 1);
        Event::assertDispatchedTimes(OrderCompleted::class, 1);
        $this->assertSame(ShippingStatus::Delivered, $order->refresh()->shipping_status);
        $this->assertSame(OrderStatus::Completed, $order->status);
    }

    public function test_shipped_order_moving_on_to_delivered_does_not_fire_order_shipped_again(): void
    {
        Event::fake([OrderShipped::class, OrderShipmentDelivered::class]);
        $order = Order::factory()->create(['currency_code' => 'IDR', 'status' => OrderStatus::Processing, 'shipping_status' => ShippingStatus::Unfulfilled]);
        $shipment = $this->shipment($order, 'plainship', ['awb' => 'AWB-STEP', 'status' => 'in_transit']);

        $sync = resolve(SyncOrderShippingFromShipments::class);
        $sync->handle($order);
        $shipment->forceFill(['status' => 'delivered'])->save();
        $sync->handle($order->refresh());
        $sync->handle($order->refresh());

        Event::assertDispatchedTimes(OrderShipped::class, 1);
        Event::assertDispatchedTimes(OrderShipmentDelivered::class, 1);
    }

    public function test_status_from_a_shopper_track_answer_is_normalised(): void
    {
        $driver = $this->register(new PlainTrackDriver);
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        $shipment = $this->shipment($order, 'plainship', ['awb' => 'AWB-NORM', 'status' => 'labeled']);

        $driver->status = 'out_for_delivery';
        $this->assertSame('in_transit', resolve(RefreshShipmentTracking::class)->handle($shipment)->status);

        // A provider's own word that maps to nothing keeps the current status.
        $driver->status = 'on_hold';
        $this->assertSame('in_transit', resolve(RefreshShipmentTracking::class)->handle($shipment->refresh())->status);

        $driver->status = 'delivered';
        $this->assertSame('delivered', resolve(RefreshShipmentTracking::class)->handle($shipment->refresh())->status);
    }

    public function test_tracking_cron_polls_hand_entered_awbs_through_a_tracking_driver_with_its_own_budget(): void
    {
        $driver = $this->register(new FulfilTrackDriver);
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        $provider = $this->shipment($order, 'fullship', ['awb' => 'PROV-1', 'external_reference' => 'FS-1']);
        $handA = $this->shipment($order, 'manual', ['awb' => 'HAND-A', 'updated_at' => now()->subHours(2)]);
        $handB = $this->shipment($order, 'manual', ['awb' => 'HAND-B', 'updated_at' => now()->subHour()]);
        $this->shipment($order, 'manual', ['awb' => 'HAND-DONE', 'status' => 'delivered']);
        $this->shipment($order, 'manual');

        $this->artisan('shipping:refresh-tracking', ['--limit' => 1])
            ->expectsOutputToContain('Refreshed 2 shipment(s); 0 failed.')
            ->assertSuccessful();

        // The provider shipment and the least recently refreshed manual AWB.
        $this->assertSame(['PROV-1', 'HAND-A'], $driver->tracked);
        $this->assertSame('in_transit', $provider->refresh()->status);
        $this->assertSame('in_transit', $handA->refresh()->status);

        // Next run rotates to the other hand-entered AWB.
        $this->artisan('shipping:refresh-tracking', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('HAND-B', $driver->tracked[3]);
        $this->assertSame('in_transit', $handB->refresh()->status);
    }

    public function test_provider_rates_take_the_carrier_of_the_owning_driver(): void
    {
        $this->register(new QuoteDriver);
        [$zone, $country] = $this->zone();
        // Another driver owns the plain 'jne' slug; the quoting driver's carrier is namespaced.
        $other = Carrier::query()->create(['name' => 'JNE Lain', 'slug' => 'jne', 'is_enabled' => true, 'driver' => 'manual']);
        $own = Carrier::query()->create(['name' => 'JNE Quote', 'slug' => 'quoteship-jne', 'metadata' => ['courier_code' => 'jne'], 'is_enabled' => true, 'driver' => 'quoteship']);
        $zone->carriers()->attach([$other->id, $own->id]);
        Inventory::factory()->create(['is_default' => true]);

        $rates = resolve(FetchDeliveryRates::class)->handle(['country_id' => $country->id, 'postal_code' => '45153'], [new Package(10, 10, 10, 1.0)]);

        $this->assertCount(1, $rates);
        $this->assertSame($own->id, $rates[0]['carrier_id']);
        $this->assertSame('JNE Quote', $rates[0]['carrier_name']);
    }

    public function test_zone_options_can_be_offered_next_to_provider_quotes(): void
    {
        $this->register(new QuoteDriver);
        [$zone, $country] = $this->zone();
        $own = Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'is_enabled' => true, 'driver' => 'quoteship']);
        $pickup = Carrier::query()->create(['name' => 'Ambil di toko', 'slug' => 'pickup', 'is_enabled' => true, 'driver' => 'manual']);
        $zone->carriers()->attach([$own->id, $pickup->id]);
        $option = CarrierOption::query()->create(['carrier_id' => $pickup->id, 'zone_id' => $zone->id, 'name' => 'Ambil di toko', 'price' => 0, 'is_enabled' => true]);
        // A zone option of the provider's own carrier is never priced from the zone.
        CarrierOption::query()->create(['carrier_id' => $own->id, 'zone_id' => $zone->id, 'name' => 'Flat JNE', 'price' => 9000, 'is_enabled' => true]);
        Inventory::factory()->create(['is_default' => true]);
        $address = ['country_id' => $country->id, 'postal_code' => '45153'];
        $packages = [new Package(10, 10, 10, 1.0)];

        $this->assertFalse(FetchDeliveryRates::combineZoneOptions());
        $this->assertSame(['quoteship'], array_column(resolve(FetchDeliveryRates::class)->handle($address, $packages), 'driver'));

        config()->set('shopper.shipping.combine_zone_options', 'true');
        $rates = resolve(FetchDeliveryRates::class)->handle($address, $packages);

        $this->assertSame(['quoteship', 'manual'], array_column($rates, 'driver'));
        $this->assertSame($option->id, $rates[1]['service_code']);
        $this->assertSame($pickup->id, $rates[1]['carrier_id']);
    }

    public function test_combined_zone_options_stay_when_the_provider_answers_nothing(): void
    {
        $this->register(new SilentQuoteDriver);
        [$zone, $country] = $this->zone();
        $own = Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'is_enabled' => true, 'driver' => 'silentship']);
        $pickup = Carrier::query()->create(['name' => 'Ambil di toko', 'slug' => 'pickup', 'is_enabled' => true, 'driver' => 'manual']);
        $zone->carriers()->attach([$own->id, $pickup->id]);
        $option = CarrierOption::query()->create(['carrier_id' => $pickup->id, 'zone_id' => $zone->id, 'name' => 'Ambil di toko', 'price' => 0, 'is_enabled' => true]);
        CarrierOption::query()->create(['carrier_id' => $own->id, 'zone_id' => $zone->id, 'name' => 'Flat JNE', 'price' => 9000, 'is_enabled' => true]);
        Inventory::factory()->create(['is_default' => true]);
        $address = ['country_id' => $country->id, 'postal_code' => '45153'];
        $packages = [new Package(10, 10, 10, 1.0)];

        // Neither switch: no courier, checkout explains why.
        $this->assertSame([], resolve(FetchDeliveryRates::class)->handle($address, $packages));

        // Combined: the store's own option does not vanish when the couriers fail,
        // and the provider's carrier is still never priced from the zone.
        config()->set('shopper.shipping.combine_zone_options', true);
        $rates = resolve(FetchDeliveryRates::class)->handle($address, $packages);

        $this->assertSame([$option->id], array_column($rates, 'service_code'));
        $this->assertSame([$pickup->id], array_column($rates, 'carrier_id'));
    }

    public function test_location_form_offers_an_origin_field_for_drivers_without_destination_search(): void
    {
        $this->register(new OriginOnlyDriver);
        $this->assertTrue(resolve(ShippingCapabilities::class)->originDrivers()->has('pickship'));

        $admin = $this->admin();
        $inventory = Inventory::factory()->create([
            'country_id' => Country::factory()->create(['cca2' => 'ID'])->id,
            'phone_number' => '081234567890',
        ]);

        Livewire::actingAs($admin)
            ->test(InventoryForm::class, ['inventory' => $inventory])
            ->assertFormFieldExists('shipping_origins.pickship')
            ->set('data.priority', 0)
            ->set('data.shipping_origins.pickship', 'PICKUP-POINT-7')
            ->call('store')
            ->assertHasNoErrors();

        $this->assertSame('PICKUP-POINT-7', resolve(InventoryOrigins::class)->get($inventory, 'pickship'));
    }

    public function test_a_second_destination_driver_gets_the_id_its_search_finds_for_the_postal_code(): void
    {
        $this->register(new AreaDriver('areaone', []));
        $second = $this->register(new AreaDriver('areatwo', [
            ['id' => 'B-NEAR', 'label' => 'Kesambi', 'postal_code' => '45131'],
            ['id' => 'B-9', 'label' => 'Harjamukti, Cirebon', 'postal_code' => '45153'],
        ]));
        $this->assertSame('areaone', resolve(ShippingCapabilities::class)->destinationDriverCode());

        $context = resolve(ShippingQuoteContext::class);
        $context->begin(['postal_code' => '45153', 'destination_id' => 'A-1', 'destination_driver' => 'areaone'], null);

        $this->assertSame('A-1', $context->destinationId('areaone'));
        $this->assertSame('B-9', $context->destinationId('areatwo'));
        $this->assertSame('B-9', $context->destinationId('areatwo'));
        $this->assertSame(['45153'], $second->searched, 'searched once per quote');

        // The primary driver never guesses from the postal code.
        $context->begin(['postal_code' => '45153'], null);
        $this->assertNull($context->destinationId('areaone'));

        $context->begin(['postal_code' => '99999'], null);
        $this->assertNull($context->destinationId('areatwo'));
        $context->end();
    }

    public function test_a_second_destination_driver_without_postal_code_search_matches_by_area_name_once(): void
    {
        $this->register(new AreaDriver('areaone', []));
        // This provider's search only knows area names, never postal codes.
        $second = $this->register(new NamedAreaDriver('areatwo', [
            ['id' => 'B-CITY', 'label' => 'Kota Cirebon', 'postal_code' => '45111'],
            ['id' => 'B-9', 'label' => 'Harjamukti, Kota Cirebon', 'postal_code' => '45153'],
        ]));

        $context = resolve(ShippingQuoteContext::class);
        $address = ['postal_code' => '45153', 'city' => 'Kota Cirebon', 'destination_id' => 'A-1', 'destination_driver' => 'areaone'];

        $context->begin($address, null);
        $this->assertSame('B-9', $context->destinationId('areatwo'), 'only a row with the exact postal code counts');
        $this->assertSame(['45153', 'Kota Cirebon'], $second->searched);

        // The next quote (another request) reuses the answer: no provider call.
        $context->begin($address, null);
        $this->assertSame('B-9', $context->destinationId('areatwo'));
        $this->assertSame(['45153', 'Kota Cirebon'], $second->searched);

        // No exact postal-code match anywhere: nothing, and remembered too.
        $context->begin(['postal_code' => '45999', 'city' => 'Kota Cirebon'], null);
        $this->assertNull($context->destinationId('areatwo'));
        $context->begin(['postal_code' => '45999', 'city' => 'Kota Cirebon'], null);
        $this->assertNull($context->destinationId('areatwo'));
        $this->assertSame(['45153', 'Kota Cirebon', '45999', 'Kota Cirebon'], $second->searched);
        $context->end();
    }

    /**
     * @template T of Driver
     *
     * @param  T  $driver
     * @return T
     */
    private function register(Driver $driver): Driver
    {
        Shipping::extend($driver->code(), static fn (): Driver => $driver);
        config()->set('shopper.shipping.drivers.'.$driver->code().'.enabled', true);

        return $driver;
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

    private function admin(): User
    {
        $this->configureShopperCpanel();

        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        return $admin;
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
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
            'cost' => 21000,
            'currency_code' => 'IDR',
            ...$attributes,
        ]);
    }
}

abstract class FakeDriver extends Driver
{
    public function name(): string
    {
        return ucfirst($this->code());
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }
}

/** A driver relying on Shopper's track(string) with its own status words. */
final class PlainTrackDriver extends FakeDriver
{
    public string $status = 'in_transit';

    public function code(): string
    {
        return 'plainship';
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        return new TrackingInfo(trackingNumber: $trackingNumber, status: $this->status, statusDescription: 'Provider says '.$this->status);
    }
}

final class FulfilTrackDriver extends FakeDriver implements FulfillsShipments, TracksShipments
{
    /** @var list<string> */
    public array $tracked = [];

    public function code(): string
    {
        return 'fullship';
    }

    public function fulfillShipment(OrderShipment $shipment): void {}

    public function trackShipment(OrderShipment $shipment): TrackingInfo
    {
        $awb = (string) ($shipment->awb ?: $shipment->tracking_number);
        $this->tracked[] = $awb;

        return new TrackingInfo(trackingNumber: $awb, status: 'in_transit', statusDescription: 'On the way');
    }
}

final class QuoteDriver extends FakeDriver
{
    public function code(): string
    {
        return 'quoteship';
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect([new ShippingRate('REG', 'Reguler', 18000, 'IDR', 'jne', '2-3')]);
    }
}

final class OriginOnlyDriver extends FakeDriver implements RequiresOriginId
{
    public function code(): string
    {
        return 'pickship';
    }
}

final class AreaDriver extends FakeDriver implements ResolvesDestinations
{
    /** @var list<string> */
    public array $searched = [];

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(private readonly string $driverCode, private readonly array $rows) {}

    public function code(): string
    {
        return $this->driverCode;
    }

    public function searchDestinations(string $query, int $limit = 10): array
    {
        $this->searched[] = $query;

        return $this->rows;
    }

    public function resolveOrigin(Inventory $inventory): ?array
    {
        return null;
    }
}

/** Search by area name only: the query must appear in the label. */
final class NamedAreaDriver extends FakeDriver implements ResolvesDestinations
{
    /** @var list<string> */
    public array $searched = [];

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(private readonly string $driverCode, private readonly array $rows) {}

    public function code(): string
    {
        return $this->driverCode;
    }

    public function searchDestinations(string $query, int $limit = 10): array
    {
        $this->searched[] = $query;

        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => str_contains(mb_strtolower((string) $row['label']), mb_strtolower($query)),
        ));
    }

    public function resolveOrigin(Inventory $inventory): ?array
    {
        return null;
    }
}

/** A configured provider that has no service for any route. */
final class SilentQuoteDriver extends FakeDriver
{
    public function code(): string
    {
        return 'silentship';
    }
}
