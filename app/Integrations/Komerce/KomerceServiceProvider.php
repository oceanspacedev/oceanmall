<?php

declare(strict_types=1);

namespace App\Integrations\Komerce;

use App\Integrations\Komerce\Console\KomerceCapabilitiesCommand;
use App\Integrations\Komerce\Console\KomerceUat;
use App\Integrations\Komerce\Console\SimulateKomercePayment;
use App\Integrations\Komerce\Database\KomerceCarrierSeeder;
use App\Integrations\Komerce\Database\KomercePaymentMethodSeeder;
use App\Integrations\Komerce\Exceptions\KomercePaidAfterCancellation;
use App\Integrations\Komerce\Jobs\CreateRajaOngkirDeliveryForShipment;
use App\Integrations\Komerce\Observers\KomerceShipmentReferenceObserver;
use App\Integrations\Komerce\Payment\KomerceDriver;
use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Integrations\Komerce\Support\KomerceCourierAssets;
use App\Integrations\Komerce\Support\KomercePaymentLookupContext;
use App\Integrations\Komerce\Support\KomerceTrackingContext;
use App\Models\OrderShipment;
use App\Support\CarrierLogos;
use App\Support\PaymentLogos;
use Database\Seeders\CarrierSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LogLevel;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\Facades\Shipping;
use Shopper\ShopperPanel;

/**
 * The Komerce module: Komerce Payment API (VA / QRIS), QRISLY, RajaOngkir
 * Shipping Cost and Komerce Shipping Delivery, wired into the store only
 * through Shopper's extension points (Payment::extend / Shipping::extend, a
 * panel addon, Shopper events via the generic listeners) and the store's
 * small contracts (app/Payment/Contracts, app/Shipping/Contracts).
 *
 * Everything Komerce lives under app/Integrations/Komerce. Removing this
 * provider from bootstrap/providers.php (and the folder) leaves a working
 * pure-Shopper store: manual payment methods, Shopper carriers with zone
 * shipping options and manual AWB entry in cpanel.
 */
final class KomerceServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> class name used by older releases => current class */
    public const LEGACY_CLASSES = [
        'App\Jobs\CreateRajaOngkirDeliveryForShipment' => CreateRajaOngkirDeliveryForShipment::class,
    ];

    public function register(): void
    {
        // The single source of Komerce credentials and options. A published
        // config/komerce.php (php artisan vendor:publish --tag=komerce-config)
        // overrides these defaults key by key.
        $this->mergeConfigFrom(__DIR__.'/config/komerce.php', 'komerce');

        // Memoise the learned plan state and per-call lookup contexts per
        // request / job (Octane-safe).
        $this->app->scoped(KomerceCapabilities::class);
        $this->app->scoped(KomerceTrackingContext::class);
        $this->app->scoped(KomercePaymentLookupContext::class);

        $this->registerAddon();
        $this->registerLogoSources();
        $this->registerLegacyClassNames();
    }

    /**
     * Payloads queued by the release before the module existed name the
     * delivery job by its old class. Alias it so those jobs (5 tries, 60 s
     * backoff) still unserialize and run after the deploy. Remove once no
     * such job can be left in the queue.
     */
    private function registerLegacyClassNames(): void
    {
        foreach (self::LEGACY_CLASSES as $legacy => $current) {
            if (! class_exists($legacy, false)) {
                class_alias($current, $legacy);
            }
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'komerce');

        $this->publishes([
            __DIR__.'/config/komerce.php' => config_path('komerce.php'),
        ], 'komerce-config');

        $this->registerDrivers();
        $this->registerRoutes();
        $this->registerCommands();
        $this->registerSchedules();
        $this->registerReportLevels();

        OrderShipment::observe(KomerceShipmentReferenceObserver::class);

        if ($this->app->runningInConsole()) {
            PaymentMethodSeeder::extend(KomercePaymentMethodSeeder::class);
            CarrierSeeder::extend(KomerceCarrierSeeder::class);
        }
    }

    /**
     * Shopper drivers. Availability is each driver's isConfigured() (product
     * key + plan, read per request). The `shopper.*.drivers.*.enabled`
     * entries are operator switches only (config/shopper/shipping.php,
     * config/shopper/payment.php): they are never overwritten with the
     * learned plan state, so a runtime plan change takes effect without a
     * reboot.
     */
    private function registerDrivers(): void
    {
        Payment::extend(KomerceDriver::CODE, static fn (): KomerceDriver => resolve(KomerceDriver::class));

        Shipping::extend(RajaOngkirDriver::CODE, static fn (): RajaOngkirDriver => resolve(RajaOngkirDriver::class));
        Shipping::extend(KomerceShippingDriver::CODE, static fn (): KomerceShippingDriver => resolve(KomerceShippingDriver::class));
    }

    /**
     * Webhook URLs and names (webhooks.komerce.*) are configured in the
     * Collaborator dashboard: keep them stable.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(__DIR__.'/routes/webhooks.php');
    }

    private function registerCommands(): void
    {
        $this->commands([
            KomerceCapabilitiesCommand::class,
            KomerceUat::class,
            SimulateKomercePayment::class,
        ]);
    }

    /**
     * Plan detection runs before the generic carrier sync (00:15), and only
     * while some Komerce product is configured.
     */
    private function registerSchedules(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('komerce:capabilities')
                ->dailyAt('00:05')
                ->when(static fn (): bool => Komerce::enabled());
        });
    }

    /**
     * Customer money is stuck on a cancelled order: route to critical so a
     * Slack/Sentry log channel at that level pages someone.
     */
    private function registerReportLevels(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'level')) {
                $handler->level(KomercePaidAfterCancellation::class, LogLevel::CRITICAL);
            }
        });
    }

    /**
     * The Komerce cpanel card. Registered on every scoped Shopper panel
     * (Octane-safe); the addon itself stays off while no Komerce product is
     * configured or when `shopper.addons.komerce` is false.
     */
    private function registerAddon(): void
    {
        $this->app->afterResolving('shopper', static function (ShopperPanel $panel): void {
            $addon = new KomerceAddon;

            if (! $panel->hasAddon($addon->getId())) {
                $panel->addon($addon);
            }
        });
    }

    /**
     * Komerce CDN carrier logos are consulted only after carrier metadata and
     * local files, and only while a Komerce shipping product is configured.
     * Komerce payment slugs (komerce-va-bca, komerce-qris) map to the local
     * payment logo files.
     */
    private function registerLogoSources(): void
    {
        $this->app->afterResolving(CarrierLogos::class, static function (CarrierLogos $logos): void {
            $logos->extend(static fn (string $slug): ?string => Komerce::shippingEnabled()
                ? KomerceCourierAssets::logoUrl($slug)
                : null);
        });

        $this->app->afterResolving(PaymentLogos::class, static function (PaymentLogos $logos): void {
            $logos->extend(static fn (PaymentMethod $method): ?string => $method->driver === KomerceDriver::CODE
                ? preg_replace('/^komerce-(va-)?/', '', strtolower((string) $method->slug))
                : null);
        });
    }
}
