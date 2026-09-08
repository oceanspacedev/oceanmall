<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DestinationCoordinateResolver
{
    /**
     * Resolve a comma-separated "latitude,longitude" pinpoint string from address data.
     *
     * @param  array<string, mixed>  $address
     * @param  string|null  $fallbackPin
     * @return string|null
     */
    public function resolve(array $address, ?string $fallbackPin = null): ?string
    {
        // 1. Check direct pinpoint if already provided
        $pin = $address['rajaongkir_pin_point'] ?? $address['pin_point'] ?? null;
        if (is_string($pin) && $this->isValidPinPoint($pin)) {
            return trim($pin);
        }

        // 2. Check individual latitude and longitude attributes
        $lat = $address['latitude'] ?? null;
        $lng = $address['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            $candidate = $lat.','.$lng;
            if ($this->isValidPinPoint($candidate)) {
                return $candidate;
            }
        }

        // 3. Fallback to geocoding destination subdistrict / city
        $destinationId = (string) ($address['rajaongkir_destination_id'] ?? $address['destination_id'] ?? '');
        $label = (string) ($address['rajaongkir_destination_label'] ?? '');
        $city = (string) ($address['city'] ?? '');
        $state = (string) ($address['state'] ?? '');
        $postalCode = (string) ($address['postal_code'] ?? '');

        $cacheKey = 'rajaongkir_pinpoint_v1:'.($destinationId !== '' ? $destinationId : md5($label.$city.$postalCode));

        $geocoded = Cache::remember($cacheKey, now()->addDays(30), function () use ($label, $city, $state, $postalCode): ?string {
            return $this->geocode($label, $city, $state, $postalCode);
        });

        if ($geocoded !== null && $this->isValidPinPoint($geocoded)) {
            return $geocoded;
        }

        // 4. Last-resort fallback to warehouse origin pin if provided
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
