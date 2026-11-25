<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Support;

use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which order shipments are Komerce's to touch. Several shipping modules can
 * be active at once (Biteship, KiriminAja, ...), and the provider-neutral
 * columns (external_reference, awb, tracking_number) hold every provider's
 * ids. Komerce code only acts on shipments its own drivers quoted or
 * registered: driver 'komerce' or 'rajaongkir' (a RajaOngkir-quoted parcel
 * is registered with Komerce Delivery), or a legacy row with no driver
 * recorded (from before drivers were stored, when Komerce was the only one)
 * that no other provider registered.
 */
final class KomerceShipments
{
    /** @var list<string> */
    public const DRIVERS = [KomerceShippingDriver::CODE, RajaOngkirDriver::CODE];

    public static function owns(OrderShipment $shipment): bool
    {
        $driver = is_string($shipment->driver) ? trim($shipment->driver) : '';

        if ($driver !== '') {
            return in_array($driver, self::DRIVERS, true);
        }

        return ! self::registeredElsewhere($shipment);
    }

    /**
     * A shipment that holds another provider's order number: on a driver-less
     * row, a reference that is not the Komerce order_no recorded in
     * metadata.komerce (before or during this save); on a row another driver
     * owns, any reference. Komerce must never book a second pickup for it.
     */
    public static function registeredElsewhere(OrderShipment $shipment): bool
    {
        $reference = ShipmentMetadata::externalReference($shipment);

        if ($reference === null) {
            return false;
        }

        $driver = is_string($shipment->driver) ? trim($shipment->driver) : '';

        if ($driver !== '') {
            return ! in_array($driver, self::DRIVERS, true);
        }

        $original = $shipment->getOriginal('metadata');
        $original = is_string($original) ? json_decode($original, true) : $original;

        foreach ([$shipment->metadata, $original] as $metadata) {
            $orderNo = data_get(is_array($metadata) ? $metadata : [], 'komerce.order_no');

            if (is_scalar($orderNo) && trim((string) $orderNo) === $reference) {
                return false;
            }
        }

        return true;
    }

    /**
     * SQL prefilter of owns() (a legacy row with both a reference and a
     * Komerce bucket still needs owns() to compare the two).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query): Builder
    {
        return $query->where(static function (Builder $where): void {
            $where->whereIn('driver', self::DRIVERS)
                ->orWhere(static function (Builder $legacy): void {
                    $legacy->where(static fn (Builder $blank): Builder => $blank->whereNull('driver')->orWhere('driver', ''))
                        ->where(static fn (Builder $unregistered): Builder => $unregistered->whereNull('external_reference')
                            ->orWhere('external_reference', '')
                            ->orWhereNotNull('metadata->komerce'));
                });
        });
    }
}
