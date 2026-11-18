<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Shipping;

use App\Integrations\Komerce\Services\ShippingCostClient;
use Shopper\Core\Models\Inventory;

/**
 * Find the single RajaOngkir destination id that matches a warehouse address.
 * The search endpoint returns candidates, not a canonical match, so only a
 * result supported by the supplied address data is accepted.
 */
final readonly class RajaOngkirOriginMatcher
{
    public function __construct(private ShippingCostClient $client) {}

    /**
     * @return array{id: string, label: string|null}|null
     */
    public function match(Inventory $inventory): ?array
    {
        $postalCode = trim((string) $inventory->getAttribute('postal_code'));
        $city = trim((string) $inventory->getAttribute('city'));
        $street = trim((string) $inventory->getAttribute('street_address'));
        $state = trim((string) $inventory->getAttribute('state'));

        if ($postalCode === '' && $city === '' && $street === '') {
            return null;
        }

        $results = [];
        if ($postalCode !== '') {
            $results = $this->client->searchDomestic($postalCode, 50);
        }

        if (empty($results) && ($city !== '' || $street !== '')) {
            $results = $this->client->searchDomestic(trim("{$street} {$city}"), 50);
        }

        if (empty($results) && $city !== '') {
            $results = $this->client->searchDomestic($city, 50);
        }

        if (empty($results)) {
            return null;
        }

        return $this->best($results, $street, $city, $state, $postalCode);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{id: string, label: string|null}|null
     */
    private function best(array $results, string $street, string $city, string $state, string $postalCode): ?array
    {
        $candidates = array_values(array_filter(
            $results,
            static fn (mixed $row): bool => is_array($row)
                && isset($row['id'])
                && trim((string) $row['id']) !== '',
        ));

        if ($candidates === []) {
            return null;
        }

        $hasVerifiedMatch = false;

        if ($postalCode !== '') {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $row): bool => trim((string) ($row['zip_code'] ?? '')) === $postalCode,
            ));

            if ($candidates === []) {
                return null;
            }

            $hasVerifiedMatch = true;
        }

        $normalizedStreet = $this->normalize($street);
        if ($normalizedStreet !== '') {
            $streetMatches = array_values(array_filter(
                $candidates,
                function (array $row) use ($normalizedStreet): bool {
                    foreach (['subdistrict_name', 'district_name'] as $field) {
                        $place = $this->normalize((string) ($row[$field] ?? ''));

                        if ($place !== '' && str_contains($normalizedStreet, $place)) {
                            return true;
                        }
                    }

                    return false;
                },
            ));

            if ($streetMatches !== []) {
                $candidates = $streetMatches;
                $hasVerifiedMatch = true;
            }
        }

        foreach ([
            'city_name' => $city,
            'province_name' => $state,
        ] as $field => $expected) {
            $expected = $this->normalize($expected);

            if ($expected === '') {
                continue;
            }

            $fieldMatches = array_values(array_filter(
                $candidates,
                function (array $row) use ($field, $expected): bool {
                    $actual = $this->normalize((string) ($row[$field] ?? ''));

                    return $actual !== ''
                        && (str_contains($actual, $expected) || str_contains($expected, $actual));
                },
            ));

            $knownFieldRows = array_filter(
                $candidates,
                fn (array $row): bool => $this->normalize((string) ($row[$field] ?? '')) !== '',
            );

            if ($knownFieldRows !== [] && $fieldMatches === []) {
                return null;
            }

            if ($fieldMatches === []) {
                continue;
            }

            $candidates = $fieldMatches;
            $hasVerifiedMatch = true;
        }

        if (! $hasVerifiedMatch || count($candidates) !== 1) {
            return null;
        }

        $label = $candidates[0]['label'] ?? null;

        return [
            'id' => (string) $candidates[0]['id'],
            'label' => is_scalar($label) && trim((string) $label) !== '' ? (string) $label : null,
        ];
    }

    private function normalize(string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }
}
