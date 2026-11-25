<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\DTO\AllocationPlan;
use App\DTO\ShipmentDraft;
use App\Support\OperatorSwitch;

/**
 * The shipping rate the customer picked for each package (shipment) of an
 * allocation plan, read from a checkout session / app checkout state.
 *
 * A rate picked per package (shipping_options_by_shipment, keyed by
 * inventory id) wins. The single shipping_option is a package's rate only
 * when the plan has one package, it was quoted from that package's warehouse
 * (when it says so) and no per-package rates exist: next to per-package rates
 * it is just their summary. A plan whose every package has a rate is covered;
 * an order is never placed for a package without one (it would ship for Rp0),
 * nor at a rate of a provider the store no longer sells through.
 *
 * A rate saved with stamp() records the package it was quoted for (warehouse
 * and lines): once the plan changes that package (lines moved in or out of
 * the warehouse, a split plan merged), the rate no longer counts for it,
 * since its weight and item value, and so its price, were another package's.
 */
final class SelectedShipmentRates
{
    /**
     * Rate key holding the fingerprint of the package the rate was quoted for.
     */
    public const string QUOTED_FOR = 'quoted_for';

    /**
     * The rate as picked for this package: tagged with its warehouse and the
     * package it was quoted for.
     *
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    public function stamp(array $rate, ShipmentDraft $shipmentDraft): array
    {
        return [
            ...$rate,
            'inventory_id' => $shipmentDraft->inventory_id,
            self::QUOTED_FOR => self::fingerprint($shipmentDraft),
        ];
    }

    /**
     * @return array<string, mixed> The selected rate, or [] when the package has none
     */
    public function forShipment(ShipmentDraft $shipmentDraft, mixed $checkout, int $shipmentCount): array
    {
        $ratesByShipment = data_get($checkout, 'shipping_options_by_shipment', []);

        if (is_array($ratesByShipment)) {
            foreach ([
                $shipmentDraft->inventory_id,
                (string) $shipmentDraft->inventory_id,
            ] as $key) {
                if (array_key_exists($key, $ratesByShipment) && is_array($ratesByShipment[$key])) {
                    return $this->quotedFor($ratesByShipment[$key], $shipmentDraft);
                }
            }

            foreach ($ratesByShipment as $rate) {
                if (
                    is_array($rate)
                    && (int) data_get($rate, 'inventory_id') === $shipmentDraft->inventory_id
                ) {
                    return $this->quotedFor($rate, $shipmentDraft);
                }
            }
        }

        if ($shipmentCount !== 1 || (is_array($ratesByShipment) && $ratesByShipment !== [])) {
            return [];
        }

        $globalRate = data_get($checkout, 'shipping_option.0');

        if (! is_array($globalRate)) {
            return [];
        }

        $quotedFrom = data_get($globalRate, 'inventory_id');

        if (is_numeric($quotedFrom) && (int) $quotedFrom !== $shipmentDraft->inventory_id) {
            return [];
        }

        return $this->quotedFor($globalRate, $shipmentDraft);
    }

    /**
     * Whether every package of the plan has a selected rate.
     */
    public function coverPlan(AllocationPlan $plan, mixed $checkout): bool
    {
        $shipmentCount = count($plan->shipments);

        foreach ($plan->shipments as $shipmentDraft) {
            $rate = $this->forShipment($shipmentDraft, $checkout, $shipmentCount);

            if ($rate === []) {
                return false;
            }

            // The store sells through one shipping provider at a time
            // (App\Support\ActiveProvider): a rate picked from a provider
            // the store moved away from since (or switched off) is picked
            // again from the current one.
            $driver = $rate['driver'] ?? null;

            if (is_string($driver) && $driver !== ''
                && OperatorSwitch::state(OperatorSwitch::SHIPPING, $driver) !== null
                && ! OperatorSwitch::on(OperatorSwitch::SHIPPING, $driver)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Same warehouse and same lines (order-insensitive).
     */
    public static function fingerprint(ShipmentDraft $shipmentDraft): string
    {
        $lines = array_map(
            static fn (array $line): string => $line['purchasable_type'].':'.(int) $line['purchasable_id'].':'.(int) $line['qty'],
            $shipmentDraft->lines,
        );
        sort($lines);

        return sha1($shipmentDraft->inventory_id.'|'.implode(',', $lines));
    }

    /**
     * The rate (without its fingerprint) when it was quoted for this package,
     * or when it predates fingerprints; [] when it was quoted for another one.
     *
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    private function quotedFor(array $rate, ShipmentDraft $shipmentDraft): array
    {
        $quotedFor = $rate[self::QUOTED_FOR] ?? null;

        if (is_string($quotedFor) && ! hash_equals($quotedFor, self::fingerprint($shipmentDraft))) {
            return [];
        }

        unset($rate[self::QUOTED_FOR]);

        return $rate;
    }
}
