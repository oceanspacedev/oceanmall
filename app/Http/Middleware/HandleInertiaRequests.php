<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\GetCountriesByZone;
use App\Actions\ZoneSessionManager;
use App\Models\Channel;
use App\Payment\PaymentMethodCatalog;
use App\Shipping\Contracts\PresentsCarriers;
use App\Shipping\ShippingCapabilities;
use App\Support\EnabledCarriers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use InvalidArgumentException;
use Shopper\Cart\CartSessionManager;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Category;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Services\PaymentProcessingService;
use Shopper\Shipping\Facades\Shipping;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->append('full_name'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'shop' => fn (): array => $this->shopProps(),
            'notificationsUnreadCount' => fn (): int => $request->user()
                ? $request->user()->unreadNotifications()->count()
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shopProps(): array
    {
        $cart = resolve(CartSessionManager::class)->current();
        $zone = ZoneSessionManager::ensureSession();

        return [
            'cart_count' => $cart?->lines->sum('quantity') ?? 0,
            'zone' => $zone ? [
                'country_code' => $zone->countryCode,
                'country_name' => $zone->countryName,
                'currency_code' => $zone->currencyCode,
                'zone_id' => $zone->zoneId,
            ] : null,
            'currency' => current_currency(),
            'channels' => Channel::query()
                ->scopes('enabled')
                ->select('id', 'name', 'slug')
                ->get()
                ->toArray(),
            'available_zones' => fn (): array => resolve(GetCountriesByZone::class)->handle()->values()->toArray(),
            'tax_label' => current_tax_label(),
            'nav_categories' => $this->topCategories(4, 'nav'),
            'footer_categories' => $this->topCategories(6, 'footer'),
            'payment_methods' => $this->footerPaymentMethods(),
            'shipping_couriers' => $this->footerShippingCouriers(),
        ];
    }

    /**
     * Payment logos shown in the storefront footer (enabled cpanel payment methods).
     *
     * @return array<int, array{key: string, title: string, logo: string|null, driver: string}>
     */
    private function footerPaymentMethods(): array
    {
        $catalog = new PaymentMethodCatalog;

        return Cache::remember(
            'footer.payment_methods.v6.'.$this->commerceCatalogSignature($catalog->signature(), $this->quotingCarrierDrivers()),
            600,
            function () use ($catalog): array {
                $service = resolve(PaymentProcessingService::class);

                return PaymentMethod::query()
                    ->where('is_enabled', true)
                    ->orderBy('id')
                    ->get()
                    // Same availability rule as checkout, for every driver.
                    ->filter(fn (PaymentMethod $method): bool => $catalog->available($method))
                    ->map(function (PaymentMethod $method) use ($service, $catalog): array {
                        try {
                            $logo = $service->getLogoUrl($method);
                        } catch (InvalidArgumentException) {
                            $logo = null;
                        }

                        // A provider catalog entry carries the official name and logo.
                        $entry = $catalog->entry($method);

                        return [
                            'key' => (string) $method->slug,
                            'title' => $entry['display_name'] ?? (string) $method->title,
                            'logo' => $entry['logo_url'] ?? $logo,
                            'driver' => (string) ($method->driver ?? 'manual'),
                        ];
                    })
                    ->values()
                    ->all();
            },
        );
    }

    /**
     * Couriers advertised in the footer: the enabled carriers of the drivers
     * that quote checkout rates (each driver may relabel or hide its own
     * carriers, PresentsCarriers), or the store's manual carriers when no
     * provider quotes.
     *
     * @return array<int, array{code: string, label: string, logo: string|null}>
     */
    private function footerShippingCouriers(): array
    {
        $owners = $this->quotingCarrierDrivers();
        /** @var \Closure(Carrier): ?string $logoResolver */
        $logoResolver = app('shopper.carrier.logo');

        return Cache::remember(
            'footer.shipping_couriers.v5.'.$this->commerceCatalogSignature(
                (new PaymentMethodCatalog)->signature(),
                $owners,
            ),
            600,
            function () use ($owners, $logoResolver): array {
                // Checkout offers provider quotes when a provider quotes, and the
                // store's own (manual) carriers otherwise: advertise the same set.
                $shown = $owners !== [] ? $owners : ['manual'];
                $rows = [];

                foreach (EnabledCarriers::forDisplay() as $carrier) {
                    $driverCode = filled($carrier->driver) ? (string) $carrier->driver : 'manual';

                    if (! in_array($driverCode, $shown, true)) {
                        continue;
                    }

                    $row = ['code' => strtolower((string) $carrier->slug), 'label' => (string) $carrier->name];
                    $driver = $driverCode !== 'manual' ? $this->shippingDriver($driverCode) : null;

                    if ($driver instanceof PresentsCarriers) {
                        $row = $driver->presentCarrier($carrier);
                    }

                    if ($row !== null) {
                        $rows[] = [...$row, 'logo' => $logoResolver($carrier)];
                    }
                }

                return $rows;
            },
        );
    }

    /**
     * Carrier-owning drivers whose carriers checkout quotes right now.
     *
     * @return list<string>
     */
    private function quotingCarrierDrivers(): array
    {
        return array_keys(resolve(ShippingCapabilities::class)->quoteChains());
    }

    private function shippingDriver(string $code): ?object
    {
        try {
            return Shipping::driver($code);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $quotingDrivers
     */
    private function commerceCatalogSignature(string $readyPaymentDrivers, array $quotingDrivers): string
    {
        $paymentIds = PaymentMethod::query()
            ->where('is_enabled', true)
            ->orderBy('id')
            ->pluck('id')
            ->implode(',');

        $carrierSlugs = EnabledCarriers::forDisplay()
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => strtolower((string) $slug))
            ->implode(',');

        return $readyPaymentDrivers
            .'.'.implode('+', $quotingDrivers)
            .'.'.$paymentIds
            .'.'.$carrierSlugs;
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    private function topCategories(int $limit, string $cacheKey): array
    {
        return Cache::remember(
            "{$cacheKey}.categories.".app()->getLocale().".{$limit}",
            7200,
            fn (): array => Category::query()
                ->scopes('enabled')
                ->whereNull('parent_id')
                ->orderBy('position')
                ->take($limit)
                ->get(['id', 'name', 'slug'])
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])
                ->all(),
        );
    }
}
