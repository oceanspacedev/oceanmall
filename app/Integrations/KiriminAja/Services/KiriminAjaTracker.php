<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Services;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\KiriminAja\Exceptions\KiriminAjaException;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Integrations\KiriminAja\Support\KiriminAjaStatus;
use App\Integrations\KiriminAja\Support\KiriminAjaTrackingMemo;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Shopper\Core\Models\Order;
use Shopper\Shipping\DataTransferObjects\TrackingEvent;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Throwable;

/**
 * Reads a KiriminAja parcel through the Tracking API
 * (POST /api/mitra/tracking, by partner order id or AWB) and records it on
 * the shipment:
 *
 * - the AWB, once KiriminAja has one (it is issued after the pickup request
 *   is paid / processed, often only after the request itself);
 * - the customer timeline under the neutral metadata.tracking_history;
 * - the raw answer and status under metadata.kiriminaja.
 *
 * Used by the driver's TracksShipments / ReconcilesShipments and by the
 * callback controller, so every state change is read from the API with the
 * store's own key, never taken from a callback body.
 */
final readonly class KiriminAjaTracker
{
    public function __construct(
        private KiriminAjaClient $client,
        private NormalizeShipmentStatus $normalizeStatus,
        private KiriminAjaTrackingMemo $memo,
    ) {}

    /**
     * Fetch and record the tracking of a shipment. Null when KiriminAja does
     * not know the parcel (yet).
     *
     * @param  bool  $reuse  Take the answer reconcile() fetched a moment ago in
     *                       this request / job (KiriminAjaTrackingMemo)
     * @return array{info: TrackingInfo, status: string}|null
     */
    public function refresh(OrderShipment $shipment, bool $reuse = false): ?array
    {
        $identifier = self::identifier($shipment);

        if ($identifier === null) {
            throw new KiriminAjaException('This shipment is not registered with KiriminAja yet (no order id or AWB).');
        }

        $response = ($reuse ? $this->memo->take($identifier) : null) ?? $this->client->tracking($identifier);

        if (($response['status'] ?? false) !== true) {
            return null;
        }

        if (! $reuse) {
            $this->memo->put($identifier, $response);
        }

        $this->assertSameParcel($shipment, $response);

        $info = $this->trackingInfo($response, self::awb($shipment) ?? $identifier);
        $this->record($shipment, $response, $info);

        return ['info' => $info, 'status' => $info->status];
    }

    /**
     * Refresh, then move the shipment's own status (and the order's shipping
     * badges) to what KiriminAja reports. A cancelled shipment stays
     * cancelled; a returned / problem parcel keeps its current status.
     */
    public function reconcile(OrderShipment $shipment): OrderShipment
    {
        $result = $this->refresh($shipment);
        $shipment->refresh();

        if ($result === null) {
            return $shipment;
        }

        $current = is_string($shipment->status) ? $shipment->status : null;

        if (! in_array($result['status'], [KiriminAjaStatus::RETURNED, KiriminAjaStatus::PROBLEM], true)) {
            $status = in_array($result['status'], NormalizeShipmentStatus::STABLE, true)
                ? $result['status']
                : $this->normalizeStatus->handle($result['status'], $current);

            if ($status !== null && NormalizeShipmentStatus::shouldTransition($current, $status) && $status !== $current) {
                $shipment->forceFill(['status' => $status])->save();
            }
        }

        $order = $shipment->order()->first();

        if ($order instanceof Order) {
            try {
                resolve(SyncOrderShippingFromShipments::class)->handle($order);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $shipment->refresh();
    }

    /**
     * Tracking of an airway bill only (Shopper's ShippingDriver::track()).
     */
    public function track(string $awb): TrackingInfo
    {
        $response = $this->client->tracking($awb);

        if (($response['status'] ?? false) !== true) {
            throw new KiriminAjaException('KiriminAja does not know airway bill '.$awb.': '.(KiriminAjaClient::text($response) ?? 'not found'), $response);
        }

        return $this->trackingInfo($response, $awb);
    }

    /**
     * The partner order id this store registered, else the AWB.
     */
    public static function identifier(OrderShipment $shipment): ?string
    {
        $attempted = data_get($shipment->metadata, 'kiriminaja.order_id');

        return self::reference($shipment)
            ?? self::awb($shipment)
            ?? (is_string($attempted) && trim($attempted) !== '' ? trim($attempted) : null);
    }

    /**
     * The provider order number, only when it is KiriminAja's: as the
     * fallback tracker of a shipment another active module registered
     * (Biteship, Komerce), external_reference is that provider's id, so
     * only the AWB is ours to look up.
     */
    public static function reference(OrderShipment $shipment): ?string
    {
        $owned = $shipment->driver === KiriminAjaDriver::CODE
            || filled(data_get($shipment->metadata, 'kiriminaja.order_id'));

        return $owned ? ShipmentMetadata::externalReference($shipment) : null;
    }

    public static function awb(OrderShipment $shipment): ?string
    {
        foreach ([$shipment->awb, $shipment->tracking_number] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertSameParcel(OrderShipment $shipment, array $response): void
    {
        $reference = self::reference($shipment);
        $orderId = data_get($response, 'details.order_id');

        if ($reference !== null && is_scalar($orderId) && trim((string) $orderId) !== ''
            && strcasecmp(trim((string) $orderId), $reference) !== 0) {
            throw new KiriminAjaException(sprintf('KiriminAja tracking answered for order %s instead of %s.', (string) $orderId, $reference));
        }

        $localAwb = self::awb($shipment);
        $providerAwb = data_get($response, 'details.awb');

        if ($reference === null && $localAwb !== null && is_scalar($providerAwb) && trim((string) $providerAwb) !== ''
            && strcasecmp(trim((string) $providerAwb), $localAwb) !== 0) {
            throw new KiriminAjaException('KiriminAja tracking answered for another airway bill.');
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function trackingInfo(array $response, string $fallbackNumber): TrackingInfo
    {
        $details = is_array($response['details'] ?? null) ? $response['details'] : [];
        $awb = is_scalar($details['awb'] ?? null) && trim((string) $details['awb']) !== '' ? trim((string) $details['awb']) : null;
        $text = is_scalar($response['text'] ?? null) ? trim((string) $response['text']) : null;
        $events = $this->events($response);

        $status = KiriminAjaStatus::stable(
            $response['status_code'] ?? null,
            delivered: ($details['delivered'] ?? false) === true,
            refunded: ($details['refunded'] ?? false) === true,
            hasAwb: $awb !== null,
            text: $text,
            shipped: filled($details['shipped_at'] ?? null),
        );

        $deliveredAt = null;

        if ($status === NormalizeShipmentStatus::DELIVERED) {
            $deliveredAt = self::date($details['delivered_at'] ?? null)
                ?? ($events !== [] ? $events[0]->occurredAt : now());
        }

        return new TrackingInfo(
            trackingNumber: $awb ?? $fallbackNumber,
            status: $status,
            statusDescription: $text !== '' ? $text : null,
            deliveredAt: $deliveredAt,
            events: $events,
        );
    }

    /**
     * Histories as KiriminAja orders them (newest first).
     *
     * @param  array<string, mixed>  $response
     * @return list<TrackingEvent>
     */
    private function events(array $response): array
    {
        $events = [];

        foreach ((array) ($response['histories'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $description = is_scalar($entry['status'] ?? null) ? trim((string) $entry['status']) : '';

            $events[] = new TrackingEvent(
                status: is_scalar($entry['status_code'] ?? null) ? (string) $entry['status_code'] : $description,
                description: $description,
                occurredAt: self::date($entry['created_at'] ?? null) ?? now(),
                location: null,
            );
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function record(OrderShipment $shipment, array $response, TrackingInfo $info): void
    {
        $shipment->refresh();
        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : [];
        $details = is_array($response['details'] ?? null) ? $response['details'] : [];
        $awb = is_scalar($details['awb'] ?? null) ? trim((string) $details['awb']) : '';

        unset($details['images']);

        $bucket['tracking'] = ['status_code' => $response['status_code'] ?? null, 'text' => $response['text'] ?? null, 'details' => $details];
        $bucket['tracking_status'] = $info->statusDescription;
        $bucket['tracked_at'] = now()->toIso8601String();

        if ($awb !== '') {
            $bucket['awb'] = $awb;
        }

        $metadata['kiriminaja'] = $bucket;

        $history = array_map(static fn (TrackingEvent $event): array => [
            'description' => $event->description !== '' ? $event->description : $event->status,
            'datetime' => $event->occurredAt->format('Y-m-d H:i'),
            'location' => $event->location,
        ], $info->events);

        if ($history !== []) {
            $metadata[ShipmentMetadata::TRACKING_HISTORY] = $history;
        }

        $attributes = ['metadata' => $metadata];

        // A registration whose answer was lost (crash after the request): the
        // partner order id we sent is confirmed by the API.
        $sent = $bucket['order_id'] ?? null;
        $found = $details['order_id'] ?? null;

        if (ShipmentMetadata::externalReference($shipment) === null && is_string($sent) && is_scalar($found)
            && strcasecmp(trim((string) $found), $sent) === 0) {
            $attributes['external_reference'] = $sent;

            if (! filled($shipment->getAttribute('driver'))) {
                $attributes['driver'] = KiriminAjaDriver::CODE;
            }
        }

        // The AWB comes from the authenticated API answer only.
        if ($awb !== '' && self::awb($shipment) !== $awb) {
            $attributes['awb'] = $awb;
            $attributes['tracking_number'] = $awb;
        }

        $shipment->forceFill($attributes)->save();
    }

    private static function date(mixed $value): ?DateTimeInterface
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, 'Asia/Jakarta');
        } catch (Throwable) {
            return null;
        }
    }
}
