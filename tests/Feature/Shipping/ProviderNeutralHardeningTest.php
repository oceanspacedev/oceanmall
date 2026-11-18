<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Integrations\Komerce\Database\KomerceCarrierSeeder;
use App\Models\OrderShipment;
use App\Models\User;
use App\Payment\PaymentMethodCatalog;
use App\Shipping\ShipmentTimeline;
use App\Shipping\ShippingCapabilities;
use App\Support\OperatorSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\OrderShippingEvent;
use Shopper\Payment\Drivers\ManualDriver;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Round-3 hardening of the provider-neutral refactor: operator switches as
 * Shopper reads them, reversible data migrations, polite provider seeders,
 * Shopper's own shipment events on the customer timeline and the manual QA
 * call-graph script.
 */
final class ProviderNeutralHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_switch_follows_shoppers_convention_and_parses_env_values(): void
    {
        Shipping::extend('switchy', static fn (): SwitchyDriver => new SwitchyDriver);
        $capabilities = resolve(ShippingCapabilities::class);

        // No config entry: off, exactly like ShippingManager::configuredDrivers().
        $this->assertNull(OperatorSwitch::state(OperatorSwitch::SHIPPING, 'switchy'));
        $this->assertFalse($capabilities->drivers()->has('switchy'));
        $this->assertFalse(Shipping::configuredDrivers()->has('switchy'));

        foreach (['0', 'off', 'false', 'no', '', '(false)'] as $off) {
            config()->set('shopper.shipping.drivers.switchy.enabled', $off);
            $this->assertFalse($capabilities->drivers()->has('switchy'), "'{$off}' switches the driver off.");
        }

        foreach ([true, '1', 'on', 'true', 'yes', '(true)'] as $on) {
            config()->set('shopper.shipping.drivers.switchy.enabled', $on);
            $this->assertTrue($capabilities->drivers()->has('switchy'), var_export($on, true).' switches the driver on.');
        }

        // Payments use the same rule; Shopper's manual driver has no switch.
        $this->assertTrue((new PaymentMethodCatalog)->driverReady('manual'));
        Payment::extend('nokey', static fn (): ManualDriver => new ManualDriver);
        $this->assertFalse((new PaymentMethodCatalog)->driverReady('nokey'));
        config()->set('shopper.payment.drivers.nokey.enabled', 'on');
        $this->assertTrue((new PaymentMethodCatalog)->driverReady('nokey'));
    }

    public function test_config_files_parse_the_env_switches_as_booleans(): void
    {
        $shipping = (string) file_get_contents(config_path('shopper/shipping.php'));
        $payment = (string) file_get_contents(config_path('shopper/payment.php'));

        $this->assertStringContainsString("filter_var(env('SHIPPING_RAJAONGKIR_ENABLED', true), FILTER_VALIDATE_BOOLEAN)", $shipping);
        $this->assertStringContainsString("filter_var(env('SHIPPING_KOMERCE_ENABLED', true), FILTER_VALIDATE_BOOLEAN)", $shipping);
        $this->assertStringContainsString("filter_var(env('PAYMENT_KOMERCE_ENABLED', true), FILTER_VALIDATE_BOOLEAN)", $payment);
        $this->assertTrue(config('shopper.shipping.drivers.rajaongkir.enabled'));
        $this->assertFalse(config('shopper.shipping.fallback_to_zone_options'));
    }

    public function test_migration_backs_up_provider_logo_urls_restores_them_and_backfills_carrier_ids(): void
    {
        $migration = require database_path('migrations/2026_11_18_000400_add_carrier_and_neutral_metadata_to_order_shipments.php');
        $cdnUrl = 'https://storage.googleapis.com/komship-bucket/shipment/jne-logo.png';

        $roJne = Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'driver' => 'rajaongkir', 'metadata' => ['logo_url' => $cdnUrl, 'courier_code' => 'jne']]);
        $otherJne = Carrier::query()->create(['name' => 'JNE (Area)', 'slug' => 'area-jne', 'driver' => 'areaship', 'metadata' => ['courier_code' => 'jne']]);
        $sicepat = Carrier::query()->create(['name' => 'SiCepat', 'slug' => 'sicepat', 'driver' => 'rajaongkir']);

        $order = Order::factory()->create();
        $inventory = Inventory::factory()->create();
        $shipment = fn (?string $driver, string $code): int => (int) DB::table('order_shipments')->insertGetId([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => $driver,
            'carrier_code' => $code,
            'carrier_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ownedByDriver = $shipment('rajaongkir', 'jne');
        $ambiguous = $shipment('manual', 'jne');
        $single = $shipment(null, 'sicepat');
        $unknown = $shipment('rajaongkir', 'nobody');

        $migration->up();

        $carrierId = static fn (int $id): mixed => DB::table('order_shipments')->where('id', $id)->value('carrier_id');
        $this->assertSame($roJne->id, (int) $carrierId($ownedByDriver), 'The carrier the shipment driver owns.');
        $this->assertNull($carrierId($ambiguous), 'Two carriers share the code and neither is the driver\'s: left null.');
        $this->assertSame($sicepat->id, (int) $carrierId($single));
        $this->assertNull($carrierId($unknown));

        $metadata = $roJne->refresh()->metadata;
        $this->assertArrayNotHasKey('logo_url', $metadata, 'A store without Komerce never loads the CDN logo.');
        $this->assertSame($cdnUrl, $metadata['provider_logo_url_backup'], 'The URL is kept, not lost.');
        $this->assertNull(data_get($otherJne->refresh()->metadata, 'provider_logo_url_backup'));

        $migration->down();
        $this->assertSame($cdnUrl, data_get($roJne->refresh()->metadata, 'logo_url'), 'Rollback restores the URL.');
        $this->assertArrayNotHasKey('provider_logo_url_backup', $roJne->metadata);

        $migration->up();
    }

    public function test_komerce_carrier_seeder_never_takes_over_or_re_enables_carriers(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $foreign = Carrier::query()->create(['name' => 'JNE (Area)', 'slug' => 'jne', 'driver' => 'areaship', 'is_enabled' => true]);
        $disabled = Carrier::query()->create([
            'name' => 'SiCepat Custom',
            'slug' => 'sicepat',
            'driver' => 'rajaongkir',
            'is_enabled' => false,
            'metadata' => ['courier_code' => 'sicepat'],
        ]);

        // Keyless (TestCase blanks every Komerce key).
        $this->seed(KomerceCarrierSeeder::class);

        $this->assertSame('areaship', $foreign->refresh()->driver, 'A foreign carrier keeps its driver.');
        $this->assertTrue((bool) $foreign->is_enabled);

        $this->assertFalse((bool) $disabled->refresh()->is_enabled, 'Staff choice is kept.');
        $this->assertSame('SiCepat Custom', $disabled->name);

        $roJne = Carrier::query()->where('slug', 'rajaongkir-jne')->sole();
        $this->assertSame('rajaongkir', $roJne->driver);
        $this->assertSame('jne', data_get($roJne->metadata, 'courier_code'));

        $this->assertSame(
            0,
            Carrier::query()->where('driver', 'rajaongkir')->where('is_enabled', true)->count(),
            'Keyless: provider couriers are seeded switched off.',
        );
        $this->assertSame(1, Carrier::query()->where('driver', 'rajaongkir')->where('slug', 'like', '%sicepat%')->count());

        // Seeding again changes nothing.
        $count = Carrier::query()->count();
        $this->seed(KomerceCarrierSeeder::class);
        $this->assertSame($count, Carrier::query()->count());
        Http::assertNothingSent();
    }

    public function test_staff_recorded_shopper_shipment_events_reach_the_customer_timeline(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $user->id, 'currency_code' => 'IDR']);
        $inventory = Inventory::factory()->create();
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'manual',
            'awb' => 'AWB-MANUAL-1',
            'status' => 'in_transit',
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);

        // What Shopper's ShipmentAddEvent (RecordShipmentEventAction) writes.
        $shipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'tracking_number' => 'AWB-MANUAL-1',
            'status' => ShipmentStatus::InTransit,
            'shipped_at' => now(),
        ]);
        OrderShippingEvent::query()->create([
            'order_shipping_id' => $shipping->id,
            'status' => ShipmentStatus::PickedUp,
            'description' => 'Paket diambil kurir toko',
            'location' => 'Cirebon',
            'occurred_at' => now()->subHours(3),
        ]);
        OrderShippingEvent::query()->create([
            'order_shipping_id' => $shipping->id,
            'status' => ShipmentStatus::InTransit,
            'description' => null,
            'occurred_at' => now()->subHour(),
        ]);

        $timeline = ShipmentTimeline::forShipments((int) $order->id, [$shipment])[$shipment->id];
        $this->assertCount(2, $timeline);
        $this->assertSame(ShipmentStatus::InTransit->getLabel(), $timeline[0]['description'], 'Newest first; status label when no description.');
        $this->assertSame('Paket diambil kurir toko', $timeline[1]['description']);
        $this->assertSame('Cirebon', $timeline[1]['location']);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shipments.0.tracking_history.1.description', 'Paket diambil kurir toko'));

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders/'.$order->number)
            ->assertOk()
            ->assertJsonPath('data.shipments.0.tracking_history.1.description', 'Paket diambil kurir toko')
            ->assertJsonPath('data.shipments.0.tracking_history.1.location', 'Cirebon');
    }

    public function test_provider_history_and_shopper_events_are_merged_newest_first(): void
    {
        $order = Order::factory()->create();
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'awb' => 'AWB-9',
            'metadata' => ['tracking_history' => [
                ['description' => 'Tiba di hub', 'datetime' => now()->subHours(2)->format('Y-m-d H:i:s')],
            ]],
        ]);
        $shipping = OrderShipping::query()->create(['order_id' => $order->id, 'tracking_number' => 'AWB-9', 'shipped_at' => now()]);
        OrderShippingEvent::query()->create([
            'order_shipping_id' => $shipping->id,
            'status' => ShipmentStatus::OutForDelivery,
            'description' => 'Dibawa kurir',
            'occurred_at' => now()->subHour(),
        ]);

        $timeline = ShipmentTimeline::forShipments((int) $order->id, collect([$shipment]))[$shipment->id];

        $this->assertSame(['Dibawa kurir', 'Tiba di hub'], array_column($timeline, 'description'));
    }

    public function test_the_fulfilment_call_graph_script_passes(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/ast-fulfillment-verify.php')], base_path());
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('AST OK', $process->getOutput());
    }
}

final class SwitchyDriver extends Driver
{
    public function code(): string
    {
        return 'switchy';
    }

    public function name(): string
    {
        return 'Switchy';
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
}
