<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Shipping\Contracts\ResolvesDestinations;
use App\Support\EnabledCarriers;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Address;
use Throwable;

/**
 * Request-scoped quote inputs that Shopper's shipping Address DTO cannot
 * carry, filled once by checkout (FetchDeliveryRates) before it calls
 * ShippingDriver::calculateRates() and read by any driver that needs them:
 *
 * - the checkout address array (neutral destination_id / pin_point keys,
 *   see AddressDestination), and the warehouse the parcel leaves from;
 * - the declared item value (insurance / COD tariffs);
 * - the courier codes of the Shopper carriers the call quotes for, and the
 *   codes staff switched off in cpanel;
 * - per-rate details a driver wants on the checkout row next to Shopper's
 *   ShippingRate fields (fee breakdowns, provider tariff rows...);
 * - the last provider failure, so checkout can tell "provider down / out of
 *   quota" from "no service for this route".
 *
 * It names no provider: drivers ask for their own vocabulary
 * (destinationId($code), originId($code)).
 *
 * Outside checkout (Shopper's CarrierRateService, or any code calling
 * Shipping::driver($code)->calculateRates() directly) nothing fills it; a
 * driver then calls beginFromAddress() to derive it from Shopper's Address
 * DTO, and end() when done.
 */
final class ShippingQuoteContext
{
    /** @var array<string, mixed>|null */
    private ?array $address = null;

    private ?Inventory $origin = null;

    private int $itemValue = 0;

    /** @var list<string> */
    private array $couriers = [];

    /** @var list<string> */
    private array $excludedCouriers = [];

    /** @var array<string, array<string, mixed>> */
    private array $details = [];

    private ?ShippingQuoteFailure $lastFailure = null;

    /** @var array<string, string|null> driver => destination id derived from the postal code */
    private array $derivedDestinations = [];

    /**
     * @param  array<string, mixed>  $address
     */
    public function begin(array $address, ?Inventory $origin, int $itemValue = 0): void
    {
        $this->address = $address;
        $this->origin = $origin;
        $this->itemValue = max(0, $itemValue);
        $this->couriers = [];
        $this->excludedCouriers = [];
        $this->details = [];
        $this->derivedDestinations = [];
    }

    /**
     * Courier codes the next calculateRates() call quotes for. An empty list
     * means "whatever the provider offers".
     *
     * @param  list<string>  $couriers
     * @param  list<string>  $excluded  Codes switched off in cpanel
     */
    public function forCouriers(array $couriers, array $excluded = []): void
    {
        $this->couriers = self::codes($couriers);
        $this->excludedCouriers = self::codes($excluded);
    }

    public function active(): bool
    {
        return $this->address !== null;
    }

    /**
     * Derive the context from Shopper's destination Address when nothing
     * filled it (a direct calculateRates() call): the default warehouse as
     * origin, the address fields, the destination id $resolver finds for the
     * address's 5-digit postal code (the first search row with exactly that
     * postal code, else none), and the enabled / switched-off couriers of
     * the carriers $courierOwners own. No item value is known (0).
     *
     * Returns true when it began the context, so the caller must end() it;
     * false when checkout already filled it (left untouched).
     *
     * @param  list<string>  $courierOwners  carriers.driver codes whose couriers are quoted
     */
    public function beginFromAddress(Address $to, ?ResolvesDestinations $resolver = null, ?string $resolverCode = null, array $courierOwners = []): bool
    {
        if ($this->active()) {
            return false;
        }

        $address = [
            'first_name' => $to->firstName,
            'last_name' => $to->lastName,
            'street_address' => $to->street,
            'street_address_plus' => $to->street2,
            'city' => $to->city,
            'postal_code' => $to->postalCode,
            'state' => $to->state,
            'phone_number' => $to->phone,
        ];

        $origin = Inventory::query()->where('is_default', true)->first()
            ?? Inventory::query()->orderBy('id')->first();

        // No destination search (an API call) when the warehouse has no
        // origin in that vocabulary: there would be nothing to quote anyway.
        if (
            $resolver !== null && $resolverCode !== null && $origin instanceof Inventory
            && resolve(InventoryOrigins::class)->get($origin, $resolverCode) !== null
            && ($id = self::destinationFor($resolver, $to->postalCode)) !== null
        ) {
            $address[AddressDestination::ID] = $id;
            $address[AddressDestination::DRIVER] = $resolverCode;
        }

        $this->begin($address, $origin);

        if ($courierOwners !== []) {
            $carriers = Carrier::query()->whereIn('driver', $courierOwners)->orderBy('id')->get();
            $codes = static fn (Carrier $carrier): string => EnabledCarriers::courierCode($carrier);

            $this->forCouriers(
                $carriers->filter(static fn (Carrier $carrier): bool => (bool) $carrier->is_enabled)->map($codes)->values()->all(),
                $carriers->reject(static fn (Carrier $carrier): bool => (bool) $carrier->is_enabled)->map($codes)->values()->all(),
            );
        }

        return true;
    }

    /**
     * The id of the first destination $resolver finds for an exact 5-digit
     * postal code; null when none matches exactly.
     *
     * Searched by the postal code first, then by each of $terms (area names
     * of the address, for providers whose search does not index postal
     * codes); a row only counts when its postal code is exactly the
     * address's. Throws when the provider search fails.
     *
     * @param  list<string|null>  $terms
     */
    private static function lookupDestination(ResolvesDestinations $resolver, string $postalCode, array $terms = []): ?string
    {
        $queries = array_values(array_unique(array_filter(
            [$postalCode, ...array_map(static fn (?string $term): string => trim((string) $term), $terms)],
            static fn (string $query): bool => mb_strlen($query) >= 2,
        )));

        foreach ($queries as $query) {
            foreach (AddressDestination::searchRows($resolver->searchDestinations($query)) as $row) {
                if (($row['postal_code'] ?? null) === $postalCode) {
                    return (string) $row['id'];
                }
            }
        }

        return null;
    }

    private static function postalCode(mixed $postalCode): ?string
    {
        $postalCode = is_scalar($postalCode) ? trim((string) $postalCode) : '';

        return preg_match('/^\d{5}$/', $postalCode) === 1 ? $postalCode : null;
    }

    private static function destinationFor(ResolvesDestinations $resolver, mixed $postalCode): ?string
    {
        $postalCode = self::postalCode($postalCode);

        if ($postalCode === null) {
            return null;
        }

        try {
            return self::lookupDestination($resolver, $postalCode);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function address(): array
    {
        return $this->address ?? [];
    }

    public function origin(): ?Inventory
    {
        return $this->origin;
    }

    /**
     * The warehouse's origin id in a driver's vocabulary.
     */
    public function originId(string $driver): ?string
    {
        return $this->origin instanceof Inventory
            ? resolve(InventoryOrigins::class)->get($this->origin, $driver)
            : null;
    }

    /**
     * The destination id in a driver's vocabulary (null when the address
     * holds none, or one of another driver's vocabulary).
     */
    public function destinationId(string $driver): ?string
    {
        if ($this->address === null) {
            return null;
        }

        $id = AddressDestination::id($this->address, $driver);

        if ($id !== null) {
            return $id;
        }

        if (! array_key_exists($driver, $this->derivedDestinations)) {
            $this->derivedDestinations[$driver] = $this->secondaryDestination($driver);
        }

        return $this->derivedDestinations[$driver];
    }

    /**
     * An address stores the destination id of ONE driver: the primary
     * destination driver whose search the address forms use
     * (ShippingCapabilities::destinationDriver()). Another configured driver
     * that ResolvesDestinations gets the id its own search finds for the
     * address's exact postal code, so two destination-based providers can
     * quote side by side. The primary driver never guesses: without a picked
     * id it quotes nothing and checkout asks for one.
     *
     * The search tries the postal code, then the address's city and
     * destination label (a row must still carry exactly that postal code).
     * Answers are cached per driver and region (a match for 30 days, no
     * match for a day; a failed search is not cached), so repeat quotes cost
     * no extra provider call.
     */
    private function secondaryDestination(string $driver): ?string
    {
        $capabilities = resolve(ShippingCapabilities::class);

        if ($capabilities->destinationDriverCode() === $driver) {
            return null;
        }

        $resolver = $capabilities->supporting(ResolvesDestinations::class)->get($driver);
        $postalCode = self::postalCode($this->address['postal_code'] ?? null);

        if (! $resolver instanceof ResolvesDestinations || $postalCode === null) {
            return null;
        }

        $terms = [
            is_scalar($this->address['city'] ?? null) ? (string) $this->address['city'] : null,
            AddressDestination::label($this->address ?? []),
        ];
        $key = 'shipping:secondary-destination:'.$driver.':'.$postalCode.':'.sha1(mb_strtolower(implode('|', $terms)));
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached !== '' ? $cached : null;
        }

        try {
            $id = self::lookupDestination($resolver, $postalCode, $terms);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        Cache::put($key, $id ?? '', $id !== null ? now()->addDays(30) : now()->addDay());

        return $id;
    }

    public function pinPoint(): ?string
    {
        return $this->address !== null ? AddressDestination::pinPoint($this->address) : null;
    }

    public function itemValue(): int
    {
        return $this->itemValue;
    }

    /**
     * @return list<string>
     */
    public function couriers(): array
    {
        return $this->couriers;
    }

    public function isExcluded(string $courier): bool
    {
        return in_array(strtolower(trim($courier)), $this->excludedCouriers, true);
    }

    /**
     * Attach driver-specific fields to one quoted rate; checkout merges them
     * into the rate row (and the selected row is stored on the shipment).
     *
     * @param  array<string, mixed>  $details
     */
    public function describe(string $driver, string|int $serviceCode, array $details): void
    {
        $this->details[$driver.'|'.$serviceCode] = $details;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(string $driver, string|int $serviceCode): array
    {
        return $this->details[$driver.'|'.$serviceCode] ?? [];
    }

    public function markFailure(string $driver, string $reason, string $message = '', ?CarbonInterface $retryAfter = null): void
    {
        $this->lastFailure = new ShippingQuoteFailure($driver, $reason, $message, $retryAfter);
    }

    public function lastFailure(): ?ShippingQuoteFailure
    {
        return $this->lastFailure;
    }

    /**
     * Forget the request inputs; the last failure stays readable for the
     * checkout hint.
     */
    public function end(): void
    {
        $this->address = null;
        $this->origin = null;
        $this->itemValue = 0;
        $this->couriers = [];
        $this->excludedCouriers = [];
        $this->details = [];
        $this->derivedDestinations = [];
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    private static function codes(array $codes): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $code): string => is_scalar($code) ? strtolower(trim((string) $code)) : '',
            $codes,
        ), static fn (string $code): bool => $code !== '')));
    }
}
