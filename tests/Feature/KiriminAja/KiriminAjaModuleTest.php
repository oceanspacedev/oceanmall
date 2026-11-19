<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use App\Integrations\KiriminAja\Database\KiriminAjaCarrierSeeder;
use App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController;
use App\Integrations\KiriminAja\KiriminAja;
use App\Integrations\KiriminAja\KiriminAjaServiceProvider;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\Contracts\ValidatesOriginIds;
use App\Support\CarrierLogos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Shopper\Core\Models\Carrier;
use Shopper\Shipping\Facades\Shipping;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * KiriminAja is a self-contained module (app/Integrations/KiriminAja) wired
 * only by KiriminAjaServiceProvider: these tests pin what it owns and that
 * generic store code never reaches into it.
 */
final class KiriminAjaModuleTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    public function test_the_provider_is_registered_as_a_module(): void
    {
        $this->assertContains(KiriminAjaServiceProvider::class, require base_path('bootstrap/providers.php'));
        $this->assertInstanceOf(KiriminAjaServiceProvider::class, $this->app->getProvider(KiriminAjaServiceProvider::class));
    }

    public function test_it_registers_its_shopper_driver_with_the_capability_contracts(): void
    {
        $this->assertContains(KiriminAjaDriver::CODE, Shipping::availableDrivers());

        $driver = Shipping::driver(KiriminAjaDriver::CODE);
        $this->assertInstanceOf(KiriminAjaDriver::class, $driver);

        foreach ([
            ResolvesDestinations::class, RequiresDestination::class, ValidatesOriginIds::class, FulfillsShipments::class,
            TracksShipments::class, ReconcilesShipments::class, CancelsShipments::class, SyncsCarriers::class,
        ] as $contract) {
            $this->assertInstanceOf($contract, $driver);
        }

        $this->assertTrue($driver->supportsRealTimeRates());
        $this->assertTrue($driver->supportsTracking());
        $this->assertFalse($driver->supportsLabels());
    }

    public function test_it_merges_its_config_as_the_single_source_of_credentials(): void
    {
        $this->assertSame('https://tdev.kiriminaja.com', config('kiriminaja.base_url'), 'Sandbox host outside production.');
        $this->assertSame(['enabled'], array_keys(config('shopper.shipping.drivers.kiriminaja')));
        $this->assertSame(12, config('kiriminaja.rate_cache_hours'));
        $this->assertSame('api/mitra/v6.1/request_pickup', config('kiriminaja.pickup.endpoint'));
        $this->assertFileDoesNotExist(config_path('kiriminaja.php'), 'The module ships its config; publish it only to override.');
    }

    public function test_production_has_no_sandbox_default(): void
    {
        $original = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        try {
            $config = require app_path('Integrations/KiriminAja/config/kiriminaja.php');
        } finally {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $original ?? 'testing';
            putenv('APP_ENV='.($original ?? 'testing'));
        }

        $this->assertNull($config['base_url']);

        config()->set('kiriminaja.api_key', 'live-key');
        config()->set('kiriminaja.base_url', null);
        $this->assertFalse(KiriminAja::configured(), 'A production key without an explicit base URL never ships through the sandbox.');
        $this->assertFalse(Shipping::driver(KiriminAjaDriver::CODE)->isConfigured());
    }

    public function test_it_owns_the_callback_route(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.kiriminaja.callback');

        $this->assertNotNull($route);
        $this->assertSame('webhooks/kiriminaja', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(KiriminAjaWebhookController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:300,1', $route->gatherMiddleware());
    }

    public function test_it_registers_its_command(): void
    {
        $this->assertArrayHasKey('kiriminaja:set-callback', Artisan::all());
    }

    public function test_origin_ids_accept_kecamatan_or_kecamatan_and_kelurahan(): void
    {
        $rules = ['origin' => Shipping::driver(KiriminAjaDriver::CODE)->originIdRules()];

        foreach (['1536', '1536:22010', null] as $valid) {
            $this->assertTrue(Validator::make(['origin' => $valid], $rules)->passes(), (string) $valid);
        }

        foreach (['abc', '1536:', 'IDNP6IDNC148', '1:2:3'] as $invalid) {
            $this->assertFalse(Validator::make(['origin' => $invalid], $rules)->passes(), $invalid);
        }
    }

    public function test_it_seeds_its_default_couriers_without_taking_over_other_drivers_slugs(): void
    {
        $this->disableKiriminAja();
        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE (RajaOngkir)', 'driver' => 'rajaongkir', 'is_enabled' => true]);

        $this->seed(KiriminAjaCarrierSeeder::class);
        $this->seed(KiriminAjaCarrierSeeder::class);

        $carriers = Carrier::query()->where('driver', KiriminAjaDriver::CODE)->orderBy('slug')->get();

        $this->assertCount(10, $carriers, 'Seeding twice creates nothing new.');
        $this->assertSame(
            ['anteraja', 'idx', 'jnt', 'kiriminaja-jne', 'lion', 'ninja', 'posindonesia', 'sap', 'sicepat', 'tiki'],
            $carriers->pluck('slug')->sort()->values()->all(),
        );
        $this->assertSame(0, $carriers->where('is_enabled', true)->count(), 'Keyless: seeded couriers stay off.');
        $this->assertSame('jne', data_get(Carrier::query()->where('slug', 'kiriminaja-jne')->first()?->metadata, 'courier_code'));
        $this->assertSame('rajaongkir', Carrier::query()->where('slug', 'jne')->value('driver'));
    }

    public function test_seeded_couriers_start_enabled_once_configured(): void
    {
        $this->configureKiriminAja();

        $this->seed(KiriminAjaCarrierSeeder::class);

        $this->assertSame(10, Carrier::query()->where('driver', KiriminAjaDriver::CODE)->where('is_enabled', true)->count());
    }

    public function test_courier_codes_with_another_logo_file_name_use_the_local_logo(): void
    {
        $logos = resolve(CarrierLogos::class);

        $pos = new Carrier(['slug' => 'posindonesia', 'driver' => KiriminAjaDriver::CODE]);
        $this->assertStringContainsString('images/couriers/pos.svg', (string) $logos->forCarrier($pos));

        $idx = new Carrier(['slug' => 'kiriminaja-idx', 'driver' => KiriminAjaDriver::CODE, 'metadata' => ['courier_code' => 'idx']]);
        $this->assertStringContainsString('images/couriers/ide.svg', (string) $logos->forCarrier($idx));
    }

    public function test_generic_store_code_never_references_the_module(): void
    {
        $offenders = [];

        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), base_path('routes'), base_path('bootstrap'), config_path(), database_path(), resource_path('views')])
            ->exclude(['Integrations', 'cache'])
            ->notPath('#^providers\.php$#');

        foreach ($finder as $file) {
            if (str_contains($file->getContents(), 'Integrations\\KiriminAja')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Only bootstrap/providers.php may name the KiriminAja module.');
    }

    public function test_the_module_does_not_reach_into_other_provider_modules(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->name('*.php')->in(app_path('Integrations/KiriminAja')) as $file) {
            if (preg_match('/Integrations\\\\(?!KiriminAja)[A-Z]/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
