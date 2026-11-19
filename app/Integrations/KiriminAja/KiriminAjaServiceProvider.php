<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja;

use App\Integrations\KiriminAja\Console\SetKiriminAjaCallback;
use App\Integrations\KiriminAja\Database\KiriminAjaCarrierSeeder;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Integrations\KiriminAja\Support\KiriminAjaCouriers;
use App\Support\CarrierLogos;
use Database\Seeders\CarrierSeeder;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Shopper\Shipping\Facades\Shipping;

/**
 * The KiriminAja module: express shipping through KiriminAja's Mitra API
 * (kecamatan search, tariffs, request pickup, tracking, cancel, courier
 * sync, callbacks), wired into the store only through Shopper's extension
 * points (Shipping::extend) and the store's small contracts
 * (app/Shipping/Contracts).
 *
 * Plug and play: always registered, it takes part only once
 * KIRIMINAJA_API_KEY is set (KiriminAjaDriver::isConfigured()) and the
 * operator switch `shopper.shipping.drivers.kiriminaja.enabled` is on. With
 * no key it has no effect on checkout, cpanel, schedules (the generic
 * shipping:* commands skip unconfigured drivers) or callbacks (answered
 * 200 {status: disabled}). Removing this provider from
 * bootstrap/providers.php (and the folder) removes the module entirely.
 */
final class KiriminAjaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The single source of KiriminAja credentials and options. A published
        // config/kiriminaja.php (vendor:publish --tag=kiriminaja-config)
        // overrides these defaults key by key.
        $this->mergeConfigFrom(__DIR__.'/config/kiriminaja.php', 'kiriminaja');
        $this->registerLogoSources();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/kiriminaja.php' => config_path('kiriminaja.php'),
        ], 'kiriminaja-config');

        Shipping::extend(KiriminAjaDriver::CODE, static fn (): KiriminAjaDriver => resolve(KiriminAjaDriver::class));

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([SetKiriminAjaCallback::class]);

            CarrierSeeder::extend(KiriminAjaCarrierSeeder::class);
        }
    }

    /**
     * The callback URL is stored at KiriminAja (kiriminaja:set-callback):
     * keep webhooks/kiriminaja and the name webhooks.kiriminaja.callback stable.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(__DIR__.'/routes/webhooks.php');
    }

    /**
     * KiriminAja courier codes whose local logo file has another name
     * (posindonesia → pos, idx → ide). Local files only, no provider CDN.
     */
    private function registerLogoSources(): void
    {
        $this->app->afterResolving(CarrierLogos::class, static function (CarrierLogos $logos): void {
            $logos->extend(static fn (string $slug): ?string => isset(KiriminAjaCouriers::LOGO_ALIASES[$slug])
                ? CarrierLogos::local(KiriminAjaCouriers::LOGO_ALIASES[$slug])
                : null);
        });
    }
}
