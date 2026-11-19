<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Services;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\Biteship\Support\BiteshipShipments;
use App\Integrations\Biteship\Support\BiteshipStatus;
use App\Integrations\Biteship\Support\BiteshipTrackingHistory;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use RuntimeException;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;

/**
 * Tracking of one shipment through Biteship (BiteshipShippingDriver::trackShipment):
 *
 * - a parcel this module registered (Biteship order id in
 *   external_reference): GET /v1/trackings/{tracking_id}, the tracking id
 *   being read from the stored order (or re-read with GET /v1/orders/{id});
 * - an AWB staff typed in by hand (manual carrier): the public
 *   GET /v1/trackings/{waybill}/couriers/{courier_code}, with the courier
 *   code from the shipment's carrier.
 *
 * Stores the raw answer under metadata.biteship.tracking and the
 * customer-facing timeline under the neutral metadata.tracking_history.
 * Returns a TrackingInfo whose status is already one of
 * NormalizeShipmentStatus::STABLE when Biteship's status is known, so
 * App\Actions\Shipping\RefreshShipmentTracking stores it as is.
 */
final readonly class BiteshipShipmentTracker
{
    public function __construct(private BiteshipClient $client) {}

    public function track(OrderShipment $shipment): TrackingInfo
    {
        $response = $this->lookup($shipment);
        $waybill = $this->scalar($response['waybill_id'] ?? null) ?? $this->airwayBill($shipment);

        if ($waybill === null) {
            throw new RuntimeException('This shipment has no airway bill (AWB) to track yet.');
        }

        $local = $this->airwayBill($shipment);

        if ($local !== null && $this->scalar($response['waybill_id'] ?? null) !== null
            && strcasecmp($local, (string) $response['waybill_id']) !== 0
            && BiteshipShipments::orderId($shipment) === null) {
            throw new RuntimeException('Biteship tracking returned a different airway bill.');
        }

        $entries = is_array($response['history'] ?? null) ? $response['history'] : [];
        $rawStatus = $this->scalar($response['status'] ?? null);
        $status = BiteshipStatus::normalize($rawStatus);

        // RefreshShipmentTracking stores a stable status as is, so the
        // forward-only rule of the reconcile is applied here too: a stale or
        // lagging tracking answer (e.g. "allocated" after the webhook already
        // moved the parcel in transit, or anything after a cancellation) hands
        // back the current status instead of moving it backwards.
        $current = is_string($shipment->status) ? strtolower(trim($shipment->status)) : '';

        if ($status !== null && ! BiteshipStatus::shouldReplace($current, $status)
            && in_array($current, NormalizeShipmentStatus::STABLE, true)) {
            $status = $current;
        }

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['biteship'] ?? null) ? $metadata['biteship'] : [];
        $bucket['tracking'] = $response;
        $bucket['tracking_status'] = $rawStatus;
        $bucket['tracked_at'] = now()->toIso8601String();

        if (($trackingId = $this->scalar($response['id'] ?? null)) !== null && BiteshipShipments::orderId($shipment) !== null) {
            $bucket['tracking_id'] ??= $trackingId;
        }

        $metadata['biteship'] = $bucket;
        $history = BiteshipTrackingHistory::fromEntries($entries);

        if ($history !== []) {
            $metadata[ShipmentMetadata::TRACKING_HISTORY] = $history;
        }

        $shipment->forceFill(['metadata' => $metadata])->save();

        $events = BiteshipTrackingHistory::events($entries);

        return new TrackingInfo(
            trackingNumber: $waybill,
            // A known Biteship status is handed over already stable; an
            // unknown one goes through NormalizeShipmentStatus, which keeps
            // the current status for words it does not know.
            status: $status ?? (string) $rawStatus,
            statusDescription: $rawStatus,
            deliveredAt: $status === NormalizeShipmentStatus::DELIVERED
                ? ($events !== [] ? $events[array_key_last($events)]->occurredAt : now())
                : null,
            events: $events,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lookup(OrderShipment $shipment): array
    {
        // Another provider's order number is never sent to Biteship: such a
        // shipment is tracked by its waybill like a hand-entered AWB.
        $orderId = BiteshipShipments::orderId($shipment);

        if ($orderId !== null) {
            $trackingId = $this->scalar(data_get($shipment->metadata, 'biteship.tracking_id'));

            if ($trackingId === null) {
                $trackingId = $this->scalar(data_get($this->client->order($orderId), 'courier.tracking_id'));
            }

            if ($trackingId !== null) {
                return $this->client->tracking($trackingId);
            }
        }

        $waybill = $this->airwayBill($shipment);

        if ($waybill === null) {
            throw new RuntimeException('This shipment has no airway bill (AWB) to track yet.');
        }

        $courier = $this->courierCode($shipment);

        if ($courier === null) {
            throw new RuntimeException('This shipment has no courier code to track its airway bill with Biteship.');
        }

        return $this->client->publicTracking($waybill, $courier);
    }

    private function airwayBill(OrderShipment $shipment): ?string
    {
        foreach ([$shipment->awb, $shipment->tracking_number, data_get($shipment->metadata, 'biteship.waybill_id')] as $candidate) {
            if (($value = $this->scalar($candidate)) !== null) {
                return $value;
            }
        }

        return null;
    }

    private function courierCode(OrderShipment $shipment): ?string
    {
        foreach ([
            data_get($shipment->metadata, 'biteship.courier_company'),
            data_get($shipment->metadata, 'rate.courier_company'),
            $shipment->carrier_code,
        ] as $candidate) {
            $code = strtolower((string) $this->scalar($candidate));

            if ($code !== '' && preg_match('/^[a-z0-9_.-]+$/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
