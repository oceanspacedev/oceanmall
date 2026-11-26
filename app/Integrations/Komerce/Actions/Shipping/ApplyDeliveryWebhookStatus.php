<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Actions\Shipping;

use App\Actions\Shipping\RefreshShipmentTracking;
use App\Integrations\Komerce\Support\KomerceShipments;
use App\Models\OrderShipment;

/**
 * Apply a Komerce Shipping Delivery webhook payload
 * ({order_no, cnote, status}) onto the matching shipment.
 */
final readonly class ApplyDeliveryWebhookStatus
{
    public function __construct(
        private RefreshShipmentTracking $refreshTracking,
        private ReconcileKomerceShipment $reconcile,
    ) {}

    /**
     * @return 'handled'|'not_found'|'ignored'
     */
    public function handle(string $orderNo, ?string $cnote, ?string $status): string
    {
        $orderNo = trim($orderNo);
        $cnote = $cnote !== null ? trim($cnote) : null;
        $status = $status !== null ? trim($status) : null;

        if ($orderNo === '' && ($cnote === null || $cnote === '')) {
            return 'ignored';
        }

        $shipment = $this->findShipment($orderNo, $cnote);

        if ($shipment === null) {
            return 'not_found';
        }

        // Never trust an unauthenticated webhook's cnote over the DB or
        // overwrite AWB in memory: reconcile through the authenticated Komerce
        // detail-order API first to persist the official airway bill.
        if ($orderNo !== '') {
            if (blank($shipment->external_reference) && blank(data_get($shipment->metadata, 'komerce.order_no'))) {
                $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
                $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
                $komerce['order_no'] = $orderNo;
                $metadata['komerce'] = $komerce;
                $shipment->forceFill(['metadata' => $metadata])->save();
            }

            $shipment = $this->reconcile->handle($shipment);
        }

        $persistedAwb = $shipment->awb ?: $shipment->tracking_number ?: data_get($shipment->metadata, 'komerce.awb');

        if (is_scalar($persistedAwb) && trim((string) $persistedAwb) !== '') {
            $shipment = $this->refreshTracking->handle($shipment);
        }

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
        $komerce['webhook_reported_status'] = $status;
        $komerce['webhook_received_at'] = now()->toIso8601String();
        if ($orderNo !== '') {
            $komerce['order_no'] = $orderNo;
        }
        $metadata['komerce'] = $komerce;

        $shipment->forceFill(['metadata' => $metadata])->save();

        return 'handled';
    }

    /**
     * The shipment Komerce Delivery registered as $orderNo: the indexed
     * (driver, external_reference) columns first, where driver is the quoting
     * driver (a RajaOngkir-quoted parcel keeps 'rajaongkir' after Delivery
     * registers it); the metadata bucket only for rows the observer has not
     * mirrored yet; then the AWB, among shipments Komerce owns only: another
     * active shipping module (Biteship, KiriminAja) may hold the same AWB.
     */
    private function findShipment(string $orderNo, ?string $cnote): ?OrderShipment
    {
        if ($orderNo !== '') {
            $byOrderNo = OrderShipment::query()
                ->whereIn('driver', KomerceShipments::DRIVERS)
                ->where('external_reference', $orderNo)
                ->orderByDesc('id')
                ->first()
                ?? OrderShipment::query()
                    ->where('metadata->komerce->order_no', $orderNo)
                    ->orderByDesc('id')
                    ->first();

            if ($byOrderNo !== null) {
                return $byOrderNo;
            }
        }

        if ($cnote !== null && $cnote !== '') {
            return KomerceShipments::scope(OrderShipment::query())
                ->where(function ($query) use ($cnote): void {
                    $query->where('awb', $cnote)
                        ->orWhere('tracking_number', $cnote);
                })
                ->orderByDesc('id')
                ->get()
                ->first(static fn (OrderShipment $shipment): bool => KomerceShipments::owns($shipment));
        }

        return null;
    }
}
