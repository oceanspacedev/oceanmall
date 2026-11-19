<?php

declare(strict_types=1);

namespace App\Integrations\Biteship;

use App\Integrations\Biteship\Database\BiteshipCarrierSeeder;
use App\Integrations\Biteship\Shipping\BiteshipShippingDriver;
use App\Integrations\Biteship\Support\BiteshipCouriers;
use App\Support\CarrierLogos;
use Database\Seeders\CarrierSeeder;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Shopper\Shipping\Facades\Shipping;

/**
 * The Biteship module: Biteship rates, area search, orders (pickup +
 * waybill), tracking, cancellation and courier sync, wired into the store
 * only through Shopper's extension points (Shipping::extend, CarrierSeeder,
 * CarrierLogos) and the store's shipping contracts (app/Shipping/Contracts).
 *
 * Plug and play: the module is always registered, but takes part only while
 * BITESHIP_API_KEY is set (and allowed for the environment, see Biteship) and
 * the operator switch shopper.shipping.drivers.biteship.enabled is on.
 * Keyless it has no effect on checkout, cpanel, schedules or webhooks
 * (which answer 200 {status: disabled}).
 *
 * Nothing is scheduled here: the generic shipping:sync-carriers,
 * shipping:fulfill-paid and shipping:refresh-tracking commands drive the
 * driver's contracts and are scheduled only while a configured driver
 * supports them. Removing this provider line (and the folder) removes
 * Biteship entirely.
 */
final class BiteshipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The single source of Biteship credentials and options. A published
        // config/biteship.php (vendor:publish --tag=biteship-config) overrides
        // these defaults key by key.
        $this->mergeConfigFrom(__DIR__.'/config/biteship.php', 'biteship');

        $this->registerLogoSources();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/biteship.php' => config_path('biteship.php'),
        ], 'biteship-config');

        Shipping::extend(BiteshipShippingDriver::CODE, static fn (): BiteshipShippingDriver => resolve(BiteshipShippingDriver::class));

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            CarrierSeeder::extend(BiteshipCarrierSeeder::class);
        }
    }

    /**
     * The webhook URL is configured in the Biteship dashboard: keep it stable.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(__DIR__.'/routes/webhooks.php');
    }

    /**
     * Biteship courier codes whose local logo file has another name
     * (idexpress → images/couriers/ide.svg), also for a namespaced slug
     * ("biteship-idexpress"). Local files only: no provider CDN URL is ever
     * returned or stored.
     */
    private function registerLogoSources(): void
    {
        $this->app->afterResolving(CarrierLogos::class, static function (CarrierLogos $logos): void {
            $logos->extend(static function (string $slug): ?string {
                $code = str_starts_with($slug, Biteship::CODE.'-') ? substr($slug, strlen(Biteship::CODE) + 1) : $slug;
                $alias = BiteshipCouriers::LOGO_ALIASES[$code] ?? ($code !== $slug ? $code : null);

                return $alias !== null ? CarrierLogos::local($alias) : null;
            });
        });
    }
}
