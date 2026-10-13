<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\GetCountriesByZone;
use App\Actions\ZoneSessionManager;
use App\Models\Channel;
use App\Support\EnabledCarriers;
use App\Support\KomercePaymentCatalog;
use App\Support\RajaOngkirCouriers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Shopper\Cart\CartSessionManager;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Category;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Services\PaymentProcessingService;

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
        $stripeEnabled = (bool) config('shopper.payment.drivers.stripe.enabled', false);
        $komerceEnabled = komerce_payment_enabled() || qrisly_enabled();

        return Cache::remember(
            'footer.payment_methods.v5.'.$this->commerceCatalogSignature($stripeEnabled, $komerceEnabled),
            600,
            function () use ($stripeEnabled, $komerceEnabled): array {
                $service = resolve(PaymentProcessingService::class);
                $catalog = resolve(KomercePaymentCatalog::class);
                // Official Komerce catalog (null = provider unreachable → local fallback).
                $official = $komerceEnabled ? $catalog->official() : null;

                return PaymentMethod::query()
                    ->where('is_enabled', true)
                    ->orderBy('id')
                    ->get()
                    ->filter(fn (PaymentMethod $method): bool => $this->paymentMethodAvailable($method, $stripeEnabled, $komerceEnabled))
                    ->map(function (PaymentMethod $method) use ($service, $catalog, $official): ?array {
                        try {
                            $logo = $service->getLogoUrl($method);
                        } catch (\InvalidArgumentException) {
                            $logo = null;
                        }

                        $title = (string) $method->title;
                        $driver = (string) ($method->driver ?? 'manual');

                        if ($driver === 'komerce' && $official !== null) {
                            $row = $catalog->match(KomercePaymentCatalog::decodeMeta($method->metadata), $official);

                            // Not in the provider catalog → never offered at checkout, so not advertised.
                            if ($row === null) {
                                return null;
                            }

                            $title = $catalog->displayName($row) ?? $title;
                            $logo = $catalog->logoUrl($row) ?? $logo;
                        }

                        return [
                            'key' => (string) $method->slug,
                            'title' => $title,
                            'logo' => $logo,
                            'driver' => $driver,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            },
        );
    }

    private function paymentMethodAvailable(PaymentMethod $method, bool $stripeEnabled, bool $komerceEnabled): bool
    {
        return match ($method->driver ?? 'manual') {
            'stripe' => $stripeEnabled,
            'komerce' => $komerceEnabled,
            default => Payment::isConfigured($method->driver ?? 'manual'),
        };
    }

    /**
     * Couriers advertised in the footer. With Komerce/RajaOngkir enabled this is
     * exactly the set checkout quotes (enabled carriers ∩ RAJAONGKIR_COURIERS ∩
     * the official RajaOngkir courier list), labelled with the official names.
     * Without it, only the manual store courier is shown.
     *
     * @return array<int, array{code: string, label: string, logo: string|null}>
     */
    private function footerShippingCouriers(): array
    {
        $komerceEnabled = komerce_shipping_cost_enabled();
        /** @var \Closure(Carrier): ?string $logoResolver */
        $logoResolver = app('shopper.carrier.logo');

        return Cache::remember(
            'footer.shipping_couriers.v3.'.$this->commerceCatalogSignature(
                (bool) config('shopper.payment.drivers.stripe.enabled', false),
                $komerceEnabled,
            ),
            600,
            function () use ($komerceEnabled, $logoResolver): array {
                $carriers = EnabledCarriers::forDisplay()->keyBy(
                    static fn (Carrier $carrier): string => strtolower((string) $carrier->slug),
                );

                if (! $komerceEnabled) {
                    return $carriers
                        ->filter(static fn (Carrier $carrier): bool => ($carrier->driver ?? 'manual') === 'manual')
                        ->map(static fn (Carrier $carrier): array => [
                            'code' => strtolower((string) $carrier->slug),
                            'label' => (string) $carrier->name,
                            'logo' => $logoResolver($carrier),
                        ])
                        ->values()
                        ->all();
                }

                $rows = [];

                foreach (RajaOngkirCouriers::quotable() as $code) {
                    $carrier = $carriers->get($code);

                    if (! $carrier instanceof Carrier) {
                        continue;
                    }

                    $rows[] = [
                        'code' => $code,
                        'label' => RajaOngkirCouriers::name($code) ?? (string) $carrier->name,
                        'logo' => $logoResolver($carrier),
                    ];
                }

                return $rows;
            },
        );
    }

    private function commerceCatalogSignature(bool $stripeEnabled, bool $komerceEnabled): string
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

        return ($stripeEnabled ? '1' : '0')
            .'.'.($komerceEnabled ? '1' : '0')
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
