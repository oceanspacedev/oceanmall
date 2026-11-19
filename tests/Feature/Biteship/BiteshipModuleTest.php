<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Integrations\Biteship\BiteshipServiceProvider;
use App\Integrations\Biteship\Database\BiteshipCarrierSeeder;
use App\Integrations\Biteship\Http\Controllers\BiteshipWebhookController;
use App\Integrations\Biteship\Shipping\BiteshipShippingDriver;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\NormalizesSelectedRates;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\RequiresCoordinates;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\TracksShipments;
use App\Support\CarrierLogos;
use App\Support\EnabledCarriers;
use Illuminate\Support\Facades\Route;
use Shopper\Core\Models\Carrier;
use Shopper\Shipping\Facades\Shipping;
use Symfony\Component\Finder\Finder;

/**
 * Biteship is a self-contained module (app/Integrations/Biteship) wired only
 * by BiteshipServiceProvider; generic store code never reaches into it.
 */
final class BiteshipModuleTest extends BiteshipTestCase
{
    public function test_the_provider_is_registered_as_a_module(): void
    {
        $this->assertContains(BiteshipServiceProvider::class, require base_path('bootstrap/providers.php'));
        $this->assertInstanceOf(BiteshipServiceProvider::class, $this->app->getProvider(BiteshipServiceProvider::class));
    }

    public function test_it_registers_its_shopper_driver_with_the_supported_contracts(): void
    {
        $this->assertContains('biteship', Shipping::availableDrivers());
        $driver = Shipping::driver('biteship');

        $this->assertInstanceOf(BiteshipShippingDriver::class, $driver);

        foreach ([ResolvesDestinations::class, RequiresDestination::class, SyncsCarriers::class, FulfillsShipments::class, TracksShipments::class, CancelsShipments::class, ReconcilesShipments::class, NormalizesSelectedRates::class] as $contract) {
            $this->assertInstanceOf($contract, $driver);
        }

        // No label API at Biteship; instant couriers are left out instead of
        // forcing customers to pin their address.
        $this->assertNotInstanceOf(PrintsLabels::class, $driver);
        $this->assertNotInstanceOf(RequiresCoordinates::class, $driver);
        $this->assertFalse($driver->supportsLabels());
    }

    public function test_it_merges_its_config_as_the_single_source_of_credentials(): void
    {
        $defaults = require app_path('Integrations/Biteship/config/biteship.php');

        $this->assertSame('https://api.biteship.com', $defaults['base_url']);
        $this->assertSame(12, $defaults['rate_cache_hours']);
        $this->assertNotNull(config('biteship.timeout'));
        $this->assertSame(['enabled'], array_keys(config('shopper.shipping.drivers.biteship')));
        $this->assertFileDoesNotExist(config_path('biteship.php'), 'The module ships its config; publish it only to override.');
    }

    public function test_it_owns_its_webhook_route(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.biteship.order');

        $this->assertNotNull($route);
        $this->assertSame('webhooks/biteship', $route->uri());
        $this->assertSame(BiteshipWebhookController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
    }

    public function test_generic_store_code_never_references_the_module(): void
    {
        $offenders = [];

        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), base_path('routes'), base_path('bootstrap'), config_path(), database_path(), resource_path('views')])
            ->exclude(['Integrations', 'cache'])
            ->notPath('#^providers\.php$#');

        foreach ($finder as $file) {
            if (str_contains($file->getContents(), 'Integrations\\Biteship')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Only bootstrap/providers.php may name the Biteship module.');
    }

    public function test_the_module_never_imports_another_provider_module(): void
    {
        $finder = (new Finder)->files()->name('*.php')->in(app_path('Integrations/Biteship'));

        foreach ($finder as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/Integrations\\\\(Komerce|KiriminAja|Midtrans|Xendit)/',
                $file->getContents(),
                $file->getRelativePathname().' must stay independent of other modules',
            );
        }
    }

    public function test_the_seeder_contributes_inactive_default_couriers_without_a_key(): void
    {
        // A carrier another driver already owns under the plain slug.
        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE', 'is_enabled' => true, 'driver' => 'rajaongkir']);

        $this->seed(BiteshipCarrierSeeder::class);
        $this->seed(BiteshipCarrierSeeder::class); // idempotent

        $owned = Carrier::query()->where('driver', 'biteship')->get();

        $this->assertCount(10, $owned);
        $this->assertSame(0, $owned->where('is_enabled', true)->count());
        $this->assertSame(
            ['anteraja', 'idexpress', 'jne', 'jnt', 'lion', 'ninja', 'pos', 'sap', 'sicepat', 'tiki'],
            $owned->map(static fn (Carrier $carrier): string => EnabledCarriers::courierCode($carrier))->sort()->values()->all(),
        );
        $this->assertTrue($owned->contains('slug', 'biteship-jne'), 'Another driver\'s slug is never taken over');
        $this->assertSame('rajaongkir', Carrier::query()->where('slug', 'jne')->value('driver'));
    }

    public function test_courier_logos_resolve_to_local_files_only(): void
    {
        $logos = resolve(CarrierLogos::class);

        $idexpress = new Carrier(['slug' => 'biteship-idexpress', 'metadata' => [EnabledCarriers::COURIER_CODE => 'idexpress']]);
        $this->assertStringContainsString('images/couriers/ide.svg', (string) $logos->forCarrier($idexpress));

        $jne = new Carrier(['slug' => 'biteship-jne', 'metadata' => [EnabledCarriers::COURIER_CODE => 'jne']]);
        $this->assertStringContainsString('images/couriers/jne.svg', (string) $logos->forCarrier($jne));
    }
}
