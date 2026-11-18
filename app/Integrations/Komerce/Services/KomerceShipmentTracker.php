<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Services;

use App\Integrations\Komerce\Support\KomerceTrackingContext;
use App\Models\OrderShipment;
use RuntimeException;
use Shopper\Shipping\Contracts\ShippingDriver;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;

/**
 * Tracking for the Komerce drivers (Delivery `track`, Shipping Cost
 * `track/waybill`): both need the courier and the receiver phone next to the
 * AWB, and both keep the provider's raw answer and a normalized history on
 * metadata.komerce (read by the storefront timeline and the mobile API).
 *
 * Used by the drivers' TracksShipments::trackShipment(); generic code only
 * sees the returned TrackingInfo.
 */
final readonly class KomerceShipmentTracker
{
    public function __construct(private KomerceTrackingContext $context) {}

    /**
     * @throws RuntimeException when the shipment cannot be tracked or the
     *                          provider answers for another airway bill
     */
    public function track(OrderShipment $shipment, ShippingDriver $driver, ?string $courier): TrackingInfo
    {
        $awb = self::airwayBill($shipment);

        if ($awb === null) {
            throw new RuntimeException('This shipment has no airway bill (AWB) to track yet.');
        }

        if ($courier === null || trim($courier) === '') {
            throw new RuntimeException('This shipment has no courier (shipping) to track yet.');
        }

        $this->context->setLastPhoneNumber($this->receiverPhone($shipment));
        $this->context->setCourier($courier);

        try {
            $info = $driver->track($awb);
            $response = $this->context->lastRaw();
        } finally {
            $this->context->clear();
        }

        $providerAwb = data_get($response, 'data.airway_bill')
            ?? data_get($response, 'data.summary.waybill_number')
            ?? data_get($response, 'data.details.waybill_number');

        if (is_scalar($providerAwb) && trim((string) $providerAwb) !== ''
            && strcasecmp(trim((string) $providerAwb), $awb) !== 0) {
            throw new RuntimeException('RajaOngkir tracking response returned a different airway bill.');
        }

        $history = $this->normalizeHistory($response);
        $rawStatus = $info->statusDescription ?? $this->resolveRawStatus($response, $history);

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
        $komerce['tracking'] = $response;
        $komerce['tracking_history'] = $history;
        $komerce['tracking_status'] = $rawStatus;
        $komerce['tracked_at'] = now()->toIso8601String();
        $metadata['komerce'] = $komerce;

        $shipment->forceFill(['metadata' => $metadata])->save();

        return $info;
    }

    public static function airwayBill(OrderShipment $shipment): ?string
    {
        foreach ([$shipment->awb, $shipment->tracking_number, data_get($shipment->metadata, 'komerce.awb')] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * Courier as Komerce Delivery names it (shipping name, e.g. "JNE").
     */
    public static function deliveryCourier(OrderShipment $shipment): ?string
    {
        foreach ([
            $shipment->carrier_name,
            $shipment->carrier_code,
            data_get($shipment->metadata, 'komerce.shipping'),
            data_get($shipment->metadata, 'komerce.carrier_name'),
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * Courier as RajaOngkir Shipping Cost codes it (e.g. "jne").
     */
    public static function costCourier(OrderShipment $shipment): ?string
    {
        $code = strtolower(trim((string) $shipment->carrier_code));

        if ($code !== '' && preg_match('/^[a-z0-9_-]+$/', $code) === 1) {
            return $code;
        }

        $name = strtolower(trim((string) self::deliveryCourier($shipment)));

        return $name !== '' && preg_match('/^[a-z0-9_-]+$/', $name) === 1 ? $name : null;
    }

    private function receiverPhone(OrderShipment $shipment): ?string
    {
        $order = $shipment->order()->first();
        $address = $order?->shippingAddress;
        $phone = $address?->phone_number ?? $address?->phone;

        return is_scalar($phone) ? (string) $phone : null;
    }

    /**
     * Normalize whichever history/manifest list the API returns into the
     * shape the storefront timeline reads.
     *
     * @param  array<string, mixed>  $response
     * @return list<array{description: string, datetime: string|null, location: string|null}>
     */
    private function normalizeHistory(array $response): array
    {
        $entries = [];

        foreach (['data.manifest', 'data.history', 'data.histories', 'data.detail', 'data.tracking_history'] as $path) {
            $value = data_get($response, $path);

            if (is_array($value) && $value !== []) {
                $entries = $value;
                break;
            }
        }

        return collect($entries)
            ->filter(static fn (mixed $entry): bool => is_array($entry))
            ->map(static function (array $entry): array {
                $date = data_get($entry, 'manifest_date') ?? data_get($entry, 'date');
                $time = data_get($entry, 'manifest_time') ?? data_get($entry, 'time');
                $datetime = trim(implode(' ', array_filter([
                    is_scalar($date) ? (string) $date : null,
                    is_scalar($time) ? (string) $time : null,
                ])));

                $description = data_get($entry, 'manifest_description')
                    ?? data_get($entry, 'description')
                    ?? data_get($entry, 'desc')
                    ?? data_get($entry, 'status');

                $location = data_get($entry, 'city_name')
                    ?? data_get($entry, 'city')
                    ?? data_get($entry, 'location');

                return [
                    'description' => is_scalar($description) ? (string) $description : '',
                    'datetime' => $datetime !== '' ? $datetime : null,
                    'location' => is_scalar($location) ? (string) $location : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<array{description: string, datetime: string|null, location: string|null}>  $history
     */
    private function resolveRawStatus(array $response, array $history): ?string
    {
        foreach ([
            'data.delivery_status.status',
            'data.details.status',
            'data.last_status',
            'data.status',
            'data.delivery_status',
            'data.summary.status',
            'status',
        ] as $path) {
            $value = data_get($response, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $last = $history !== [] ? ($history[array_key_last($history)]['description'] ?? null) : null;

        return is_string($last) && $last !== '' ? $last : null;
    }
}
