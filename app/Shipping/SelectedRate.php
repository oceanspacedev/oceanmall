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
    private const DISPLAY_ONLY = ['description', 'carrier_logo'];

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
            'id' => $row['service_code'] ?? null,
            'name' => $row['service_name'] ?? null,
            'price' => $amount,
            'service_code' => $row['service_code'] ?? null,
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
