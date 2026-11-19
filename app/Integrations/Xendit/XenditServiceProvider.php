<?php

declare(strict_types=1);

namespace App\Integrations\Xendit;

use App\Integrations\Xendit\Console\XenditStatusCommand;
use App\Integrations\Xendit\Database\XenditPaymentMethodSeeder;
use App\Integrations\Xendit\Exceptions\XenditPaidAfterCancellation;
use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\Support\XenditChannels;
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
 * The Xendit module: Xendit Invoice (hosted checkout) as the Shopper payment
 * driver `xendit`, wired only through Shopper's extension points
 * (Payment::extend, PaymentMethodSeeder::extend, PaymentLogos::extend) and
 * the store's payment contracts (app/Payment/Contracts).
 *
 * Plug and play: the module is always registered, but it takes part only
 * once XENDIT_SECRET_KEY is set (and allowed in this environment, see
 * Xendit::refusal()) and PAYMENT_XENDIT_ENABLED is on. Without a key no
 * method is offered, no request is sent, the webhook answers
 * `{status: disabled}`, and generic schedules (orders:expire-unpaid) have
 * nothing of Xendit's to do. Removing this provider line (and the folder)
 * leaves the store as it was.
 */
final class XenditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The single source of Xendit credentials and options. A published
        // config/xendit.php (vendor:publish --tag=xendit-config) overrides
        // these defaults key by key.
        $this->mergeConfigFrom(__DIR__.'/config/xendit.php', 'xendit');

        $this->registerLogoSource();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/xendit.php' => config_path('xendit.php'),
        ], 'xendit-config');

        Payment::extend(XenditDriver::CODE, static fn (): XenditDriver => resolve(XenditDriver::class));

        $this->registerRoutes();
        $this->registerReportLevels();

        if ($this->app->runningInConsole()) {
            $this->commands([XenditStatusCommand::class]);

            PaymentMethodSeeder::extend(XenditPaymentMethodSeeder::class);
        }
    }

    /**
     * Webhook URL and name (webhooks.xendit.invoice) are configured in the
     * Xendit Dashboard: keep them stable.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(__DIR__.'/routes/webhooks.php');
    }

    /**
     * Customer money is stuck on a cancelled order: route to critical so a
     * Slack/Sentry log channel at that level pages someone.
     */
    private function registerReportLevels(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'level')) {
                $handler->level(XenditPaidAfterCancellation::class, LogLevel::CRITICAL);
            }
        });
    }

    /**
     * Xendit method slugs (xendit-va-bca, xendit-qris...) map to the local
     * payment logo files (bca, qris...) when metadata.channel_code has none.
     */
    private function registerLogoSource(): void
    {
        $this->app->afterResolving(PaymentLogos::class, static function (PaymentLogos $logos): void {
            $logos->extend(static fn (PaymentMethod $method): ?string => $method->driver === XenditDriver::CODE
                ? XenditChannels::logoKey((string) $method->slug)
                : null);
        });
    }
}
