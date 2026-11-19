<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Shipping;

use App\Models\OrderShipment;
use RuntimeException;

/**
 * The Biteship courier of a selected rate. Rates quoted by the driver carry
 * `courier_company` (Biteship courier_code, e.g. "jne") and `courier_type`
 * (courier_service_code, e.g. "reg") — exactly what POST /v1/orders needs —
 * and a service code "<courier_company>:<courier_type>". Rows from older
 * sessions are parsed from that service code, then from the shipment's
 * carrier / service columns.
 */
final class BiteshipRate
{
    /**
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    public static function normalize(array $rate, int $cost): array
    {
        $courier = self::courier($rate, null, null);

        return [
            ...$rate,
            'provider' => BiteshipShippingDriver::CODE,
            'courier_company' => $courier['courier_company'] ?? null,
            'courier_type' => $courier['courier_type'] ?? null,
            'shipping_cost' => $cost,
            'currency' => 'IDR',
        ];
    }

    /**
     * @return array{courier_company: string, courier_type: string}
     */
    public static function fromShipment(OrderShipment $shipment): array
    {
        $rate = data_get($shipment->metadata, 'rate');
        $courier = self::courier(
            is_array($rate) ? $rate : [],
            is_scalar($shipment->carrier_code) ? (string) $shipment->carrier_code : null,
            is_scalar($shipment->service_code) ? (string) $shipment->service_code : null,
        );

        if ($courier === null) {
            throw new RuntimeException('Shipment has no Biteship courier (courier_company / courier_type) to register.');
        }

        return $courier;
    }

    /**
     * @param  array<string, mixed>  $rate
     * @return array{courier_company: string, courier_type: string}|null
     */
    private static function courier(array $rate, ?string $carrierCode, ?string $serviceCode): ?array
    {
        $company = self::code($rate['courier_company'] ?? null);
        $type = self::code($rate['courier_type'] ?? null);

        foreach ([$rate['service_code'] ?? null, $rate['id'] ?? null, $serviceCode] as $candidate) {
            if (($company === null || $type === null) && is_string($candidate) && str_contains($candidate, ':')) {
                [$left, $right] = explode(':', $candidate, 2);
                $company ??= self::code($left);
                $type ??= self::code($right);
            }
        }

        $company ??= self::code($rate['carrier_code'] ?? null) ?? self::code($carrierCode);
        $type ??= self::code($serviceCode);

        return $company !== null && $type !== null
            ? ['courier_company' => $company, 'courier_type' => $type]
            : null;
    }

    private static function code(mixed $value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return $value !== '' && preg_match('/^[a-z0-9_.-]+$/', $value) === 1 ? $value : null;
    }
}
