<?php

declare(strict_types=1);

namespace App\Providers;

use App\Addons\Store\OceanMallStoreAddon;
use App\Http\Responses\CpanelStaffLoginResponse;
use App\Listeners\CancelShipmentsOnOrderCancelled;
use App\Listeners\FulfillShipmentsOnOrderPaid;
use App\Listeners\SendOrderPaidNotification;
use App\Livewire\Shopper\Pages\OrderDetail;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Models\OrderShipment;
use App\Models\User;
use App\Observers\InventoryObserver;
use App\Observers\OrderShipmentObserver;
use App\Payment\Drivers\ConfirmFirstStripeDriver;
use App\Shipping\ShippingQuoteContext;
use App\Stock\AllocationPlanStockAllocator;
use App\Support\CarrierLogos;
use App\Support\CheckoutAllocationContext;
use App\Support\PaymentLogos;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Shopper\Contracts\LoginResponse as ShopperLoginResponse;
use Shopper\Core\Contracts\StockAllocator;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Shopper\ShopperPanel;
use Shopper\Stripe\StripeDriver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CheckoutAllocationContext::class);
        $this->app->scoped(ShippingQuoteContext::class);
        $this->app->bind(StockAllocator::class, AllocationPlanStockAllocator::class);

        $this->registerShopperAddons();

        // Order *pages* are routed straight from config('shopper.components.order.pages.*')
        // and Shopper loads its routes before addons boot, so these overrides cannot live
        // in an addon. They name no provider: each action decides per order from the
        // order's own payment / shipping drivers and otherwise returns Shopper's own
        // action, so they are safe in every mode. Set single keys instead of publishing
        // config/shopper/components/order.php: Shopper merges that file shallowly, so a
        // published copy would hide components added by future Shopper releases.
        $this->app->booting(function (): void {
            config()->set(
                'shopper.components.order.pages.order-shipments',
                OrderShipments::class,
            );
            config()->set(
                'shopper.components.order.pages.order-detail',
                OrderDetail::class,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        OrderShipment::observe(OrderShipmentObserver::class);
        Inventory::observe(InventoryObserver::class);

        $this->configureDefaults();
        $this->configureRateLimits();
        $this->configureAuthorization();
        $this->configureShopperLogos();
        $this->configureShopperStaffLogin();
        $this->configureOrderEvents();
        $this->configurePaymentDrivers();
    }

    /**
     * Shopper's Stripe driver with the store's confirm-first checkout
     * contract (AppPaymentContractsConfirmsBeforeOrder). App providers boot
     * after package providers, so this replaces the shopper/stripe creator.
     */
    protected function configurePaymentDrivers(): void
    {
        if (! class_exists(StripeDriver::class)) {
            return;
        }

        Payment::extend('stripe', static function (): ConfirmFirstStripeDriver {
            $secret = (string) (config('shopper.stripe.secret_key') ?: config('shopper.payment.drivers.stripe.credentials.secret_key'));
            $publishable = (string) (config('shopper.stripe.publishable_key') ?: config('shopper.payment.drivers.stripe.credentials.publishable_key'));

            return new ConfirmFirstStripeDriver(
                new StripeDriver(
                    secretKey: $secret,
                    publishableKey: $publishable,
                    webhookSecret: (string) (config('shopper.stripe.webhook_secret') ?: config('shopper.payment.drivers.stripe.credentials.webhook_secret')),
                    captureMethod: (string) config('shopper.stripe.capture_method', 'manual'),
                ),
                $secret,
                $publishable,
            );
        });
    }

    /**
     * Shopper order events drive the post-payment flow for every payment and
     * shipping driver (and Shopper's own cpanel "mark paid").
     */
    protected function configureOrderEvents(): void
    {
        Event::listen(OrderPaid::class, SendOrderPaidNotification::class);
        Event::listen(OrderPaid::class, FulfillShipmentsOnOrderPaid::class);
        Event::listen(OrderCancelled::class, CancelShipmentsOnOrderCancelled::class);
    }

    /**
     * Register the generic store addon on every scoped Shopper instance
     * (Octane-safe). Shopper boots addons immediately after resolving the
     * panel, so this must run via afterResolving rather than boot().
     * Provider modules register their own addon the same way.
     */
    protected function registerShopperAddons(): void
    {
        $this->app->afterResolving('shopper', static function (ShopperPanel $panel): void {
            $addon = new OceanMallStoreAddon;

            if (! $panel->hasAddon($addon->getId())) {
                $panel->addon($addon);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Customer reset links always point at APP_URL, never at the request Host.
        ResetPassword::createUrlUsing(static fn (object $notifiable, string $token): string => rtrim((string) config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));

        Password::defaults(
            fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Mobile API limiters. Each one is named so routes never share a counter:
     * unnamed `throttle:N,1` keys only by user/IP, so a burst of searches used
     * to lock the same device out of login and password reset.
     */
    protected function configureRateLimits(): void
    {
        $byUserOrIp = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
        $byIp = static fn (Request $request): string => (string) $request->ip();

        $limits = [
            'mobile-register' => [10, $byIp],
            'mobile-login' => [10, $byIp],
            'mobile-password-reset' => [5, $byIp],
            'mobile-search' => [60, $byIp],
            'mobile-cart' => [60, $byUserOrIp],
            'mobile-password-change' => [6, $byUserOrIp],
            'mobile-account-delete' => [5, $byUserOrIp],
            'mobile-place-order' => [20, $byUserOrIp],
            'mobile-sync-payment' => [12, $byUserOrIp],
            'mobile-review' => [10, $byUserOrIp],
            'mobile-review-report' => [10, $byUserOrIp],
        ];

        foreach ($limits as $name => [$perMinute, $key]) {
            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute($perMinute)->by($key($request)));
        }

        // Shared by web and API so an account has one budget across both.
        // Quotes and destination lookups may spend an account-wide provider
        // quota (e.g. a daily rate-API budget): the daily cap keeps one
        // customer (or script) from breaking checkout for everyone else.
        $perMinuteAndDay = static fn (int $perMinute, int $perDay): Closure => static fn (Request $request): array => [
            Limit::perMinute($perMinute)->by('minute:'.$byUserOrIp($request)),
            Limit::perDay($perDay)->by('day:'.$byUserOrIp($request)),
        ];

        RateLimiter::for('checkout-quote', $perMinuteAndDay(10, 40));
        RateLimiter::for('checkout-destinations', $perMinuteAndDay(30, 300));
        // Coupon codes are guessable; each payment attempt may create a provider payment.
        RateLimiter::for('coupon', $perMinuteAndDay(5, 30));
        RateLimiter::for('checkout-payment', static fn (Request $request): Limit => Limit::perMinute(10)->by($byUserOrIp($request)));
    }

    protected function configureAuthorization(): void
    {
        Gate::define(
            'override-allocation',
            static fn (User $user, ?Order $order = null): bool => $user->isAdmin() || $user->isManager() || $user->hasPermissionTo('browse_orders'),
        );

        Gate::define(
            'print-shipment-label',
            static fn (User $user, ?Order $order = null): bool => $user->isAdmin() || $user->isManager() || $user->hasPermissionTo('browse_orders'),
        );
    }

    protected function configureShopperStaffLogin(): void
    {
        $this->app->bind(ShopperLoginResponse::class, CpanelStaffLoginResponse::class);
    }

    /**
     * Logo lookups are extendable: provider modules add their own sources
     * with CarrierLogos::extend() / PaymentLogos::extend() from their service
     * provider, so the core resolvers only know metadata and local files.
     */
    protected function configureShopperLogos(): void
    {
        $this->app->singleton(CarrierLogos::class);
        $this->app->singleton(PaymentLogos::class);

        $this->app->bind('shopper.carrier.logo', static fn (): Closure => static fn (Carrier $carrier): ?string => resolve(CarrierLogos::class)->forCarrier($carrier));
        $this->app->bind('shopper.payment.logo', static fn (): Closure => static fn (PaymentMethod $method): ?string => resolve(PaymentLogos::class)->forMethod($method));
    }
}
