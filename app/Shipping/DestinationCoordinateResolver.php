<?php

declare(strict_types=1);

namespace App\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Destination pin point ("lat,lng") for drivers that RequiresCoordinates.
 *
 * Part of that contract: a coordinate-based driver must quote and fulfil
 * without a customer-supplied pin, because clients collect one only when they
 * can (web checkout offers the browser location when
 * shippingCapabilities.requiresCoordinates; app builds do not collect one).
 * Order of preference:
 *
 * 1. the address pin_point (customer location, or one stored earlier);
 * 2. latitude / longitude fields on the address;
 * 3. geocoding the destination label / city / postal code (OpenStreetMap
 *    Nominatim, cached 30 days per destination; a failed lookup is cached
 *    for an hour so a quote never re-asks Nominatim on every request);
 * 4. the caller's fallback (e.g. the warehouse pin).
 */
final class DestinationCoordinateResolver
{
    private const CACHE_PREFIX = 'shipping_destination_pin_v2:';

    /** Cached marker of a failed geocode. */
    private const MISS = '-';

    /**
     * Resolve a comma-separated "latitude,longitude" pinpoint string from address data.
     *
     * @param  array<string, mixed>  $address
     */
    public function resolve(array $address, ?string $fallbackPin = null): ?string
    {
        $pin = AddressDestination::pinPoint($address);
        if (is_string($pin) && $this->isValidPinPoint($pin)) {
            return trim($pin);
        }

        $lat = $address['latitude'] ?? null;
        $lng = $address['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            $candidate = $lat.','.$lng;
            if ($this->isValidPinPoint($candidate)) {
                return $candidate;
            }
        }

        $geocoded = $this->geocodeCached($address);

        if ($geocoded !== null) {
            return $geocoded;
        }

        if ($fallbackPin !== null && $this->isValidPinPoint($fallbackPin)) {
            return $fallbackPin;
        }

        return null;
    }

    public function isValidPinPoint(string $pin): bool
    {
        $parts = explode(',', trim($pin));
        if (count($parts) !== 2) {
            return false;
        }

        $lat = filter_var(trim($parts[0]), FILTER_VALIDATE_FLOAT);
        $lng = filter_var(trim($parts[1]), FILTER_VALIDATE_FLOAT);

        return $lat !== false && $lng !== false && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function geocodeCached(array $address): ?string
    {
        $driver = (string) AddressDestination::driver($address);
        $destinationId = (string) AddressDestination::id($address);
        $label = (string) AddressDestination::label($address);
        $city = (string) ($address['city'] ?? '');
        $state = (string) ($address['state'] ?? '');
        $postalCode = (string) ($address['postal_code'] ?? '');

        $cacheKey = self::CACHE_PREFIX.($destinationId !== ''
            ? $driver.':'.$destinationId
            : md5($label.'|'.$city.'|'.$state.'|'.$postalCode));

        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached !== self::MISS && $this->isValidPinPoint($cached) ? $cached : null;
        }

        $geocoded = $this->geocode($label, $city, $state, $postalCode);

        if ($geocoded !== null && $this->isValidPinPoint($geocoded)) {
            Cache::put($cacheKey, $geocoded, now()->addDays(30));

            return $geocoded;
        }

        Cache::put($cacheKey, self::MISS, now()->addHour());

        return null;
    }

    private function geocode(string $label, string $city, string $state, string $postalCode): ?string
    {
        $queries = [];

        if ($label !== '') {
            $parts = array_map('trim', explode(',', $label));
            if (count($parts) >= 3) {
                // e.g. "KEDAWUNG, CIREBON, Indonesia"
                $queries[] = $parts[0].', '.$parts[2].', Indonesia';
            }
            $queries[] = $label;
        }

        if ($city !== '') {
            $queries[] = trim($city.', '.$state.', Indonesia', ', ');
        }

        if ($postalCode !== '' && $city !== '') {
            $queries[] = "{$postalCode}, {$city}, Indonesia";
        }

        foreach (array_unique(array_filter($queries)) as $query) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'OceanMall/1.0 (shipping-delivery-geocoder)',
                ])
                    ->timeout(3)
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $query,
                        'format' => 'json',
                        'limit' => 1,
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data) && isset($data[0]['lat'], $data[0]['lon'])) {
                        $lat = round((float) $data[0]['lat'], 6);
                        $lon = round((float) $data[0]['lon'], 6);
                        $candidate = "{$lat},{$lon}";
                        if ($this->isValidPinPoint($candidate)) {
                            return $candidate;
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Geocoding fallback request failed for destination', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
