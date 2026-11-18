<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Komerce\Jobs\CreateRajaOngkirDeliveryForShipment;
use App\Livewire\Shopper\InventoryForm;
use App\Models\OrderShipment;
use App\Models\User;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShippingCapabilities;
use App\Support\CarrierLogos;
use App\Support\OrderShipmentOpsPresenter;
use Database\Seeders\CarrierSeeder;
use Database\Seeders\ZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Zone;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\DataTransferObjects\Shipment;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Generic shipment operations (allocation lock, cpanel presenter, tracking
 * timeline, retry crons, origin field, destination rows, carriers, seeding)
 * work for any provider driver from neutral data, never from a provider's
 * own metadata bucket or copy.
 */
final class ProviderNeutralOpsTest extends TestCase
{
    use RefreshDatabase;

    private NeutralAreaDriver $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = new NeutralAreaDriver;
        $area = $this->area;
        Shipping::extend('areaship', static fn (): NeutralAreaDriver => $area);
        config()->set('shopper.shipping.drivers.areaship.enabled', true);
    }

    public function test_presenter_uses_neutral_keys_and_copy_for_any_provider(): void
    {
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        $registered = $this->shipment($order, 'areaship', [
            'external_reference' => 'AREA-ORDER-1',
            'metadata' => ['fulfillment_error' => 'Saldo provider kurang'],
        ]);
        $pickedUp = $this->shipment($order, 'areaship', [
            'external_reference' => 'AREA-ORDER-2',
            'metadata' => ['pickup_requested_at' => '2026-11-18T08:00:00+07:00'],
        ]);
        $manual = $this->shipment($order, 'manual');

        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $rows = collect($presenter->shipments($order))->keyBy('id');

        $this->assertSame('AREA-ORDER-1', $rows[$registered->id]['delivery_order_no']);
        $this->assertSame('Saldo provider kurang', $rows[$registered->id]['fulfillment_error']);
        $this->assertTrue($rows[$registered->id]['needs_pickup']);
        $this->assertFalse($rows[$registered->id]['can_override']);
        $this->assertFalse($rows[$pickedUp->id]['needs_pickup']);
        $this->assertStringContainsString('Pickup sudah diminta', (string) $rows[$pickedUp->id]['print_hint']);
        $this->assertTrue($rows[$manual->id]['can_override']);
        $this->assertStringContainsString('manual', (string) $rows[$manual->id]['print_hint']);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Komerce', (string) $row['print_hint']);
        }

        $this->assertSame('Dibatalkan', $presenter->statusLabel('cancelled'));
    }

    public function test_komerce_bucket_is_mirrored_to_the_neutral_keys(): void
    {
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        $shipment = $this->shipment($order, 'rajaongkir');

        $shipment->update(['metadata' => ['komerce' => [
            'order_no' => 'KOM-1',
            'fulfillment_error' => 'Store order gagal',
            'tracking_history' => [['description' => 'Diterima kurir', 'datetime' => '2026-11-18 09:00', 'location' => 'Cirebon']],
        ]]]);

        $shipment->refresh();
        $this->assertSame('Store order gagal', data_get($shipment->metadata, 'fulfillment_error'));
        $this->assertSame('Diterima kurir', data_get($shipment->metadata, 'tracking_history.0.description'));
        $this->assertNull(data_get($shipment->metadata, 'pickup_requested_at'));

        $metadata = $shipment->metadata;
        unset($metadata['komerce']['fulfillment_error']);
        $metadata['komerce']['pickup_response'] = ['meta' => ['status' => 'success']];
        $shipment->update(['metadata' => $metadata]);

        $shipment->refresh();
        $this->assertNull(data_get($shipment->metadata, 'fulfillment_error'));
        $this->assertNotNull(data_get($shipment->metadata, 'pickup_requested_at'));
    }

    public function test_customer_pages_show_the_neutral_tracking_history_of_any_driver(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $user->id, 'currency_code' => 'IDR']);
        $this->shipment($order, 'areaship', [
            'awb' => 'AREA-AWB',
            'metadata' => [
                // The driver's raw bucket is never read by generic pages.
                'areaship' => ['tracking_history' => [['description' => 'raw']]],
                'tracking_history' => [['description' => 'Paket di hub', 'datetime' => '2026-11-18 10:00', 'location' => 'Bandung']],
            ],
        ]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shipments.0.tracking_history.0.description', 'Paket di hub')
                ->where('shipments.0.tracking_history.0.location', 'Bandung'));

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders/'.$order->number)
            ->assertOk()
            ->assertJsonPath('data.shipments.0.tracking_history.0.description', 'Paket di hub')
            ->assertJsonPath('data.shipments.0.tracking_history.0.datetime', '2026-11-18 10:00')
            // Alias read by app builds released before `datetime` was used.
            ->assertJsonPath('data.shipments.0.tracking_history.0.date', '2026-11-18 10:00');
    }

    public function test_fulfil_cron_is_not_starved_by_manual_shipments(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->shipment($this->paidOrder(), 'manual');
        }
        $provider = $this->shipment($this->paidOrder(), 'areaship');

        $this->artisan('shipping:fulfill-paid', ['--limit' => 2])
            ->expectsOutputToContain('Fulfilled 1 paid shipment(s); 0 failed; 0 left to staff.')
            ->assertSuccessful();

        $this->assertSame([$provider->id], $this->area->fulfilled);
    }

    public function test_tracking_cron_is_not_starved_by_hand_entered_awbs(): void
    {
        $order = Order::factory()->create(['currency_code' => 'IDR']);

        for ($i = 0; $i < 3; $i++) {
            $this->shipment($order, 'manual', ['awb' => 'HAND-'.$i]);
        }
        $provider = $this->shipment($order, 'areaship', ['awb' => 'AREA-AWB-9', 'external_reference' => 'AREA-9']);

        $this->artisan('shipping:refresh-tracking', ['--limit' => 2])
            ->expectsOutputToContain('Refreshed 1 shipment(s); 0 failed.')
            ->assertSuccessful();

        $this->assertSame(['AREA-AWB-9'], $this->area->tracked);
        $this->assertSame('in_transit', $provider->refresh()->status);
    }

    public function test_operator_switch_turns_a_configured_driver_off(): void
    {
        $capabilities = resolve(ShippingCapabilities::class);
        $this->assertTrue($capabilities->drivers()->has('areaship'));
        $this->assertNotNull($capabilities->fulfillerForCode('areaship'));

        config()->set('shopper.shipping.drivers.areaship.enabled', false);

        $this->assertFalse($capabilities->drivers()->has('areaship'));
        $this->assertNull($capabilities->fulfillerForCode('areaship'));
        $this->assertFalse($capabilities->destinationSearch());
        $this->assertSame([], $capabilities->fulfilledDriverCodes());
    }

    public function test_destination_rows_get_the_neutral_region_keys(): void
    {
        $this->area->searchRows = [
            ['id' => 'IDNP6IDNC148', 'label' => 'Coblong, Bandung'],
            ['id' => 'IDNP6IDNC149', 'label' => 'Sukajadi, Bandung', 'postal_code' => '40162', 'city' => 'Bandung', 'state' => 'Jawa Barat', 'extra' => 'kept'],
            ['label' => 'no id'],
        ];

        $this->actingAs(User::factory()->create())
            ->getJson('/checkout/destinations?q=bandung')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'IDNP6IDNC148')
            ->assertJsonPath('data.0.postal_code', null)
            ->assertJsonPath('data.0.city', null)
            ->assertJsonPath('data.0.state', null)
            ->assertJsonPath('data.1.postal_code', '40162')
            ->assertJsonPath('data.1.extra', 'kept');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/checkout/destinations?q=bandung')
            ->assertOk()
            ->assertJsonPath('data.0.postal_code', null)
            ->assertJsonPath('data.1.state', 'Jawa Barat');
    }

    public function test_location_form_accepts_string_area_ids_for_drivers_without_rules(): void
    {
        $admin = $this->admin();
        $inventory = Inventory::factory()->create([
            'country_id' => Country::factory()->create(['cca2' => 'ID'])->id,
            'phone_number' => '081234567890',
        ]);

        Livewire::actingAs($admin)
            ->test(InventoryForm::class, ['inventory' => $inventory])
            ->set('data.priority', 0)
            ->set('data.shipping_origins.areaship', 'IDNP6IDNC148IDND836IDZ12410')
            ->call('store')
            ->assertHasNoErrors();

        $this->assertSame('IDNP6IDNC148IDND836IDZ12410', resolve(InventoryOrigins::class)->get($inventory, 'areaship'));
    }

    public function test_rajaongkir_origin_ids_stay_numeric_through_the_driver_rules(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->area->configured = false;
        $admin = $this->admin();
        $inventory = Inventory::factory()->create([
            'country_id' => Country::factory()->create(['cca2' => 'ID'])->id,
            'phone_number' => '081234567890',
        ]);

        Livewire::actingAs($admin)
            ->test(InventoryForm::class, ['inventory' => $inventory])
            ->set('data.priority', 0)
            ->set('data.shipping_origins.rajaongkir', 'IDNP6')
            ->call('store')
            ->assertHasErrors(['data.shipping_origins.rajaongkir']);
    }

    public function test_order_shipping_uses_the_recorded_carrier_and_never_an_unrelated_one(): void
    {
        $order = Order::factory()->create(['currency_code' => 'IDR']);
        // An enabled carrier that has nothing to do with the parcel.
        Carrier::query()->create(['name' => 'Unrelated', 'slug' => 'unrelated', 'is_enabled' => true, 'driver' => 'manual']);
        $own = Carrier::query()->create(['name' => 'Kurir Area', 'slug' => 'kurir-area', 'is_enabled' => true, 'driver' => 'areaship']);

        $recorded = $this->shipment($order, 'areaship', ['carrier_id' => $own->id, 'carrier_code' => 'xyz', 'awb' => 'AWB-REC']);
        $unknown = $this->shipment($order, 'areaship', ['carrier_code' => 'nobody', 'awb' => 'AWB-UNK']);

        resolve(SyncOrderShippingFromShipments::class)->handle($order);

        $this->assertSame($own->id, (int) OrderShipping::query()->where('tracking_number', 'AWB-REC')->value('carrier_id'));
        $this->assertNull(OrderShipping::query()->where('tracking_number', 'AWB-UNK')->value('carrier_id'));
        $this->assertNotNull($recorded->refresh());
        $this->assertNotNull($unknown->refresh());
    }

    public function test_rates_carry_the_carrier_id_and_unregistered_drivers_are_skipped_quietly(): void
    {
        Exceptions::fake();
        $this->area->configured = false;
        [$zone, $country] = $this->zone();
        $manual = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'is_enabled' => true, 'driver' => 'manual']);
        // A carrier left behind by a provider module that was removed.
        $orphan = Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'is_enabled' => true, 'driver' => 'removed-provider']);
        $zone->carriers()->attach([$manual->id, $orphan->id]);
        CarrierOption::query()->create(['carrier_id' => $manual->id, 'zone_id' => $zone->id, 'name' => 'Reguler', 'price' => 15000, 'is_enabled' => true]);
        CarrierOption::query()->create(['carrier_id' => $orphan->id, 'zone_id' => $zone->id, 'name' => 'REG', 'price' => 9000, 'is_enabled' => true]);

        $rates = resolve(FetchDeliveryRates::class)->handle(
            ['country_id' => $country->id, 'city' => 'Cirebon', 'postal_code' => '45153'],
            [new Package(10, 10, 10, 1.0)],
        );

        $this->assertCount(1, $rates);
        $this->assertSame($manual->id, $rates[0]['carrier_id']);
        Exceptions::assertNothingReported();
    }

    public function test_carrier_logos_resolve_namespaced_slugs_by_courier_code(): void
    {
        $carrier = new Carrier(['slug' => 'rajaongkir-jne', 'metadata' => ['courier_code' => 'jne']]);

        $this->assertStringContainsString('images/couriers/jne.svg', (string) resolve(CarrierLogos::class)->forCarrier($carrier));
    }

    public function test_migration_copies_neutral_shipment_metadata_and_drops_provider_cdn_logos(): void
    {
        $order = Order::factory()->create();
        $inventory = Inventory::factory()->create();
        $id = (int) DB::table('order_shipments')->insertGetId([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'metadata' => json_encode(['komerce' => [
                'tracking_history' => [['description' => 'Diterima kurir']],
                'fulfillment_error' => 'gagal',
                'pickup_response' => ['meta' => ['status' => 'success']],
            ]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cdn = Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'metadata' => ['logo_url' => 'https://storage.googleapis.com/komship-bucket/shipment/jne-logo.png', 'courier_code' => 'jne']]);
        $own = Carrier::query()->create(['name' => 'Toko', 'slug' => 'toko', 'metadata' => ['logo_url' => 'https://cdn.example.test/toko.png']]);

        (require database_path('migrations/2026_11_18_000400_add_carrier_and_neutral_metadata_to_order_shipments.php'))->up();

        $metadata = json_decode((string) DB::table('order_shipments')->where('id', $id)->value('metadata'), true);
        $this->assertSame('Diterima kurir', data_get($metadata, 'tracking_history.0.description'));
        $this->assertSame('gagal', $metadata['fulfillment_error']);
        $this->assertNotEmpty($metadata['pickup_requested_at']);
        $this->assertArrayHasKey('komerce', $metadata);

        $this->assertNull(data_get($cdn->refresh()->metadata, 'logo_url'));
        $this->assertSame('jne', data_get($cdn->metadata, 'courier_code'));
        $this->assertSame('https://cdn.example.test/toko.png', data_get($own->refresh()->metadata, 'logo_url'));
    }

    public function test_seeding_attaches_provider_contributed_rows_without_listing_them_in_zones_json(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        Country::factory()->create(['cca2' => 'ID', 'cca3' => 'IDN', 'name' => 'Indonesia']);

        // What a provider module's seeder would have created (PaymentMethodSeeder::extend / CarrierSeeder::extend).
        $method = PaymentMethod::query()->create(['title' => 'Acme VA', 'slug' => 'acme-va', 'driver' => 'acme', 'is_enabled' => true]);
        $carrier = Carrier::query()->create(['name' => 'Acme Express', 'slug' => 'acme-express', 'driver' => 'areaship', 'is_enabled' => true]);
        $disabled = PaymentMethod::query()->create(['title' => 'Old', 'slug' => 'old', 'driver' => 'acme', 'is_enabled' => false]);

        $this->seed([CarrierSeeder::class, ZoneSeeder::class]);

        $zone = Zone::query()->where('slug', 'indonesia')->firstOrFail();
        $this->assertTrue($zone->paymentMethods()->whereKey($method->id)->exists());
        $this->assertTrue($zone->carriers()->whereKey($carrier->id)->exists());
        $this->assertFalse($zone->paymentMethods()->whereKey($disabled->id)->exists());

        $zonesJson = (string) file_get_contents(database_path('seeders/data/zones.json'));
        $this->assertStringNotContainsStringIgnoringCase('komerce', $zonesJson);

        // The generic carrier seeder itself seeds only Shopper's manual carrier.
        $this->assertStringNotContainsStringIgnoringCase('rajaongkir', (string) file_get_contents(database_path('seeders/CarrierSeeder.php')));
    }

    public function test_jobs_queued_under_the_previous_class_name_still_unserialize(): void
    {
        $legacy = 'App\Jobs\CreateRajaOngkirDeliveryForShipment';

        $this->assertTrue(class_exists($legacy));

        $payload = str_replace(
            'O:'.strlen(CreateRajaOngkirDeliveryForShipment::class).':"'.CreateRajaOngkirDeliveryForShipment::class.'"',
            'O:'.strlen($legacy).':"'.$legacy.'"',
            serialize(new CreateRajaOngkirDeliveryForShipment(42)),
        );

        $job = unserialize($payload);

        $this->assertInstanceOf(CreateRajaOngkirDeliveryForShipment::class, $job);
        $this->assertSame(42, $job->orderShipmentId);
    }

    public function test_shopper_facing_legal_text_names_no_provider(): void
    {
        $legal = (string) file_get_contents(base_path('mobile/lib/legal-content.json'));

        $this->assertStringNotContainsStringIgnoringCase('komerce', $legal);
        $this->assertStringNotContainsStringIgnoringCase('rajaongkir', $legal);
    }

    private function paidOrder(): Order
    {
        return Order::factory()->create(['currency_code' => 'IDR', 'payment_status' => PaymentStatus::Paid]);
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
 * A provider with string area ids that registers, tracks and searches
 * (Biteship-like), implementing only the neutral contracts.
 */
final class NeutralAreaDriver extends Driver implements FulfillsShipments, ResolvesDestinations
{
    public bool $configured = true;

    /** @var list<int> */
    public array $fulfilled = [];

    /** @var list<string> */
    public array $tracked = [];

    /** @var list<array<string, mixed>> */
    public array $searchRows = [];

    public function code(): string
    {
        return 'areaship';
    }

    public function name(): string
    {
        return 'AreaShip';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }

    public function createShipment(Address $from, Address $to, array $packages, string $serviceCode): Shipment
    {
        return new Shipment(trackingNumber: 'unused', carrierCode: 'areaship', serviceCode: $serviceCode);
    }

    public function fulfillShipment(OrderShipment $shipment): void
    {
        $this->fulfilled[] = (int) $shipment->id;
        $shipment->forceFill(['awb' => 'AREA-'.$shipment->id, 'external_reference' => 'area-'.$shipment->id])->save();
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        $this->tracked[] = $trackingNumber;

        return new TrackingInfo(trackingNumber: $trackingNumber, status: 'in_transit', statusDescription: 'On the way', events: []);
    }

    public function searchDestinations(string $query, int $limit = 10): array
    {
        return $this->searchRows;
    }

    public function resolveOrigin(Inventory $inventory): ?array
    {
        return null;
    }
}
