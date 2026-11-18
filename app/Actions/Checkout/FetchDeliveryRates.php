<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Shipping\ShippingCapabilities;
use App\Shipping\ShippingQuoteContext;
use App\Shipping\ShippingQuoteFailure;
use App\Support\CarrierLogos;
use App\Support\CourierServiceExplainer;
use App\Support\EnabledCarriers;
use App\Support\OperatorSwitch;
use Illuminate\Support\Collection;
use Shopper\Cart\CartSessionManager;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Zone;
use Shopper\Shipping\Contracts\ShippingDriver;
use Shopper\Shipping\DataTransferObjects\Address as ShippingAddress;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\DataTransferObjects\ShippingRate;
use Shopper\Shipping\Facades\Shipping;
use Shopper\Shipping\Services\CarrierRateService;
use Throwable;

/**
 * Checkout delivery options for one parcel, provider-neutral:
 *
 * 1. Every configured real-time Shopper shipping driver quotes the carriers it
 *    owns (carriers.driver), one call per driver, through
 *    ShippingDriver::calculateRates(). Inputs Shopper's Address DTO cannot
 *    carry (destination id, pin point, warehouse, item value) travel in the
 *    neutral ShippingQuoteContext. Which driver answers first for a set of
 *    carriers is the drivers' own configuration (DelegatesQuotes), see
 *    ShippingCapabilities::quoteChains().
 * 2. With no provider configured, Shopper's own zone options are offered:
 *    manual carriers and carriers whose driver is not configured, exactly as
 *    Shopper's CarrierRateService prices them.
 * 3. When a configured provider returns nothing (half-configured, out of
 *    quota, failing, no service for the route) the result is empty and
 *    checkout explains why (ShippingQuoteContext::lastFailure()), as before
 *    provider drivers existed; the store setting
 *    shopper.shipping.fallback_to_zone_options offers the zone options then.
 *
 * By default provider quotes and zone options are not mixed: zone options
 * are the keyless store's rates (the seeded "Reguler" flat rate) and would
 * undercut real courier quotes. A store that wants e.g. "Ambil di toko" next
 * to provider couriers opts in with shopper.shipping.combine_zone_options
 * (SHIPPING_COMBINE_ZONE_OPTIONS): the zone options of carriers no
 * configured provider owns are appended to the provider quotes, and are
 * still offered when the provider answers nothing.
 */
final readonly class FetchDeliveryRates
{
    public function __construct(
        private ShippingCapabilities $capabilities,
        private ShippingQuoteContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<int, Package>  $packages
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $shippingAddress, array $packages, ?int $originInventoryId = null, ?int $itemValue = null): array
    {
        $countryId = $shippingAddress['country_id'] ?? null;
        $zone = $countryId ? resolve(ResolveZoneForCountry::class)->handle($countryId) : null;
        $from = $this->buildOriginAddress();
        $to = $this->buildDestinationAddress($shippingAddress);

        $chains = $this->capabilities->quoteChains();
        $providerQuotes = $chains !== [];
        $providerRates = $providerQuotes
            ? $this->providerRates($chains, $shippingAddress, $packages, $originInventoryId, $itemValue, $zone, $from, $to)
            : [];

        if ($providerRates !== []) {
            return self::combineZoneOptions() && $zone instanceof Zone
                ? [...$providerRates, ...$this->zoneOptions($zone, $from, $to, $packages)]
                : $providerRates;
        }

        // A configured provider that answered nothing: no courier (checkout
        // explains why) unless the store opted into its zone options, as a
        // fallback or next to the couriers (then "Ambil di toko" must not
        // vanish exactly when the couriers fail).
        if ($providerQuotes && ! self::fallbackToZoneOptions() && ! self::combineZoneOptions()) {
            return [];
        }

        return $zone instanceof Zone ? $this->zoneOptions($zone, $from, $to, $packages) : [];
    }

    /**
     * Store setting (config shopper.shipping.fallback_to_zone_options): offer
     * Shopper's zone options when a configured provider returns nothing.
     */
    public static function fallbackToZoneOptions(): bool
    {
        return filter_var(config('shopper.shipping.fallback_to_zone_options', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Store setting (config shopper.shipping.combine_zone_options): offer
     * Shopper's zone options (manual carriers, e.g. "Ambil di toko") NEXT TO
     * the provider quotes. Carriers a configured provider owns are never
     * priced from zone options, so courier quotes are not duplicated.
     */
    public static function combineZoneOptions(): bool
    {
        return filter_var(config('shopper.shipping.combine_zone_options', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, list<ShippingDriver>>  $chains
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<int, Package>  $packages
     * @return array<int, array<string, mixed>>
     */
    private function providerRates(
        array $chains,
        array $shippingAddress,
        array $packages,
        ?int $originInventoryId,
        ?int $itemValue,
        ?Zone $zone,
        ShippingAddress $from,
        ShippingAddress $to,
    ): array {
        $origin = $originInventoryId !== null
            ? Inventory::query()->find($originInventoryId)
            : (Inventory::query()->where('is_default', true)->first() ?? Inventory::query()->first());

        $this->context->begin($shippingAddress, $origin, $itemValue ?? $this->cartItemValue());
        $rows = [];

        try {
            foreach ($chains as $owner => $drivers) {
                [$couriers, $excluded] = $this->courierCodes($owner, $zone);

                foreach ($drivers as $driver) {
                    $this->context->forCouriers($couriers, $excluded);

                    try {
                        $rates = $driver->calculateRates($from, $to, $packages);
                    } catch (Throwable $e) {
                        report($e);
                        $this->context->markFailure($driver->code(), ShippingQuoteFailure::UNAVAILABLE, $e->getMessage());

                        continue;
                    }

                    $formatted = $this->formatProviderRates($rates, $driver, $owner);

                    if ($formatted !== []) {
                        array_push($rows, ...$formatted);

                        break;
                    }
                }
            }
        } finally {
            $this->context->end();
        }

        return $rows;
    }

    /**
     * Courier codes of the enabled carriers a driver owns (narrowed to the
     * zone when it has a zone), and of the ones staff switched off.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function courierCodes(string $owner, ?Zone $zone): array
    {
        $carriers = Carrier::query()->where('driver', $owner)->orderBy('id')->get();
        $zoneCarrierIds = $zone instanceof Zone ? $zone->carriers()->allRelatedIds()->all() : null;

        $enabled = $carriers
            ->filter(static fn (Carrier $carrier): bool => (bool) $carrier->is_enabled
                && ($zoneCarrierIds === null || in_array($carrier->id, $zoneCarrierIds, false)))
            ->map(static fn (Carrier $carrier): string => EnabledCarriers::courierCode($carrier))
            ->values()
            ->all();

        $disabled = $carriers
            ->reject(static fn (Carrier $carrier): bool => (bool) $carrier->is_enabled)
            ->map(static fn (Carrier $carrier): string => EnabledCarriers::courierCode($carrier))
            ->values()
            ->all();

        return [$enabled, $disabled];
    }

    /**
     * @param  Collection<int, ShippingRate>|iterable<mixed>  $rates
     * @return array<int, array<string, mixed>>
     */
    private function formatProviderRates(iterable $rates, ShippingDriver $driver, string $owner): array
    {
        // Only the chain owner's carriers: another driver may own the same
        // courier code (a namespaced 'rajaongkir-jne' next to 'jne').
        $carriers = Carrier::query()->where('driver', $owner)->orderBy('id')->get();
        $logos = resolve(CarrierLogos::class);
        $formatted = [];

        foreach ($rates as $rate) {
            if (! $rate instanceof ShippingRate) {
                continue;
            }

            $carrierCode = strtolower(trim($rate->carrierCode));

            // Couriers switched off in cpanel stay hidden even when the provider offers them.
            if ($this->context->isExcluded($carrierCode)) {
                continue;
            }

            $details = $this->context->details($driver->code(), $rate->serviceCode);
            $lookup = strtolower(trim((string) ($details['carrier_slug'] ?? $carrierCode)));
            $carrier = $carriers->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $lookup)
                ?? $carriers->first(static fn (Carrier $carrier): bool => strtolower((string) $carrier->slug) === $lookup);

            $formatted[] = [
                'service_code' => $rate->serviceCode,
                'service_name' => $rate->serviceName,
                'amount' => $rate->amount,
                'currency' => $rate->currency,
                'carrier_code' => $carrierCode,
                'estimated_days' => $rate->estimatedDays,
                'description' => CourierServiceExplainer::explain($carrierCode, $rate->serviceName),
                'carrier_name' => $carrier?->name ?? $details['carrier_name'] ?? $rate->carrierCode,
                'carrier_logo' => $carrier instanceof Carrier
                    ? $logos->forCarrier($carrier)
                    : ($details['carrier_logo'] ?? $logos->forCode($lookup)),
                // Shopper shipping driver that quoted the rate (order_shipments.driver).
                'driver' => $driver->code(),
                // Shopper carrier of the rate (order_shipments.carrier_id).
                'carrier_id' => $carrier?->id,
                ...array_diff_key($details, array_flip(['carrier_slug', 'carrier_name', 'carrier_logo'])),
            ];
        }

        return $formatted;
    }

    /**
     * Shopper's own zone options (CarrierRateService manual rates) for manual
     * carriers and carriers whose provider driver is not configured.
     *
     * @param  array<int, Package>  $packages
     * @return array<int, array<string, mixed>>
     */
    private function zoneOptions(Zone $zone, ShippingAddress $from, ShippingAddress $to, array $packages): array
    {
        $service = resolve(CarrierRateService::class);

        $carriers = $zone->carriers()
            ->where('is_enabled', true)
            ->get()
            // A carrier whose driver is not registered (its provider module
            // was removed) has no Shopper driver to price it: skip it quietly
            // instead of letting Shipping::driver() throw on every quote.
            ->filter(static fn (Carrier $carrier): bool => self::driverRegistered($carrier))
            ->reject(fn (Carrier $carrier): bool => $this->driverConfigured($service, $carrier));

        $options = $zone->shippingOptions()
            ->where('is_enabled', true)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($carriers as $carrier) {
            try {
                $rates = $service->getRatesForCarrier($carrier, $from, $to, $packages, $zone);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($rates as $rate) {
                $option = is_int($rate->serviceCode) ? $options->get($rate->serviceCode) : null;

                $rows[] = [
                    'service_code' => $rate->serviceCode,
                    'service_name' => $rate->serviceName,
                    'amount' => $rate->amount,
                    'currency' => $rate->currency,
                    'carrier_code' => $rate->carrierCode,
                    'estimated_days' => $rate->estimatedDays,
                    'description' => $option?->description,
                    'carrier_name' => $carrier->name ?? $rate->carrierCode,
                    'carrier_logo' => $service->getLogoUrl($carrier),
                    'driver' => 'manual',
                    'carrier_id' => $carrier->id,
                ];
            }
        }

        return $rows;
    }

    private static function driverRegistered(Carrier $carrier): bool
    {
        $driver = trim((string) $carrier->driver);

        return $driver === '' || in_array($driver, Shipping::availableDrivers(), true);
    }

    /**
     * A carrier whose provider driver is configured is quoted by that driver
     * (above), never priced from its zone options. The operator switch
     * (shopper.shipping.drivers.{code}.enabled = false) also keeps it out:
     * Shopper's CarrierRateService would call the provider API for it.
     */
    private function driverConfigured(CarrierRateService $service, Carrier $carrier): bool
    {
        if (filled($carrier->driver) && $carrier->driver !== 'manual'
            && OperatorSwitch::state(OperatorSwitch::SHIPPING, (string) $carrier->driver) === false) {
            return true;
        }

        try {
            return $service->isDriverConfigured($carrier);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Item value of the session cart, for callers that did not pass one.
     * Read-only: never create a cart here — on the stateless API
     * cartSession() would mint a fresh empty cart and hide the real one.
     */
    private function cartItemValue(): int
    {
        try {
            if (! request()->hasSession()) {
                return 0;
            }

            $cart = resolve(CartSessionManager::class)->current();

            if ($cart === null) {
                return 0;
            }

            $total = 0;

            foreach ($cart->lines as $line) {
                $total += (int) $line->unit_price_amount * max(1, (int) $line->quantity);
            }

            return $total;
        } catch (Throwable) {
            return 0;
        }
    }

    private function buildOriginAddress(): ShippingAddress
    {
        return once(function (): ShippingAddress {
            $countryId = shopper_setting('country_id');
            $country = $countryId ? Country::query()->find($countryId) : null;

            return new ShippingAddress(
                firstName: shopper_setting('name') ?? '',
                lastName: '',
                street: shopper_setting('street_address') ?? '',
                city: shopper_setting('city') ?? '',
                postalCode: shopper_setting('postal_code') ?? '',
                state: shopper_setting('state') ?? '',
                country: $country?->cca2 ?? '',
                phone: shopper_setting('phone_number'),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $shippingAddress
     */
    private function buildDestinationAddress(array $shippingAddress): ShippingAddress
    {
        $country = Country::query()->find($shippingAddress['country_id'] ?? null);

        return new ShippingAddress(
            firstName: $shippingAddress['first_name'] ?? '',
            lastName: $shippingAddress['last_name'] ?? '',
            street: $shippingAddress['street_address'] ?? '',
            city: $shippingAddress['city'] ?? '',
            postalCode: $shippingAddress['postal_code'] ?? '',
            state: $shippingAddress['state'] ?? '',
            country: $country?->cca2 ?? '',
            street2: $shippingAddress['street_address_plus'] ?? null,
            phone: $shippingAddress['phone_number'] ?? null,
            email: auth()->user()?->email,
        );
    }
}
