<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Shipping;

use App\Integrations\Komerce\Services\ShippingCostClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use Illuminate\Support\Facades\Cache;
use Shopper\Core\Models\Inventory;
use Throwable;

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
        return $this->matchWith(
            $inventory,
            function (string $query): array {
                $normalized = mb_strtolower(trim($query));

                if ($normalized === '') {
                    return [];
                }

                $cacheKey = 'rajaongkir:destinations:v1:'.sha1("{$normalized}|50");
                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    return $cached;
                }

                $rows = $this->client->searchDomestic($normalized, 50);
                Cache::put($cacheKey, $rows, now()->addDays(30));

                return $rows;
            },
            'shipping_cost',
        );
    }

    /**
     * @param  callable(string): list<array<string, mixed>>  $search
     * @return array{id: string, label: string|null}|null
     */
    public function matchWith(Inventory $inventory, callable $search, string $product = 'shipping_cost'): ?array
    {
        $postalCode = trim((string) $inventory->getAttribute('postal_code'));
        $city = trim((string) $inventory->getAttribute('city'));
        $street = trim((string) $inventory->getAttribute('street_address'));
        $state = trim((string) $inventory->getAttribute('state'));

        if ($postalCode === '' && $city === '' && $street === '') {
            return null;
        }

        $capabilities = resolve(KomerceCapabilities::class);
        $results = [];

        $queries = array_values(array_unique(array_filter([
            $postalCode !== '' ? $postalCode : null,
            ($city !== '' || $street !== '') ? trim("{$street} {$city}") : null,
            $city !== '' ? $city : null,
        ], static fn (?string $query): bool => $query !== null && $query !== '')));

        foreach ($queries as $query) {
            if ($product === 'shipping_cost') {
                $cacheKey = 'rajaongkir:destinations:v1:'.sha1(mb_strtolower(trim($query)).'|50');
                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    $results = $cached;
                    if ($results !== []) {
                        break;
                    }

                    continue;
                }
            } elseif ($product === 'shipping_delivery') {
                $cacheKey = 'komerce:delivery:destinations:v1:'.sha1(mb_strtolower(trim($query)));
                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    $results = $cached;
                    if ($results !== []) {
                        break;
                    }

                    continue;
                }
            }

            if ($capabilities->quotaExhausted($product)) {
                return null;
            }

            try {
                $results = $search($query);
            } catch (Throwable $e) {
                if ($capabilities->quotaExhausted($product)) {
                    return null;
                }

                throw $e;
            }

            if (! empty($results)) {
                break;
            }
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
