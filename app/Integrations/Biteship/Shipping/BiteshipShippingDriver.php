<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Shipping;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\Biteship\Actions\ReconcileBiteshipShipment;
use App\Integrations\Biteship\Biteship;
use App\Integrations\Biteship\Exceptions\BiteshipApiException;
use App\Integrations\Biteship\Services\BiteshipCarrierSync;
use App\Integrations\Biteship\Services\BiteshipClient;
use App\Integrations\Biteship\Services\BiteshipFulfillment;
use App\Integrations\Biteship\Services\BiteshipShipmentTracker;
use App\Integrations\Biteship\Support\BiteshipCouriers;
use App\Integrations\Biteship\Support\BiteshipShipments;
use App\Integrations\Biteship\Support\BiteshipStatus;
use App\Models\OrderShipment;
use App\Shipping\CarrierSyncResult;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\NormalizesSelectedRates;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\DestinationCoordinateResolver;
use App\Shipping\ShippingQuoteContext;
use App\Shipping\ShippingQuoteFailure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\DataTransferObjects\Shipment;
use Shopper\Shipping\DataTransferObjects\ShippingRate;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Exceptions\ShippingException;
use Throwable;

/**
 * Shopper shipping driver for Biteship (https://biteship.com/id/docs/api):
 *
 *   rates        POST /v1/rates/couriers (area ids, postal-code fallback)
 *   destinations GET  /v1/maps/areas (area id = destination_id, also the warehouse origin)
 *   carriers     GET  /v1/couriers (shipping:sync-carriers)
 *   fulfilment   POST /v1/orders (pickup + waybill), reconcile GET /v1/orders/{id}
 *   tracking     GET  /v1/trackings/{id} or /v1/trackings/{waybill}/couriers/{code}
 *   cancel       POST /v1/orders/{id}/cancel
 *
 * Biteship has no label / print API (labels are printed from its dashboard),
 * so the driver does not implement PrintsLabels and the cpanel keeps its
 * built-in thermal label. Instant couriers are left out (BiteshipCouriers),
 * so the driver does not implement RequiresCoordinates either.
 *
 * Plug and play: isConfigured() is Biteship::configured() (key present and
 * allowed for this environment). Keyless, every capability is off and
 * nothing here is ever called by generic code.
 */
final class BiteshipShippingDriver extends Driver implements CancelsShipments, FulfillsShipments, NormalizesSelectedRates, ReconcilesShipments, RequiresDestination, ResolvesDestinations, SyncsCarriers, TracksShipments
{
    public const CODE = Biteship::CODE;

    /**
     * The driver is memoised by Shopper's ShippingManager across requests and
     * jobs (Octane): it holds no request state, contexts are resolved per call.
     */
    public function __construct(private readonly BiteshipClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Biteship';
    }

    public function isConfigured(): bool
    {
        return Biteship::configured();
    }

    public function supportsRealTimeRates(): bool
    {
        return true;
    }

    public function supportsLabels(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    /**
     * Area search for address forms. Areas are static and every live Maps
     * call counts against the plan quota, so each query is remembered for
     * 30 days.
     *
     * @return list<array<string, mixed>>
     */
    public function searchDestinations(string $query, int $limit = 10): array
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $query = mb_strtolower(trim($query));
        $limit = max(1, min($limit, 50));

        if (mb_strlen($query) < 2) {
            return [];
        }

        $rows = Cache::remember(
            'biteship:areas:v1:'.sha1($query),
            now()->addDays(30),
            fn (): array => $this->areaRows($this->client->searchAreas($query)),
        );

        return array_slice($rows, 0, $limit);
    }

    /**
     * The warehouse's Biteship area: the single area of its 5-digit postal
     * code, narrowed by its city when the postal code spans several areas.
     */
    public function resolveOrigin(Inventory $inventory): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $postalCode = trim((string) $inventory->getAttribute('postal_code'));

        if (preg_match('/^\d{5}$/', $postalCode) !== 1) {
            return null;
        }

        try {
            $rows = array_values(array_filter(
                $this->searchDestinations($postalCode, 50),
                static fn (array $row): bool => ($row['postal_code'] ?? null) === $postalCode,
            ));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (count($rows) > 1) {
            $city = mb_strtolower(trim((string) $inventory->getAttribute('city')));
            $rows = $city === '' ? $rows : array_values(array_filter(
                $rows,
                static fn (array $row): bool => str_contains(mb_strtolower((string) ($row['city'] ?? '')), $city)
                    || str_contains(mb_strtolower((string) ($row['district'] ?? '')), $city),
            ));
        }

        return count($rows) === 1 ? ['id' => (string) $rows[0]['id'], 'label' => $rows[0]['label'] ?? null] : null;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $context = resolve(ShippingQuoteContext::class);

        // Called directly (Shopper's CarrierRateService, third-party code):
        // derive warehouse / area id / couriers from the Address DTO.
        $derived = $context->beginFromAddress($to, $this, $this->code(), [$this->code()]);

        try {
            return $this->quote($context, $packages);
        } finally {
            if ($derived) {
                $context->end();
            }
        }
    }

    /**
     * @param  array<int, Package>  $packages
     * @return Collection<int, ShippingRate>
     */
    private function quote(ShippingQuoteContext $context, array $packages): Collection
    {
        $inventory = $context->origin();
        $address = $context->address();

        $origin = $this->location('origin', $context->originId($this->code()), $inventory?->getAttribute('postal_code'));
        $destination = $this->location('destination', $context->destinationId($this->code()), $address['postal_code'] ?? null);

        if ($origin === null || $destination === null) {
            return collect();
        }

        $couriers = array_values(array_filter(
            $context->couriers(),
            static fn (string $code): bool => ! BiteshipCouriers::isInstant($code) && preg_match('/^[a-z0-9_.-]+$/', $code) === 1,
        ));

        // Biteship needs an explicit courier list: no enabled carrier of this
        // driver (run shipping:sync-carriers) means nothing to quote.
        if ($couriers === []) {
            return collect();
        }

        sort($couriers);
        $grams = $this->weightGrams($packages);
        $payload = [
            ...$origin,
            ...$destination,
            ...$this->coordinates($inventory, $context->pinPoint()),
            'couriers' => implode(',', $couriers),
            'items' => [[
                'name' => 'Paket',
                'value' => max(0, $context->itemValue()),
                'quantity' => 1,
                'weight' => $grams,
            ]],
        ];

        $hours = (int) config('biteship.rate_cache_hours', 12);
        $key = 'biteship:rates:v1:'.sha1(json_encode([$origin, $destination, $grams, $couriers], JSON_THROW_ON_ERROR));

        try {
            $response = $hours > 0
                ? Cache::remember($key, now()->addHours($hours), fn (): array => $this->client->rates($payload))
                : $this->client->rates($payload);
        } catch (BiteshipApiException $e) {
            if ($e->isQuota()) {
                $context->markFailure($this->code(), ShippingQuoteFailure::QUOTA, $e->getMessage());

                return collect();
            }

            throw ShippingException::apiError($this->code(), $e->getMessage());
        } catch (Throwable $e) {
            throw ShippingException::apiError($this->code(), $e->getMessage());
        }

        return $this->rates($response, $context, $couriers);
    }

    /**
     * Area id first; the 5-digit postal code when no area id is known (an
     * address saved before Biteship was plugged in, or a warehouse whose
     * origin was not filled in yet).
     *
     * @return array<string, int|string>|null
     */
    private function location(string $side, ?string $areaId, mixed $postalCode): ?array
    {
        if ($areaId !== null && trim($areaId) !== '') {
            return ["{$side}_area_id" => trim($areaId)];
        }

        $postalCode = is_scalar($postalCode) ? trim((string) $postalCode) : '';

        return preg_match('/^\d{5}$/', $postalCode) === 1 ? ["{$side}_postal_code" => (int) $postalCode] : null;
    }

    /**
     * Coordinates are only sent when the customer pinned the address and the
     * warehouse has latitude / longitude; regular couriers do not need them.
     *
     * @return array<string, float>
     */
    private function coordinates(?Inventory $inventory, ?string $pin): array
    {
        $lat = $inventory?->getAttribute('latitude');
        $lng = $inventory?->getAttribute('longitude');

        if (! is_numeric($lat) || ! is_numeric($lng) || ! is_string($pin)
            || ! resolve(DestinationCoordinateResolver::class)->isValidPinPoint($pin)) {
            return [];
        }

        [$destinationLat, $destinationLng] = array_map(static fn (string $part): float => (float) trim($part), explode(',', $pin));

        return [
            'origin_latitude' => (float) $lat,
            'origin_longitude' => (float) $lng,
            'destination_latitude' => $destinationLat,
            'destination_longitude' => $destinationLng,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $couriers
     * @return Collection<int, ShippingRate>
     */
    private function rates(array $response, ShippingQuoteContext $context, array $couriers): Collection
    {
        $rates = collect();

        foreach ((array) ($response['pricing'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $company = strtolower(trim((string) ($row['courier_code'] ?? $row['company'] ?? '')));
            $type = strtolower(trim((string) ($row['courier_service_code'] ?? $row['type'] ?? '')));
            $price = $row['price'] ?? null;

            if ($company === '' || $type === '' || ! is_numeric($price) || (int) $price < 1
                || ! in_array($company, $couriers, true) || $context->isExcluded($company)) {
                continue;
            }

            $serviceCode = "{$company}:{$type}";
            $serviceName = trim((string) ($row['courier_service_name'] ?? '')) ?: strtoupper($type);

            $rates->push(new ShippingRate(
                serviceCode: $serviceCode,
                serviceName: $serviceName,
                // IDR is zero-decimal: Biteship prices are whole rupiah.
                amount: (int) $price,
                currency: 'IDR',
                carrierCode: $company,
                estimatedDays: $this->days($row),
            ));

            $context->describe($this->code(), $serviceCode, [
                'carrier_name' => trim((string) ($row['courier_name'] ?? '')) ?: (BiteshipCouriers::name($company) ?? strtoupper($company)),
                'provider' => self::CODE,
                'courier_company' => $company,
                'courier_type' => $type,
                'shipping_fee' => is_numeric($row['shipping_fee'] ?? null) ? (int) $row['shipping_fee'] : null,
                'service_type' => is_scalar($row['service_type'] ?? null) ? (string) $row['service_type'] : null,
            ]);
        }

        return $rates;
    }

    /**
     * "1 - 2" days → "1-2"; hour ranges are not day estimates.
     *
     * @param  array<string, mixed>  $row
     */
    private function days(array $row): ?string
    {
        $unit = strtolower(trim((string) ($row['shipment_duration_unit'] ?? 'days')));
        $range = trim((string) ($row['shipment_duration_range'] ?? ''));

        if ($range === '' || ! str_starts_with($unit, 'day')) {
            return null;
        }

        return (string) preg_replace('/\s*-\s*/', '-', $range);
    }

    /**
     * @param  array<int, Package>  $packages
     */
    private function weightGrams(array $packages): int
    {
        $grams = 0;

        foreach ($packages as $package) {
            $weight = max(0.0, $package->weight);
            $grams += (int) ceil($package->isImperial() ? $weight * 453.59237 : $weight * 1000);
        }

        return max(1, $grams);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function areaRows(array $response): array
    {
        $rows = [];

        foreach ((array) ($response['areas'] ?? []) as $area) {
            if (! is_array($area) || ! is_scalar($area['id'] ?? null) || trim((string) $area['id']) === '') {
                continue;
            }

            $postal = $area['postal_code'] ?? null;
            $postal = is_scalar($postal) && preg_match('/^\d{5}$/', trim((string) $postal)) === 1 ? trim((string) $postal) : null;

            $rows[] = [
                'id' => trim((string) $area['id']),
                'label' => trim((string) ($area['name'] ?? $area['id'])),
                'postal_code' => $postal,
                'city' => self::nullable($area['administrative_division_level_2_name'] ?? null),
                'state' => self::nullable($area['administrative_division_level_1_name'] ?? null),
                'district' => self::nullable($area['administrative_division_level_3_name'] ?? null),
            ];
        }

        return $rows;
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public function syncCarriers(bool $dryRun = false): CarrierSyncResult
    {
        if (! $this->isConfigured()) {
            return CarrierSyncResult::failed('Biteship is not configured.');
        }

        return resolve(BiteshipCarrierSync::class)->sync($dryRun);
    }

    public function normalizeSelectedRate(array $rate, int $cost): array
    {
        return BiteshipRate::normalize($rate, $cost);
    }

    public function fulfillShipment(OrderShipment $shipment): void
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        resolve(BiteshipFulfillment::class)->fulfill($shipment);
    }

    public function reconcileShipment(OrderShipment $shipment): OrderShipment
    {
        if (! $this->isConfigured()) {
            return $shipment;
        }

        return resolve(ReconcileBiteshipShipment::class)->handle($shipment);
    }

    public function trackShipment(OrderShipment $shipment): TrackingInfo
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        return resolve(BiteshipShipmentTracker::class)->track($shipment);
    }

    /**
     * Cancel the Biteship order behind a shipment. A no-op for shipments
     * Biteship never registered and for ones already cancelled. When
     * Biteship refuses (the courier already picked the parcel up) the local
     * status is left alone and the error is rethrown (reported by
     * CancelShipmentsOnOrderCancelled) so staff see the parcel is still moving.
     */
    public function cancelShipment(OrderShipment $shipment): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        // Only a Biteship order: another provider's order number is not ours to cancel.
        $orderId = BiteshipShipments::orderId($shipment);

        if ($orderId === null || filled(data_get($shipment->metadata, 'biteship.cancelled_at'))) {
            return;
        }

        try {
            $response = $this->client->cancelOrder($orderId, 'others', 'Order cancelled by the store');
        } catch (BiteshipApiException $e) {
            $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
            $metadata['biteship'] = [...(is_array($metadata['biteship'] ?? null) ? $metadata['biteship'] : []), 'cancel_error' => $e->getMessage()];
            $shipment->forceFill(['metadata' => $metadata])->save();

            throw $e;
        }

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['biteship'] ?? null) ? $metadata['biteship'] : [];
        unset($bucket['cancel_error']);
        $bucket['cancelled_at'] = now()->toIso8601String();
        $bucket['status'] = is_scalar($response['status'] ?? null) ? (string) $response['status'] : 'cancelled';
        $metadata['biteship'] = $bucket;

        $attributes = ['metadata' => $metadata];

        if (BiteshipStatus::shouldReplace($shipment->status, NormalizeShipmentStatus::CANCELLED)) {
            $attributes['status'] = NormalizeShipmentStatus::CANCELLED;
        }

        $shipment->forceFill($attributes)->save();
    }

    /**
     * Shopper's contract has no room for the warehouse shipment Biteship
     * needs (lines, receiver, selected courier): fulfillShipment() instead.
     */
    public function createShipment(Address $from, Address $to, array $packages, string $serviceCode): Shipment
    {
        throw ShippingException::notSupported('createShipment (use fulfillShipment)', $this->code());
    }

    /**
     * Biteship's public tracking needs the courier code next to the waybill,
     * which Shopper's track(string) does not carry: generic code calls
     * trackShipment() (TracksShipments) instead.
     */
    public function track(string $trackingNumber): TrackingInfo
    {
        throw ShippingException::notSupported('track (use trackShipment)', $this->code());
    }
}
