<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Models\OrderShipment;
use App\Shipping\Contracts\DelegatesFulfillment;
use App\Shipping\Contracts\DelegatesQuotes;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\RequiresCoordinates;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\RequiresOriginId;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\Contracts\ValidatesOriginIds;
use App\Support\OperatorSwitch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Shopper\Core\Models\Order;
use Shopper\Shipping\Contracts\ShippingDriver;
use Shopper\Shipping\Facades\Shipping;
use Throwable;

/**
 * The one place generic code (controllers, Inertia props, API, allocation)
 * asks "what can shipping do right now?". Answers come from the Shopper
 * shipping drivers registered with Shipping::extend() that report
 * isConfigured(), checked against the capability contracts in
 * App\Shipping\Contracts. With no provider configured every answer is
 * "no" and checkout runs on Shopper's own carriers and zone options.
 *
 * A driver takes part when the operator switch
 * `shopper.shipping.drivers.{code}.enabled` is on (Shopper's convention, as
 * in ShippingManager::configuredDrivers(): no entry means off; values are
 * parsed by App\Support\OperatorSwitch) AND its isConfigured() says so, read per call so a key or plan change takes
 * effect without a reboot. Shopper's built-in 'manual' driver never has
 * provider capabilities and is skipped.
 *
 * One PRIMARY destination vocabulary: address forms search the FIRST
 * configured driver that ResolvesDestinations and an address stores a single
 * destination_id / destination_driver picked by the customer. A second
 * destination-based driver configured next to it gets the id its own search
 * finds for the address's exact postal code (ShippingQuoteContext::destinationId()),
 * (by postal code, then the address city / label; cached per region), so both
 * quote; when nothing matches the postal code exactly it quotes
 * nothing. Customer-picked ids for several drivers (a per-driver map on the
 * address) are not supported: every address writer (web, API, order,
 * allocation) would need it. Warehouse origins are kept per driver
 * (InventoryOrigins, originDrivers()). See README "Adding a shipping provider".
 */
final class ShippingCapabilities
{
    /**
     * Configured provider drivers keyed by code.
     *
     * @return Collection<string, ShippingDriver>
     */
    public function drivers(): Collection
    {
        $drivers = [];

        foreach (Shipping::availableDrivers() as $code) {
            if ($code === 'manual') {
                continue;
            }

            $driver = $this->resolve($code);

            if ($driver === null || ! $this->configured($driver)) {
                continue;
            }

            $drivers[$code] = $driver;
        }

        return collect($drivers);
    }

    /**
     * Configured drivers implementing a capability contract.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return Collection<string, ShippingDriver&T>
     */
    public function supporting(string $contract): Collection
    {
        return $this->drivers()->filter(static fn (ShippingDriver $driver): bool => $driver instanceof $contract);
    }

    /**
     * @param  class-string  $contract
     */
    public function supports(string $contract): bool
    {
        return $this->supporting($contract)->isNotEmpty();
    }

    /**
     * First configured driver implementing the contract.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return (ShippingDriver&T)|null
     */
    public function first(string $contract): ?ShippingDriver
    {
        return $this->supporting($contract)->first();
    }

    /**
     * The driver whose destination vocabulary address forms search.
     *
     * @return (ShippingDriver&ResolvesDestinations)|null
     */
    public function destinationDriver(): ?ShippingDriver
    {
        return $this->first(ResolvesDestinations::class);
    }

    public function destinationDriverCode(): ?string
    {
        return $this->destinationDriver()?->code();
    }

    /**
     * Address forms should offer a destination (area / subdistrict) search.
     */
    public function destinationSearch(): bool
    {
        return $this->destinationDriver() !== null;
    }

    /**
     * Checkout must not continue without a destination id: some configured
     * driver needs one and a configured driver can provide it.
     */
    public function requiresDestination(): bool
    {
        return $this->supports(RequiresDestination::class) && $this->destinationSearch();
    }

    /**
     * Some configured driver needs a destination pin point ("lat,lng").
     */
    public function requiresCoordinates(): bool
    {
        return $this->supports(RequiresCoordinates::class);
    }

    /**
     * Configured drivers that need a warehouse origin id in their own
     * vocabulary (cpanel location form fields): every driver with a
     * destination search, plus drivers that only declare an origin id
     * (RequiresOriginId, ValidatesOriginIds).
     *
     * @return Collection<string, ShippingDriver>
     */
    public function originDrivers(): Collection
    {
        return $this->drivers()->filter(static fn (ShippingDriver $driver): bool => $driver instanceof ResolvesDestinations
            || $driver instanceof RequiresOriginId
            || $driver instanceof ValidatesOriginIds);
    }

    /**
     * Driver code warehouse origins must exist for, so that quotes are
     * possible; null when origins are not needed.
     */
    public function originDriverCode(): ?string
    {
        return $this->requiresDestination() ? $this->destinationDriverCode() : null;
    }

    /**
     * The driver that owns a shipment (order_shipments.driver), whether or not
     * it is configured right now; null for unknown / unregistered codes.
     */
    public function forShipment(OrderShipment $shipment): ?ShippingDriver
    {
        $code = is_string($shipment->driver) ? trim($shipment->driver) : '';

        return $code !== '' ? $this->resolve($code) : null;
    }

    /**
     * The configured driver that registers this shipment with its provider
     * (store order, pickup, AWB), or null when staff ship it by hand with
     * Shopper's own label / AWB flow.
     *
     * - the shipment's own driver, when it FulfillsShipments;
     * - a quoting-only driver that DelegatesFulfillment hands over once
     *   (RajaOngkir Cost quotes are registered with Komerce Delivery);
     * - a legacy shipment with no driver recorded falls back to the single
     *   configured fulfilling driver, as before drivers were stored.
     *
     * 'manual' (Shopper zone options) and unknown codes never match, so
     * manual / COD orders keep Shopper's actions next to a provider.
     *
     * @return (ShippingDriver&FulfillsShipments)|null
     */
    public function fulfillerFor(OrderShipment $shipment): ?ShippingDriver
    {
        $code = is_string($shipment->driver) ? trim($shipment->driver) : '';

        return $code === ''
            ? $this->soleFulfiller()
            : $this->fulfillerForCode($code);
    }

    /**
     * The fulfilling driver of an order: from its warehouse shipments, or,
     * before any exists, from the carrier of its Shopper shipping option.
     *
     * @return (ShippingDriver&FulfillsShipments)|null
     */
    public function fulfillerForOrder(Order $order): ?ShippingDriver
    {
        $shipments = OrderShipment::query()->where('order_id', $order->id)->get();

        if ($shipments->isNotEmpty()) {
            foreach ($shipments as $shipment) {
                if (($driver = $this->fulfillerFor($shipment)) !== null) {
                    return $driver;
                }
            }

            return null;
        }

        $order->loadMissing('shippingOption.carrier');
        $carrierDriver = $order->shippingOption?->carrier?->driver;

        if (is_string($carrierDriver) && trim($carrierDriver) !== '') {
            return $this->fulfillerForCode(trim($carrierDriver));
        }

        return $order->shippingOption === null ? $this->soleFulfiller() : null;
    }

    /**
     * @return (ShippingDriver&FulfillsShipments)|null
     */
    public function fulfillerForCode(string $code): ?ShippingDriver
    {
        if ($code === 'manual') {
            return null;
        }

        $driver = $this->resolve($code);

        if ($driver instanceof DelegatesFulfillment) {
            $driver = $this->resolve($driver->fulfillmentDriver());
        }

        if (! $driver instanceof FulfillsShipments) {
            return null;
        }

        return $this->configured($driver) ? $driver : null;
    }

    /**
     * Restrict an order_shipments query to rows a configured driver fulfils
     * (the SQL twin of fulfillerFor()), so batch jobs apply their limit to
     * provider shipments only and are never starved by manual / zone-option
     * rows that no provider will ever pick up.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function whereFulfillable(Builder $query): Builder
    {
        $codes = $this->fulfilledDriverCodes();
        $legacy = $this->soleFulfiller() !== null;

        return $query->where(static function (Builder $where) use ($codes, $legacy): void {
            $where->whereIn('driver', $codes === [] ? [''] : $codes);

            if ($legacy) {
                $where->orWhereNull('driver')->orWhere('driver', '');
            }
        });
    }

    /**
     * Shipment driver codes (order_shipments.driver) that a configured driver
     * fulfils: fulfilling drivers themselves and drivers delegating to one.
     *
     * @return list<string>
     */
    public function fulfilledDriverCodes(): array
    {
        $codes = [];

        foreach (Shipping::availableDrivers() as $code) {
            if (is_string($code) && $code !== 'manual' && $this->fulfillerForCode($code) !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @return (ShippingDriver&FulfillsShipments)|null
     */
    private function soleFulfiller(): ?ShippingDriver
    {
        $fulfillers = $this->supporting(FulfillsShipments::class);

        return $fulfillers->count() === 1 ? $fulfillers->first() : null;
    }

    /**
     * Who quotes checkout rates: one chain per carrier-owning driver (the
     * carriers.driver code), each listing the configured drivers to ask in
     * order. A driver that DelegatesQuotes is asked after its preferred
     * driver; a preferred driver is not asked again on its own. Drivers that
     * are not configured or do not quote in real time are left out, so with
     * no provider configured there are no chains and checkout uses Shopper's
     * zone options alone.
     *
     * @return array<string, list<ShippingDriver>> owner code => drivers to ask in order
     */
    public function quoteChains(): array
    {
        $codes = array_values(array_filter(
            Shipping::availableDrivers(),
            static fn (mixed $code): bool => is_string($code) && $code !== 'manual',
        ));

        $claimed = [];

        foreach ($codes as $code) {
            $driver = $this->resolve($code);

            if ($driver instanceof DelegatesQuotes) {
                $claimed[$driver->preferredQuoteDriver()] = true;
            }
        }

        $chains = [];

        foreach ($codes as $code) {
            if (isset($claimed[$code])) {
                continue;
            }

            $driver = $this->resolve($code);

            if ($driver === null) {
                continue;
            }

            $members = [];

            if ($driver instanceof DelegatesQuotes) {
                $preferred = $this->resolve($driver->preferredQuoteDriver());

                if ($preferred !== null && $this->quotes($preferred)) {
                    $members[] = $preferred;
                }
            }

            if ($this->quotes($driver)) {
                $members[] = $driver;
            }

            if ($members !== []) {
                $chains[$code] = $members;
            }
        }

        return $chains;
    }

    /**
     * The configured driver that tracks a shipment's airway bill:
     *
     * - the provider that registered the shipment (FulfillsShipments) when it
     *   holds a provider reference;
     * - otherwise the shipment's own driver, when configured;
     * - otherwise the first configured driver that TracksShipments (an AWB
     *   staff typed in by hand can still be tracked through a provider).
     */
    public function trackerFor(OrderShipment $shipment): ?ShippingDriver
    {
        if (filled($shipment->external_reference)) {
            $fulfiller = $this->fulfillerFor($shipment);

            if ($fulfiller !== null && $fulfiller->supportsTracking()) {
                return $fulfiller;
            }
        }

        $own = $this->forShipment($shipment);

        if ($own !== null && $own->code() !== 'manual' && $this->configured($own) && $own->supportsTracking()) {
            return $own;
        }

        return $this->first(TracksShipments::class);
    }

    /**
     * The configured driver that can act on a shipment through a capability
     * contract: its own driver first, then the driver that fulfils it.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return (ShippingDriver&T)|null
     */
    public function forShipmentSupporting(OrderShipment $shipment, string $contract): ?ShippingDriver
    {
        $own = $this->forShipment($shipment);

        if ($own instanceof $contract && $this->configured($own)) {
            return $own;
        }

        $fulfiller = $this->fulfillerFor($shipment);

        return $fulfiller instanceof $contract ? $fulfiller : null;
    }

    private function quotes(ShippingDriver $driver): bool
    {
        return $this->configured($driver) && $driver->supportsRealTimeRates();
    }

    /**
     * Operator switch on (`shopper.shipping.drivers.{code}.enabled`, see
     * App\Support\OperatorSwitch) and the driver reports itself configured.
     */
    public function configured(ShippingDriver $driver): bool
    {
        if (! OperatorSwitch::on(OperatorSwitch::SHIPPING, $driver->code())) {
            return false;
        }

        try {
            return $driver->isConfigured();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Shape of the Inertia `shippingCapabilities` prop.
     *
     * @return array{destinationSearch: bool, requiresDestination: bool, requiresCoordinates: bool}
     */
    public function toInertia(): array
    {
        $summary = $this->summary();

        return [
            'destinationSearch' => $summary['destination_search'],
            'requiresDestination' => $summary['requires_destination'],
            'requiresCoordinates' => $summary['requires_coordinates'],
        ];
    }

    /**
     * Shape of the mobile API `shipping_capabilities` field.
     *
     * @return array{destination_search: bool, requires_destination: bool, requires_coordinates: bool}
     */
    public function toApi(): array
    {
        return $this->summary();
    }

    /**
     * The three client-facing answers from ONE walk over the configured
     * drivers (same rules as destinationSearch() / requiresDestination() /
     * requiresCoordinates()). Driver instances themselves are memoized by
     * Shopper's ShippingManager singleton.
     *
     * @return array{destination_search: bool, requires_destination: bool, requires_coordinates: bool}
     */
    private function summary(): array
    {
        $drivers = $this->drivers();
        $search = $drivers->contains(static fn (ShippingDriver $driver): bool => $driver instanceof ResolvesDestinations);

        return [
            'destination_search' => $search,
            'requires_destination' => $search
                && $drivers->contains(static fn (ShippingDriver $driver): bool => $driver instanceof RequiresDestination),
            'requires_coordinates' => $drivers->contains(static fn (ShippingDriver $driver): bool => $driver instanceof RequiresCoordinates),
        ];
    }

    private function resolve(string $code): ?ShippingDriver
    {
        try {
            return Shipping::driver($code);
        } catch (InvalidArgumentException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
