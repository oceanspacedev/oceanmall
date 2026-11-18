<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Shipping;

/**
 * Maps checkout / Cost courier codes onto Shipping Delivery store-order names.
 *
 * Store Order expects `shipping` like "JNE" and `shipping_type` like "REG",
 * not the Cost API display name ("Jalur Nugraha Ekakurir") or a Shopper
 * service label stored in `name`.
 */
final class RajaOngkirCourier
{
    /**
     * Stamp a selected checkout rate with Delivery store-order field names so
     * Komerce Delivery issues the AWB; Shopper only records the result.
     * (Formerly CreateOrder::officialDeliveryRate, now reached through the
     * drivers' NormalizesSelectedRates contract.)
     *
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    public static function deliveryRate(array $rate, int $cost): array
    {
        if ($rate === [] || $cost < 1) {
            return $rate;
        }

        $string = static function (string $key) use ($rate): ?string {
            $value = $rate[$key] ?? null;

            return is_scalar($value) && $value !== '' ? (string) $value : null;
        };

        $carrierCode = $string('carrier_code');
        $service = $string('service_name') ?? $string('service_code') ?? $string('id');

        $rate['shipping_name'] = self::deliveryName($carrierCode, $string('carrier_name'));
        $rate['service_name'] = self::deliveryService($service);
        $rate['shipping_cost'] = $cost;
        $rate['shipping_cashback'] = (int) ($rate['shipping_cashback'] ?? 0);
        $rate['service_fee'] = (int) ($rate['service_fee'] ?? 0);

        return $rate;
    }

    public static function deliveryName(?string $carrierCode, ?string $fallbackName = null): string
    {
        $code = strtolower(trim((string) $carrierCode));

        if (str_contains($code, ':')) {
            $code = trim(explode(':', $code, 2)[0] ?? $code);
        }

        $mapped = match ($code) {
            'jne' => 'JNE',
            'jnt', 'j&t' => 'JNT',
            'sicepat' => 'SICEPAT',
            'ide', 'idexpress' => 'IDE',
            'anteraja' => 'ANTERAJA',
            'pos' => 'POS',
            'tiki' => 'TIKI',
            'lion' => 'LION',
            'ninja' => 'NINJA',
            'wahana' => 'WAHANA',
            'rpx' => 'RPX',
            'ncs' => 'NCS',
            default => '',
        };

        if ($mapped !== '') {
            return $mapped;
        }

        $fallback = strtoupper(trim((string) $fallbackName));

        if ($fallback !== '' && ! in_array($fallback, ['STD', 'EZ', 'REG', 'YES', 'OKE', 'CTC', 'CTCYES', 'SIUNT', 'GOKIL', 'EXPRESS'], true)) {
            return $fallback;
        }

        return strtoupper($code);
    }

    public static function deliveryService(?string $service): string
    {
        $service = trim((string) $service);

        if (str_contains($service, ':')) {
            $service = trim(explode(':', $service, 2)[1] ?? $service);
        }

        return strtoupper($service);
    }
}
