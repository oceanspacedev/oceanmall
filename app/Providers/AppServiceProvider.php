<?php

declare(strict_types=1);

namespace App\Providers;

use App\Addons\Store\OceanMallStoreAddon;
use App\Http\Middleware\DispatchShopperForStaffOnly;
use App\Http\Middleware\EnsureStaffHasTwoFactor;
use App\Http\Responses\CpanelStaffLoginResponse;
use App\Listeners\CancelShipmentsOnOrderCancelled;
use App\Listeners\FulfillShipmentsOnOrderPaid;
use App\Listeners\RecordOrderPaidFinancials;
use App\Listeners\ReleaseDiscountOnOrderCancelled;
use App\Listeners\SendOrderPaidNotification;
use App\Livewire\Shopper\Pages\Dashboard as ShopperDashboardPage;
use App\Livewire\Shopper\Pages\OrderDetail;
use App\Livewire\Shopper\Pages\OrderIndex as ShopperOrderIndexPage;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Livewire\Shopper\Pages\Settings\Carriers as ShopperCarriersPage;
use App\Livewire\Shopper\Pages\Settings\General as ShopperGeneralSettingsPage;
use App\Livewire\Shopper\Pages\Settings\PaymentMethods as ShopperPaymentMethodsPage;
use App\Livewire\Shopper\Pages\Settings\TeamIndex as ShopperTeamIndexPage;
use App\Livewire\Shopper\Pages\Settings\TeamRoles as ShopperTeamRolesPage;
use App\Livewire\Shopper\SlideOvers\ReviewDetail;
use App\Models\OrderNote;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\OrderShipment;
use App\Models\PaymentDispute;
use App\Models\User;
use App\Observers\InventoryObserver;
use App\Observers\OrderShipmentObserver;
use App\Observers\ShopperOrderShippingObserver;
use App\Payment\Drivers\ConfirmFirstStripeDriver;
use App\Shipping\ShippingQuoteContext;
use App\Stock\AllocationPlanStockAllocator;
use App\Support\CarrierLogos;
use App\Support\CheckoutAllocationContext;
use App\Support\PaymentLogos;
use App\Support\ProviderQuotaRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Shopper\Cart\CartSessionManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Livewire\Livewire;
use Shopper\Contracts\LoginResponse as ShopperLoginResponse;
use Shopper\Core\Contracts\StockAllocator;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Events\Orders\OrderShipmentDelivered;
use Shopper\Core\Events\Orders\OrderShipped;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Http\Middleware\Dashboard as ShopperDashboardMiddleware;
use Shopper\Http\Middleware\DispatchShopper;
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
        $this->app->singleton(ProviderQuotaRegistry::class);
        $this->app->bind(StockAllocator::class, AllocationPlanStockAllocator::class);
        $this->app->bind(DispatchShopper::class, DispatchShopperForStaffOnly::class);

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
            config()->set(
                'shopper.components.review.components.slide-overs.review-detail',
                ReviewDetail::class,
            );
            config()->set(
                'shopper.components.setting.pages.team-index',
                ShopperTeamIndexPage::class,
            );
            config()->set(
                'shopper.components.setting.pages.team-roles',
                ShopperTeamRolesPage::class,
            );
            config()->set(
                'shopper.components.setting.pages.payment-methods',
                ShopperPaymentMethodsPage::class,
            );
            config()->set(
                'shopper.components.setting.pages.carriers',
                ShopperCarriersPage::class,
            );
            config()->set(
                'shopper.components.setting.pages.general',
                ShopperGeneralSettingsPage::class,
            );
            config()->set(
                'shopper.components.order.pages.order-index',
                ShopperOrderIndexPage::class,
            );
            config()->set(
                'shopper.components.dashboard.pages.dashboard',
                ShopperDashboardPage::class,
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
        OrderShipping::observe(ShopperOrderShippingObserver::class);

        Order::resolveRelationUsing('refunds', static fn (Order $order) => $order->hasMany(OrderRefund::class, 'order_id'));
        Order::resolveRelationUsing('returnRequests', static fn (Order $order) => $order->hasMany(OrderReturnRequest::class, 'order_id'));
        Order::resolveRelationUsing('paymentProofs', static fn (Order $order) => $order->hasMany(OrderPaymentProof::class, 'order_id'));
        Order::resolveRelationUsing('orderNotes', static fn (Order $order) => $order->hasMany(OrderNote::class, 'order_id'));
        Order::resolveRelationUsing('paymentDisputes', static fn (Order $order) => $order->hasMany(PaymentDispute::class, 'order_id'));

        $this->configureDefaults();
        $this->configureRateLimits();
        $this->configureAuthorization();
        $this->configureShopperLogos();
        $this->configureShopperStaffLogin();
        $this->configureOrderEvents();
        $this->configurePaymentDrivers();

        if (class_exists(Livewire::class)) {
            Livewire::addPersistentMiddleware([
                ShopperDashboardMiddleware::class,
                EnsureStaffHasTwoFactor::class,
            ]);
        }
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
        // Turning two-step verification on signs the mobile app out: a token
        // issued before it (possibly to someone else who knew the password)
        // must not outlive it. The owner signs in again with a code.
        Event::listen(TwoFactorAuthenticationConfirmed::class, static function (TwoFactorAuthenticationConfirmed $event): void {
            $event->user->tokens()->delete();
        });

        Event::listen(OrderPaid::class, RecordOrderPaidFinancials::class);
        Event::listen(OrderPaid::class, SendOrderPaidNotification::class);
        Event::listen(OrderPaid::class, FulfillShipmentsOnOrderPaid::class);
        Event::listen(OrderShipped::class, static function (OrderShipped $event): void {
            resolve(\App\Actions\Notify\NotifyOrderCustomer::class)->handle(
                $event->order,
                \App\Enums\OrderNotificationType::Shipped,
            );
        });
        Event::listen(OrderShipmentDelivered::class, static function (OrderShipmentDelivered $event): void {
            resolve(\App\Actions\Notify\NotifyOrderCustomer::class)->handle(
                $event->order,
                \App\Enums\OrderNotificationType::Delivered,
            );
        });
        Event::listen(OrderCancelled::class, CancelShipmentsOnOrderCancelled::class);
        Event::listen(OrderCancelled::class, ReleaseDiscountOnOrderCancelled::class);

        Event::listen(Login::class, static function (Login $event): void {
            if ($event->guard !== 'web' || ! $event->user instanceof User || ! request()->hasSession()) {
                return;
            }

            $cartKey = (string) config('shopper.cart.session.key', 'shopper_cart');
            $cartId = request()->session()->get($cartKey);

            if (! $cartId) {
                return;
            }

            $sessionCart = \Shopper\Cart\Models\Cart::query()->find($cartId);
            resolve(\App\Support\CustomerCart::class)->mergeSessionCart($event->user, $sessionCart);
        });
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
            'mobile-verify-resend' => [6, $byUserOrIp],
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
        // A tracking lookup spends the same provider quota as checkout quotes.
        // Web taps come through Inertia: answer them with the page's own
        // inline error instead of a bare 429 page.
        $tooManyTracks = static fn (Request $request, array $headers) => $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => 'Terlalu sering memperbarui pelacakan. Coba lagi nanti.'], 429, $headers)
            : back()->withErrors(['tracking' => 'Terlalu sering memperbarui pelacakan. Coba lagi nanti.']);

        RateLimiter::for('shipment-track', static fn (Request $request): array => [
            Limit::perMinute(6)->by('minute:'.$byUserOrIp($request))->response($tooManyTracks),
            Limit::perDay(40)->by('day:'.$byUserOrIp($request))->response($tooManyTracks),
        ]);
        // Coupon codes are guessable; each payment attempt may create a provider payment.
        RateLimiter::for('coupon', $perMinuteAndDay(5, 30));
        RateLimiter::for('checkout-payment', static fn (Request $request): Limit => Limit::perMinute(10)->by($byUserOrIp($request)));
    }

    protected function configureAuthorization(): void
    {
        Gate::define(
            'override-allocation',
            static fn (User $user, mixed $order = null): bool => $user->isAdmin()
                || self::hasPermission($user, 'orders.override_allocation'),
        );

        Gate::define(
            'manage-order-fulfillment',
            static fn (User $user, mixed $order = null): bool => $user->isAdmin()
                || self::hasPermission($user, 'orders.fulfill'),
        );

        Gate::define(
            'finance-dashboard',
            static fn (User $user): bool => $user->isAdmin()
                || self::hasPermission($user, 'finance.view_dashboard'),
        );

        Gate::define(
            'orders-mark-paid',
            static fn (User $user, mixed $order = null): bool => $user->isAdmin()
                || self::hasPermission($user, 'orders.mark_paid'),
        );

        Gate::define(
            'orders-cancel',
            static fn (User $user, mixed $order = null): bool => $user->isAdmin()
                || self::hasPermission($user, 'orders.cancel'),
        );

        Gate::define(
            'orders-refund',
            static fn (User $user, mixed $order = null): bool => $user->isAdmin()
                || self::hasPermission($user, 'orders.refund'),
        );

        Gate::define(
            'print-shipment-label',
            static fn (User $user, mixed $order = null): bool => $user->isAdmin()
                || self::hasPermission($user, 'orders.fulfill')
                || self::hasPermission($user, 'read_orders')
                || self::hasPermission($user, 'browse_orders'),
        );
    }

    private static function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (\Throwable) {
            return false;
        }
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
