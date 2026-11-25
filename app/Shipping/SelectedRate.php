<?php

declare(strict_types=1);

namespace App\Shipping;

/**
 * The checkout-session form of a rate the customer picked (web
 * CheckoutController, mobile API, split shipments). Built from one row of
 * App\Actions\Checkout\FetchDeliveryRates, it is what CreateOrder stores as
 * order_shipments.metadata.rate and what a driver's NormalizesSelectedRates /
 * FulfillsShipments reads back at fulfilment.
 *
 * Generic fields are normalised; every other key of the row (the details a
 * driver attached with ShippingQuoteContext::describe(), e.g. a provider
 * tariff group or a rate id) is passed through untouched, so a new provider
 * needs no change here or in the controllers. Display-only keys are dropped.
 */
final class SelectedRate
{
    /** Keys only the rate picker shows; never needed after selection. */
    private const DISPLAY_ONLY = ['description', 'carrier_logo', 'provider_service_code'];

    /**
     * Option key of a provider rate when several providers quote at once:
     * '{chain owner}|{driver service code}' (FetchDeliveryRates).
     */
    public static function optionKey(string $owner, int|string $serviceCode): string
    {
        return $owner.'|'.$serviceCode;
    }

    /**
     * The option key a stored selection was picked by (what the rate list
     * and the client know it as). Selections stored before option keys
     * existed fall back to their service code.
     *
     * @param  array<string, mixed>  $rate
     */
    public static function optionKeyOf(array $rate): int|string|null
    {
        $key = $rate['option_key'] ?? $rate['id'] ?? $rate['service_code'] ?? null;

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * The FetchDeliveryRates row a client picked: by option key, else (a
     * client holding a driver code, e.g. a list rendered before a second
     * provider was switched on) the one row with that driver code. Never a
     * guess between providers.
     *
     * @param  iterable<mixed>  $rows
     * @return array<string, mixed>|null
     */
    public static function pick(iterable $rows, mixed $key): ?array
    {
        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $byCode = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ((string) ($row['service_code'] ?? '') === (string) $key) {
                return $row;
            }

            if (array_key_exists('provider_service_code', $row) && (string) $row['provider_service_code'] === (string) $key) {
                $byCode[] = $row;
            }
        }

        return count($byCode) === 1 ? $byCode[0] : null;
    }

    /**
     * The quoting driver's own service code of a FetchDeliveryRates row (what
     * order_shipments.service_code stores and drivers read back), never the
     * namespaced option key.
     *
     * @param  array<string, mixed>  $row
     */
    public static function serviceCode(array $row): int|string|null
    {
        $code = $row['provider_service_code'] ?? $row['service_code'] ?? null;

        // Zone options keep their integer CarrierOption id (the mobile API
        // echoes back what it returned).
        return (is_int($code) || is_string($code)) && (string) $code !== '' ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $row  One FetchDeliveryRates row
     * @return array<string, mixed>
     */
    public static function fromQuote(array $row): array
    {
        $details = array_filter(
            array_diff_key($row, array_flip(self::DISPLAY_ONLY)),
            static fn (mixed $value): bool => $value === null || is_scalar($value) || is_array($value),
        );

        $amount = (int) ($row['amount'] ?? 0);

        return [
            ...$details,
            'id' => self::serviceCode($row),
            'name' => $row['service_name'] ?? null,
            'price' => $amount,
            'service_code' => self::serviceCode($row),
            // The key the customer picked it by (namespaced when several
            // providers quote): what checkout echoes back as the selection.
            'option_key' => $row['service_code'] ?? null,
            'service_name' => $row['service_name'] ?? null,
            'carrier_code' => $row['carrier_code'] ?? null,
            'carrier_name' => $row['carrier_name'] ?? null,
            // Shopper carrier the rate belongs to (order_shipments.carrier_id).
            'carrier_id' => isset($row['carrier_id']) && is_numeric($row['carrier_id']) ? (int) $row['carrier_id'] : null,
            'shipping_name' => $row['shipping_name'] ?? $row['carrier_name'] ?? null,
            'shipping_cost' => (int) ($row['shipping_cost'] ?? $amount),
            // Shopper shipping driver that quoted the rate (order_shipments.driver).
            'driver' => $row['driver'] ?? null,
            'amount' => $amount,
            'currency' => $row['currency'] ?? 'IDR',
            'estimated_days' => $row['estimated_days'] ?? null,
        ];
    }
}
