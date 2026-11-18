<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderShipment;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use RuntimeException;
use Shopper\Shipping\DataTransferObjects\TrackingEvent;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Throwable;

/**
 * Pull the latest tracking of a shipment from the shipping driver that can
 * track it (ShippingCapabilities::trackerFor) and store the normalized status.
 *
 * - A driver that TracksShipments receives the whole shipment and records its
 *   provider details itself: raw answers in its own metadata bucket, and the
 *   customer-facing timeline under the neutral metadata.tracking_history
 *   (App\Shipping\ShipmentMetadata), which web and mobile read.
 * - Any other Shopper driver is asked with track($awb); its events are stored
 *   here as metadata.tracking_history.
 *
 * A failed lookup keeps the history already recorded instead of erroring.
 */
final readonly class RefreshShipmentTracking
{
    public function __construct(
        private ShippingCapabilities $capabilities,
        private NormalizeShipmentStatus $normalizeStatus,
        private SyncOrderShippingFromShipments $syncOrderShipping,
    ) {}

    /**
     * @throws RuntimeException When the shipment has no AWB or no driver can track it.
     */
    public function handle(OrderShipment $shipment): OrderShipment
    {
        $awb = $this->airwayBill($shipment);

        if ($awb === null) {
            throw new RuntimeException('This shipment has no airway bill (AWB) to track yet.');
        }

        $driver = $this->capabilities->trackerFor($shipment);

        if ($driver === null) {
            throw new RuntimeException('No configured shipping provider can track this airway bill.');
        }

        try {
            $info = $driver instanceof TracksShipments
                ? $driver->trackShipment($shipment)
                : $driver->track($awb);
        } catch (Throwable $e) {
            $shipment->refresh();

            if ($this->hasHistory($shipment)) {
                return $shipment;
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        $shipment->refresh();
        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];

        if (! $driver instanceof TracksShipments) {
            $metadata[ShipmentMetadata::TRACKING_HISTORY] = $this->history($info);
            $metadata['tracking_status'] = $info->statusDescription;
            $metadata['tracked_at'] = now()->toIso8601String();
            $metadata['tracked_by'] = $driver->code();
        }

        // Always the stable vocabulary (NormalizeShipmentStatus::STABLE): a driver
        // may answer with Shopper's ShipmentStatus values (out_for_delivery,
        // delivery_failed...) or its own words (dropping_off, on_hold). An
        // unknown answer keeps the current status.
        $raw = trim($info->status) !== '' ? $info->status : $info->statusDescription;
        $normalized = in_array($raw, NormalizeShipmentStatus::STABLE, true)
            ? $raw
            : $this->normalizeStatus->handle($raw, is_string($shipment->status) ? $shipment->status : null);

        $attributes = ['metadata' => $metadata];

        if ($normalized !== null) {
            $attributes['status'] = $normalized;
        }

        $shipment->forceFill($attributes)->save();

        $order = $shipment->order()->first();

        if ($order !== null) {
            $this->syncOrderShipping->handle($order);
        }

        return $shipment->refresh();
    }

    private function airwayBill(OrderShipment $shipment): ?string
    {
        $candidates = [$shipment->awb, $shipment->tracking_number];

        // Provider buckets (metadata.<provider>.awb) written before the AWB column was filled.
        foreach ((array) $shipment->metadata as $bucket) {
            if (is_array($bucket)) {
                $candidates[] = $bucket['awb'] ?? null;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function hasHistory(OrderShipment $shipment): bool
    {
        return ShipmentMetadata::trackingHistory($shipment) !== [];
    }

    /**
     * @return list<array{description: string, datetime: string|null, location: string|null}>
     */
    private function history(TrackingInfo $info): array
    {
        return array_values(array_map(static fn (TrackingEvent $event): array => [
            'description' => $event->description !== '' ? $event->description : $event->status,
            'datetime' => $event->occurredAt->format('Y-m-d H:i'),
            'location' => $event->location,
        ], $info->events));
    }
}
