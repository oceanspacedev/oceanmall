<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans;

use App\Integrations\Midtrans\Database\MidtransPaymentMethodSeeder;
use App\Integrations\Midtrans\Exceptions\MidtransPaymentNeedsReview;
use App\Integrations\Midtrans\Payment\MidtransDriver;
use App\Support\PaymentLogos;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LogLevel;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;

/**
 * The Midtrans module: Snap hosted checkout (QRIS, GoPay, ShopeePay, bank
 * virtual accounts, Mandiri bill, cards) with Core API status / expire,
 * wired into the store only through Shopper's extension points
 * (Payment::extend, PaymentMethodSeeder::extend, PaymentLogos::extend) and
 * the store's payment contracts (app/Payment/Contracts).
 *
 * Plug and play: the module is always registered but takes part only while
 * MIDTRANS_SERVER_KEY is set with a consistent host (MidtransDriver::
 * isConfigured()) and PAYMENT_MIDTRANS_ENABLED is on. Without a key its
 * methods are not offered, no schedule has work for it, its webhook answers
 * 200 {status: disabled} and no HTTP call leaves the store. Expiry of unpaid
 * orders runs through the generic orders:expire-unpaid schedule
 * (ExpiresPayments), so the module registers no schedule of its own.
 *
 * Removing this provider from bootstrap/providers.php (and the folder)
 * removes Midtrans entirely; generic code never references it.
 */
final class MidtransServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The single source of Midtrans credentials and options. A published
        // config/midtrans.php (php artisan vendor:publish --tag=midtrans-config)
        // overrides these defaults key by key.
        $this->mergeConfigFrom(__DIR__.'/config/midtrans.php', 'midtrans');

        $this->registerLogoSource();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/midtrans.php' => config_path('midtrans.php'),
        ], 'midtrans-config');

        Payment::extend(MidtransDriver::CODE, static fn (): MidtransDriver => resolve(MidtransDriver::class));

        $this->registerRoutes();
        $this->registerReportLevels();

        if ($this->app->runningInConsole()) {
            PaymentMethodSeeder::extend(MidtransPaymentMethodSeeder::class);
        }
    }

    /**
     * The notification URL (webhooks.midtrans.notification) is entered in
     * the Midtrans dashboard: keep it stable.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(__DIR__.'/routes/webhooks.php');
    }

    /**
     * Customer money that cannot be applied (cancelled order, second paid
     * attempt): route to critical so an alerting log channel pages someone.
     */
    private function registerReportLevels(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'level')) {
                $handler->level(MidtransPaymentNeedsReview::class, LogLevel::CRITICAL);
            }
        });
    }

    /**
     * Midtrans channel codes map onto the store's local payment logo files
     * (bca_va -> bca.svg, echannel -> mandiri.svg, other_qris -> qris.svg).
     * Channels without a local file (gopay, shopeepay, other_va, cards) get
     * none; staff can set metadata.logo_url in cpanel.
     */
    private function registerLogoSource(): void
    {
        $this->app->afterResolving(PaymentLogos::class, static function (PaymentLogos $logos): void {
            $logos->extend(static function (PaymentMethod $method): ?string {
                if ($method->driver !== MidtransDriver::CODE) {
                    return null;
                }

                $metadata = $method->metadata;
                $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
                $channel = strtolower((string) data_get($metadata, 'channel_code', ''));

                return match ($channel) {
                    'bca_va' => 'bca',
                    'bni_va' => 'bni',
                    'bri_va' => 'bri',
                    'echannel' => 'mandiri',
                    'permata_va' => 'permata',
                    'other_qris', 'qris' => 'qris',
                    default => null,
                };
            });
        });
    }
}
