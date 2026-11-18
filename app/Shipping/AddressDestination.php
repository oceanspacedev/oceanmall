<?php

declare(strict_types=1);

namespace App\Shipping;

/**
 * Provider-neutral destination fields on an address array (checkout session,
 * address-book metadata, order metadata.shipping_address, API payloads).
 *
 *   destination_id      provider destination / area id (string)
 *   destination_label   human label of that destination
 *   destination_driver  Shopper shipping driver code whose vocabulary the id belongs to
 *   pin_point           "lat,lng"
 *
 * Legacy keys (rajaongkir_destination_id / rajaongkir_destination_label /
 * rajaongkir_pin_point) are still ACCEPTED on input and READ as a fallback:
 * mobile builds already in the stores send them, and rows written before the
 * neutral keys existed carry them. In STORED data they imply
 * destination_driver=rajaongkir (fromInput / normalize); in a client REQUEST
 * they hold an id of the current destination driver (fromRequest).
 */
final class AddressDestination
{
    public const ID = 'destination_id';

    public const LABEL = 'destination_label';

    public const DRIVER = 'destination_driver';

    public const PIN_POINT = 'pin_point';

    /**
     * Id prefix of the free-text row served to old app builds when no driver
     * resolves destinations (freeTextRows()); such an id is never stored.
     */
    public const FREE_TEXT_PREFIX = 'text:';

    /** Driver whose ids the legacy keys hold. */
    public const LEGACY_DRIVER = 'rajaongkir';

    /** @var array<string, string> legacy key => neutral key */
    public const LEGACY_KEYS = [
        'rajaongkir_destination_id' => self::ID,
        'rajaongkir_destination_label' => self::LABEL,
        'rajaongkir_pin_point' => self::PIN_POINT,
    ];

    /**
     * Destination id, neutral key first then legacy. With $driver, an id
     * stored for another driver's vocabulary is ignored (stale after a
     * provider switch).
     *
     * @param  array<string, mixed>  $address
     */
    public static function id(array $address, ?string $driver = null): ?string
    {
        $id = self::string($address[self::ID] ?? null)
            ?? self::string($address['rajaongkir_destination_id'] ?? null);

        if ($id === null) {
            return null;
        }

        if ($driver !== null) {
            $owner = self::driver($address);

            if ($owner !== null && $owner !== $driver) {
                return null;
            }
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    public static function label(array $address): ?string
    {
        return self::string($address[self::LABEL] ?? null)
            ?? self::string($address['rajaongkir_destination_label'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    public static function pinPoint(array $address): ?string
    {
        return self::string($address[self::PIN_POINT] ?? null)
            ?? self::string($address['rajaongkir_pin_point'] ?? null);
    }

    /**
     * Driver code the destination id belongs to (legacy keys imply rajaongkir).
     *
     * @param  array<string, mixed>  $address
     */
    public static function driver(array $address): ?string
    {
        $driver = self::string($address[self::DRIVER] ?? null);

        if ($driver !== null) {
            return $driver;
        }

        if (self::string($address[self::ID] ?? null) === null
            && self::string($address['rajaongkir_destination_id'] ?? null) !== null) {
            return self::LEGACY_DRIVER;
        }

        return null;
    }

    /**
     * Neutral fields from request input that may use legacy names. Only keys
     * that have a value are returned, so it is safe to merge into a request.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function fromInput(array $input): array
    {
        $fields = [];

        $id = self::string($input[self::ID] ?? null);
        $legacyId = self::string($input['rajaongkir_destination_id'] ?? null);

        if (($id ?? $legacyId) !== null) {
            $fields[self::ID] = $id ?? $legacyId;
        }

        if (($label = self::label($input)) !== null) {
            $fields[self::LABEL] = $label;
        }

        if (($pin = self::pinPoint($input)) !== null) {
            $fields[self::PIN_POINT] = $pin;
        }

        $driver = self::string($input[self::DRIVER] ?? null);

        if ($driver === null && $id === null && $legacyId !== null) {
            $driver = self::LEGACY_DRIVER;
        }

        if ($driver !== null) {
            $fields[self::DRIVER] = $driver;
        }

        return $fields;
    }

    /**
     * Neutral fields from a client REQUEST (web form, mobile API). Unlike
     * stored rows, a legacy-only id in a request is not RajaOngkir's by
     * definition: app builds already in the stores search
     * /checkout/destinations, which serves the CURRENT destination driver's
     * vocabulary, and post the picked id back as rajaongkir_destination_id
     * without destination_driver. Such an id is stamped $currentDriver
     * (rajaongkir only when no destination driver is configured, as before
     * drivers were recorded). An explicit destination_driver always wins.
     * The id of a free-text row (freeTextRows()) comes back as null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    public static function fromRequest(array $input, ?string $currentDriver): array
    {
        $fields = self::fromInput($input);

        // The free-text row of freeTextRows() is not a destination: drop its
        // id and label explicitly (null, so they also override the raw
        // request input). The label would otherwise come back as the
        // destination label, which old builds post as the city.
        if (isset($fields[self::ID]) && str_starts_with($fields[self::ID], self::FREE_TEXT_PREFIX)) {
            unset($fields[self::DRIVER]);
            $fields[self::ID] = null;
            $fields[self::LABEL] = null;

            return $fields;
        }

        if (self::legacyOnly($input) && self::string($input[self::DRIVER] ?? null) === null) {
            $fields[self::DRIVER] = $currentDriver ?? self::LEGACY_DRIVER;
        }

        return $fields;
    }

    /**
     * The input carries a destination id under the legacy key only.
     *
     * @param  array<string, mixed>  $input
     */
    public static function legacyOnly(array $input): bool
    {
        return self::string($input[self::ID] ?? null) === null
            && self::string($input['rajaongkir_destination_id'] ?? null) !== null;
    }

    /**
     * Rewrite an address array to neutral keys only: legacy values fill
     * missing neutral ones, legacy keys are dropped, and a destination id
     * without an owner is stamped with $defaultDriver.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    public static function normalize(array $address, ?string $defaultDriver = null): array
    {
        $fields = self::fromInput($address);

        foreach (array_keys(self::LEGACY_KEYS) as $legacy) {
            unset($address[$legacy]);
        }

        foreach ([self::ID, self::LABEL, self::PIN_POINT, self::DRIVER] as $key) {
            if (array_key_exists($key, $fields)) {
                $address[$key] = $fields[$key];
            } elseif (array_key_exists($key, $address) && self::string($address[$key]) === null) {
                $address[$key] = null;
            }
        }

        if (self::string($address[self::ID] ?? null) === null) {
            unset($address[self::DRIVER]);
        } elseif (self::string($address[self::DRIVER] ?? null) === null && $defaultDriver !== null) {
            $address[self::DRIVER] = $defaultDriver;
        }

        return $address;
    }

    /** Region keys of a destination search row (ResolvesDestinations). */
    public const SEARCH_REGION_KEYS = ['postal_code', 'city', 'state', 'district'];

    /**
     * A destination search row as clients receive it: `id` / `label` as
     * strings and every neutral region key present (null when the driver did
     * not supply it). Null for a row without an id.
     *
     * @param  mixed  $row  One row from ResolvesDestinations::searchDestinations()
     * @return array<string, mixed>|null
     */
    public static function searchRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $id = self::string($row['id'] ?? null);

        if ($id === null) {
            return null;
        }

        $row['id'] = $id;
        $row['label'] = self::string($row['label'] ?? null) ?? $id;

        foreach (self::SEARCH_REGION_KEYS as $key) {
            $row[$key] = self::string($row[$key] ?? null);
        }

        return $row;
    }

    /**
     * Destination search answer for app builds released before
     * shipping_capabilities when NO driver resolves destinations: those builds
     * require a picked row and read its zip_code as the postal code. A query
     * holding a 5-digit postal code becomes one row echoing it (the legacy
     * city_name / province_name / zip_code keys those builds read, plus the
     * neutral ones); any other query gets none here (AddressBookDestinations
     * adds the regions customers already saved). The row's id is dropped
     * again by fromRequest().
     *
     * @return list<array<string, mixed>>
     */
    public static function freeTextRows(string $query): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');

        if (mb_strlen($query) < 2 || mb_strlen($query) > 100 || preg_match('/(?<!\d)(\d{5})(?!\d)/', $query, $match) !== 1) {
            return [];
        }

        $postalCode = $match[1];
        $area = trim(preg_replace('/(?<!\d)'.$postalCode.'(?!\d)/', '', $query) ?? '', ' ,.-');

        return [self::freeTextRow($postalCode, $area !== '' ? $area : null, null, $query)];
    }

    /**
     * One free-text search row (see freeTextRows()) for a region: the legacy
     * city_name / province_name / zip_code keys old builds read, plus the
     * neutral ones. The label defaults to "City, Province 12345".
     *
     * @return array<string, mixed>
     */
    public static function freeTextRow(string $postalCode, ?string $city, ?string $state, ?string $label = null): array
    {
        $region = implode(', ', array_filter([$city, $state], static fn (?string $part): bool => $part !== null && $part !== ''));
        $label ??= trim($region.' '.$postalCode);

        return [
            'id' => self::FREE_TEXT_PREFIX.$label,
            'label' => $label,
            'postal_code' => $postalCode,
            'city' => $city,
            'state' => $state,
            'district' => null,
            'zip_code' => $postalCode,
            'city_name' => $city,
            'province_name' => $state,
        ];
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    public static function searchRows(iterable $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (($row = self::searchRow($row)) !== null) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * An address for a client form: neutral keys only (legacy values moved
     * over, e.g. a checkout session saved before the neutral keys existed),
     * and the destination cleared when it is not in $driver's vocabulary
     * (stale after a provider switch, or no destination driver at all).
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    public static function forDriver(array $address, ?string $driver): array
    {
        $address = self::normalize($address);

        if ($driver === null || self::id($address, $driver) === null) {
            $address[self::ID] = null;
            $address[self::LABEL] = null;
            unset($address[self::DRIVER]);
        }

        return $address;
    }

    /**
     * Neutral keys plus the legacy aliases, for API responses read by mobile
     * builds released before the neutral keys existed.
     *
     * With $freeTextFallback (no driver resolves destinations), an address
     * without a destination id gets a free-text LEGACY id instead of null:
     * builds released before shipping_capabilities only reuse an address that
     * has a picked destination, and would make the customer search again
     * every time. They post the id back (fromRequest() drops it) with the
     * address's own postal_code and city. The neutral destination_id stays
     * null, so current clients see no destination.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    public static function withLegacyKeys(array $address, bool $freeTextFallback = false): array
    {
        $address[self::ID] = self::id($address);
        $address[self::LABEL] = self::label($address);
        $address[self::PIN_POINT] = self::pinPoint($address);
        $address[self::DRIVER] = $address[self::ID] !== null ? self::driver($address) : null;

        foreach (self::LEGACY_KEYS as $legacy => $neutral) {
            $address[$legacy] = $address[$neutral];
        }

        $postalCode = self::string($address['postal_code'] ?? null);

        if ($freeTextFallback && $address[self::ID] === null && $postalCode !== null) {
            $address['rajaongkir_destination_id'] = self::FREE_TEXT_PREFIX
                .trim($postalCode.' '.(self::string($address['city'] ?? null) ?? ''));
        }

        return $address;
    }

    /**
     * Validation rules for the neutral fields.
     *
     * @param  list<string>|string  $idRule  Rule for destination_id (required / nullable)
     * @return array<string, list<string>|string>
     */
    public static function rules(array|string $idRule = ['nullable', 'string', 'max:50']): array
    {
        return [
            self::ID => $idRule,
            self::LABEL => ['nullable', 'string', 'max:255'],
            self::DRIVER => ['nullable', 'string', 'max:64'],
            // "lat,lng" (decimal degrees), as web checkout's location button
            // and DestinationCoordinateResolver produce it.
            self::PIN_POINT => ['nullable', 'string', 'max:64', 'regex:/^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/'],
        ];
    }

    private static function string(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
