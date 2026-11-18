<?php

declare(strict_types=1);

namespace App\Shipping;

use Shopper\Core\Models\Address;

/**
 * Destination search for app builds released before shipping_capabilities
 * when NO driver resolves destinations. Those builds require a row picked
 * from /checkout/destinations and read its zip_code / city_name /
 * province_name as the address region.
 *
 * Rows come from the store's own address book: the distinct postal code /
 * city / province triples customers already saved (no names or streets), so
 * a search by area name ("Harjamukti") or by postal code alone finds the
 * region without a provider. A query holding a postal code also gets a row
 * echoing what was typed (AddressDestination::freeTextRows()). Every row id
 * carries AddressDestination::FREE_TEXT_PREFIX and is never stored
 * (AddressDestination::fromRequest()).
 */
final class AddressBookDestinations
{
    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');

        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return [];
        }

        $postalCode = preg_match('/(?<!\d)(\d{5})(?!\d)/', $query, $match) === 1 ? $match[1] : null;
        $area = $postalCode !== null
            ? trim(preg_replace('/(?<!\d)'.$postalCode.'(?!\d)/', '', $query) ?? '', ' ,.-')
            : $query;

        $echo = AddressDestination::freeTextRows($query);
        // "Cirebon 45153": the typed area first, then what the address book knows
        // for that postal code. "45153" alone: the address book's area, if any.
        $rows = $area !== '' ? $echo : [];

        foreach ($this->known($postalCode, $postalCode !== null ? null : $area, $limit) as $known) {
            if ($rows !== [] && mb_strtolower((string) $rows[0]['city_name']) === mb_strtolower($known['city'])
                && $rows[0]['zip_code'] === $known['postal_code']) {
                continue;
            }

            $rows[] = AddressDestination::freeTextRow($known['postal_code'], $known['city'], $known['state']);
        }

        if ($rows === []) {
            $rows = $echo;
        }

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Distinct saved regions with a 5-digit postal code, by postal code or by
     * a city / province name containing $area.
     *
     * @return list<array{postal_code: string, city: string, state: string|null}>
     */
    private function known(?string $postalCode, ?string $area, int $limit): array
    {
        // LIKE wildcards typed by the customer are matched literally by dropping them.
        $area = $area !== null ? mb_strtolower(trim(str_replace(['%', '_'], '', $area))) : null;

        if ($postalCode === null && ($area === null || mb_strlen($area) < 3)) {
            return [];
        }

        $rows = Address::query()
            ->select(['postal_code', 'city', 'state'])
            ->distinct()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->when($postalCode !== null, static fn ($query) => $query->where('postal_code', $postalCode))
            ->when($postalCode === null, static fn ($query) => $query->where(static fn ($query) => $query
                ->whereRaw('LOWER(city) LIKE ?', ['%'.$area.'%'])
                ->orWhereRaw('LOWER(state) LIKE ?', ['%'.$area.'%'])))
            ->orderBy('city')
            ->orderBy('postal_code')
            ->limit(max(1, $limit) * 3)
            ->get();

        $known = [];

        foreach ($rows as $row) {
            $postal = trim((string) $row->postal_code);
            $city = trim((string) $row->city);
            $key = $postal.'|'.mb_strtolower($city);

            // A city that is only the postal code (typed into an old build) is no area.
            if (preg_match('/^\d{5}$/', $postal) !== 1 || $city === '' || $city === $postal || isset($known[$key])) {
                continue;
            }

            $state = trim((string) $row->state);
            $known[$key] = ['postal_code' => $postal, 'city' => $city, 'state' => $state !== '' ? $state : null];

            if (count($known) >= $limit) {
                break;
            }
        }

        return array_values($known);
    }
}
