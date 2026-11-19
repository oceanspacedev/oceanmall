<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Shipping;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\KiriminAja\Exceptions\KiriminAjaException;
use App\Integrations\KiriminAja\KiriminAja;
use App\Integrations\KiriminAja\Services\KiriminAjaCarrierSync;
use App\Integrations\KiriminAja\Services\KiriminAjaClient;
use App\Integrations\KiriminAja\Services\KiriminAjaFulfillment;
use App\Integrations\KiriminAja\Services\KiriminAjaTracker;
use App\Integrations\KiriminAja\Support\KiriminAjaCouriers;
use App\Integrations\KiriminAja\Support\KiriminAjaDestination;
use App\Models\OrderShipment;
use App\Shipping\CarrierSyncResult;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\PresentsCarriers;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\Contracts\ValidatesOriginIds;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingQuoteContext;
use App\Shipping\ShippingQuoteFailure;
use App\Support\EnabledCarriers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Shopper\Core\Models\Carrier;
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
 * Shopper shipping driver for KiriminAja express delivery (one account, 15+
 * couriers): kecamatan / kelurahan search, checkout tariffs, request pickup,
 * tracking, cancel and courier sync.
 *
 * Plug and play: registered always, but isConfigured() is true only with
 * KIRIMINAJA_API_KEY (and an explicit base URL in production). Generic code
 * reaches it only through Shopper's ShippingDriver and the store's
 * App\Shipping\Contracts, guarded by the operator switch
 * `shopper.shipping.drivers.kiriminaja.enabled`.
 *
 * Vocabulary: destination / warehouse origin ids are "{kecamatan}:{kelurahan}"
 * (KiriminAjaDestination). Carriers it owns have carriers.driver =
 * 'kiriminaja' and metadata.courier_code = the KiriminAja courier code.
 */
final class KiriminAjaDriver extends Driver implements CancelsShipments, FulfillsShipments, PresentsCarriers, ReconcilesShipments, RequiresDestination, ResolvesDestinations, SyncsCarriers, TracksShipments, ValidatesOriginIds
{
    public const CODE = 'kiriminaja';

    /**
     * Collaborators are resolved per call: Shopper's ShippingManager
     * memoizes this driver across queue jobs and Octane requests.
     */
    public function __construct(private readonly KiriminAjaClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'KiriminAja';
    }

    public function isConfigured(): bool
    {
        return KiriminAja::configured();
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
     * Subdistrict search by name or postal code
     * (GET /api/mitra/v6.1/addresses, min. 3 characters, rate-limited:
     * every answer is cached for 30 days).
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

        if (mb_strlen($query) < 3) {
            return [];
        }

        $rows = Cache::remember(
            'kiriminaja:addresses:v1:'.sha1($query),
            now()->addDays(30),
            function () use ($query): array {
                $response = $this->client->searchAddresses($query);
                $rows = [];

                foreach ((array) ($response['data'] ?? []) as $row) {
                    if (is_array($row) && ($parsed = KiriminAjaDestination::searchRow($row)) !== null) {
                        $rows[] = $parsed;
                    }
                }

                return $rows;
            },
        );

        return array_slice($rows, 0, $limit);
    }

    /**
     * The warehouse's kelurahan from its postal code: the single search row
     * with exactly that postal code, narrowed by the kelurahan / kecamatan
     * named in the street address and by the city. Null unless exactly one
     * verified row remains.
     */
    public function resolveOrigin(Inventory $inventory): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $postal = trim((string) $inventory->getAttribute('postal_code'));

        if (preg_match('/^\d{5}$/', $postal) !== 1) {
            return null;
        }

        try {
            $rows = array_values(array_filter(
                $this->searchDestinations($postal, 50),
                static fn (array $row): bool => ($row['postal_code'] ?? null) === $postal,
            ));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $normalize = static fn (mixed $value): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(is_scalar($value) ? (string) $value : '')));
        $street = $normalize(implode(' ', [$inventory->getAttribute('street_address'), $inventory->getAttribute('street_address_plus')]));
        $city = $normalize($inventory->getAttribute('city'));

        foreach ([
            static fn (array $row): bool => $street !== '' && $normalize($row['subdistrict'] ?? '') !== '' && str_contains(" {$street} ", ' '.$normalize($row['subdistrict']).' '),
            static fn (array $row): bool => $street !== '' && $normalize($row['district'] ?? '') !== '' && str_contains(" {$street} ", ' '.$normalize($row['district']).' '),
            static fn (array $row): bool => $city !== '' && $normalize($row['city'] ?? '') !== '' && (str_contains($city, $normalize($row['city'])) || str_contains($normalize($row['city']), $city)),
        ] as $filter) {
            if (count($rows) <= 1) {
                break;
            }

            $narrowed = array_values(array_filter($rows, $filter));

            if ($narrowed !== []) {
                $rows = $narrowed;
            }
        }

        if (count($rows) !== 1) {
            return null;
        }

        return ['id' => (string) $rows[0]['id'], 'label' => $rows[0]['label'] ?? null];
    }

    /**
     * "{kecamatan_id}" or "{kecamatan_id}:{kelurahan_id}".
     *
     * @return list<string>
     */
    public function originIdRules(): array
    {
        return ['nullable', 'string', 'max:20', 'regex:'.KiriminAjaDestination::PATTERN];
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $context = resolve(ShippingQuoteContext::class);

        // Called directly (Shopper's CarrierRateService, third-party code):
        // derive origin / destination / couriers from the Address DTO.
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
        $origin = KiriminAjaDestination::parse($context->originId($this->code()));
        $destination = KiriminAjaDestination::parse($context->destinationId($this->code()));

        if ($origin === null || $destination === null) {
            return collect();
        }

        // The enabled carriers this driver owns; before any is seeded / synced
        // the default set (never every account courier: POS / RPX / Paxel need
        // separate APIs and instant couriers are not supported), minus the
        // ones staff switched off.
        $couriers = KiriminAjaCouriers::filter($context->couriers());

        if ($couriers === []) {
            $couriers = array_values(array_filter(
                array_keys(KiriminAjaCouriers::DEFAULTS),
                static fn (string $code): bool => ! $context->isExcluded($code),
            ));
        }

        $weight = $this->billableGrams($packages);
        $itemValue = max(0, $context->itemValue());
        // KiriminAja requires item_value with insurance=1.
        $insurance = (bool) config('kiriminaja.insurance', false) && $itemValue > 0;

        $payload = array_filter([
            'origin' => $origin->district,
            'subdistrict_origin' => $origin->subdistrict,
            'destination' => $destination->district,
            'subdistrict_destination' => $destination->subdistrict,
            'weight' => $weight,
            'insurance' => $insurance ? 1 : 0,
            'item_value' => $itemValue > 0 ? $itemValue : null,
            'courier' => $couriers !== [] ? $couriers : null,
        ], static fn (mixed $value): bool => $value !== null);

        $hours = max(0, (int) config('kiriminaja.rate_cache_hours', 12));
        $key = 'kiriminaja:rates:v1:'.sha1((string) json_encode($payload));

        try {
            $response = $hours > 0
                ? Cache::remember($key, now()->addHours($hours), fn (): array => $this->client->shippingPrice($payload))
                : $this->client->shippingPrice($payload);
        } catch (KiriminAjaException $e) {
            if ($e->isRateLimited()) {
                $context->markFailure($this->code(), ShippingQuoteFailure::QUOTA, $e->getMessage(), now()->addMinutes(5));

                return collect();
            }

            throw ShippingException::apiError($this->code(), $e->getMessage());
        }

        return $this->rates($response, $context, $couriers, $insurance);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $couriers
     * @return Collection<int, ShippingRate>
     */
    private function rates(array $response, ShippingQuoteContext $context, array $couriers, bool $insurance): Collection
    {
        $rates = collect();

        foreach ((array) ($response['results'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $service = strtolower(trim((string) ($row['service'] ?? '')));
            $type = trim((string) ($row['service_type'] ?? ''));
            $cost = $row['cost'] ?? null;

            if ($service === '' || $type === '' || ! is_numeric($cost) || (int) $cost < 1) {
                continue;
            }

            if (! KiriminAjaCouriers::isExpressCode($service) || $context->isExcluded($service)
                || ($couriers !== [] && ! in_array($service, $couriers, true))) {
                continue;
            }

            // IDR is zero-decimal: cost / insurance are whole rupiah.
            $insured = $insurance || ($row['force_insurance'] ?? false) === true;
            $insuranceFee = $insured && is_numeric($row['insurance'] ?? null) ? max(0, (int) $row['insurance']) : 0;
            $serviceCode = "{$service}:{$type}";
            $serviceName = trim((string) ($row['service_name'] ?? '')) ?: strtoupper($type);

            $rates->push(new ShippingRate(
                serviceCode: $serviceCode,
                serviceName: $serviceName,
                amount: (int) $cost + $insuranceFee,
                currency: 'IDR',
                carrierCode: $service,
                estimatedDays: self::etd($row['etd'] ?? null),
            ));

            // Kept on the selected rate (order_shipments.metadata.rate) for
            // the create-order call: shipping_cost must be the quoted `cost`.
            $context->describe($this->code(), $serviceCode, [
                'carrier_name' => KiriminAjaCouriers::name($service) ?? $serviceName,
                'kiriminaja_service' => $service,
                'kiriminaja_service_type' => $type,
                'kiriminaja_cost' => (int) $cost,
                'kiriminaja_insurance' => $insuranceFee,
                'kiriminaja_group' => is_scalar($row['group'] ?? null) ? (string) $row['group'] : null,
                'kiriminaja_drop' => ($row['drop'] ?? null) === true,
                'kiriminaja_cod' => ($row['cod'] ?? null) === true,
            ]);
        }

        return $rates;
    }

    /**
     * Register the shipment with KiriminAja (request pickup). Errors are
     * stored on the shipment and rethrown so the queue retries.
     */
    public function fulfillShipment(OrderShipment $shipment): void
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        resolve(KiriminAjaFulfillment::class)->fulfill($shipment);
    }

    /**
     * Cancel through POST /api/mitra/v3/cancel_shipment. KiriminAja cancels
     * by AWB only and only before the courier picks the parcel up; non-COD
     * parcels are voided within 48 h (the callback / tracking then reports
     * the cancellation). Without an AWB (pickup request not paid / processed
     * yet) nothing can be cancelled through the API: staff cancel it in the
     * KiriminAja dashboard, which is recorded on the shipment.
     */
    public function cancelShipment(OrderShipment $shipment): void
    {
        if (! $this->isConfigured() || ShipmentMetadata::externalReference($shipment) === null) {
            return;
        }

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : [];

        if ($shipment->status === NormalizeShipmentStatus::CANCELLED || isset($bucket['cancel_requested_at'])) {
            return;
        }

        // KiriminAja cancels only before the courier has the parcel.
        if (in_array($shipment->status, [NormalizeShipmentStatus::PICKED_UP, NormalizeShipmentStatus::IN_TRANSIT, NormalizeShipmentStatus::DELIVERED], true)) {
            $bucket['cancel_note'] = 'Already with the courier: KiriminAja cannot cancel it; arrange a return in the KiriminAja dashboard.';
            $metadata['kiriminaja'] = $bucket;
            $shipment->forceFill(['metadata' => $metadata])->save();

            return;
        }

        $awb = KiriminAjaTracker::awb($shipment);

        if ($awb === null) {
            $bucket['cancel_note'] = 'No AWB yet: cancel this pickup request in the KiriminAja dashboard.';
            $metadata['kiriminaja'] = $bucket;
            $shipment->forceFill(['metadata' => $metadata])->save();

            Log::warning('KiriminAja shipment has no AWB to cancel; cancel it in the KiriminAja dashboard.', [
                'shipment_id' => $shipment->id,
                'order_id' => ShipmentMetadata::externalReference($shipment),
            ]);

            return;
        }

        $response = $this->client->cancelShipment($awb, 'Pesanan dibatalkan oleh toko');

        $bucket['cancel_requested_at'] = now()->toIso8601String();
        $bucket['cancel_response'] = [
            'text' => $response['text'] ?? null,
            'data' => is_array($response['data'] ?? null) ? $response['data'] : null,
        ];
        unset($bucket['cancel_note']);
        $metadata['kiriminaja'] = $bucket;

        $shipment->forceFill(['metadata' => $metadata])->save();
    }

    public function reconcileShipment(OrderShipment $shipment): OrderShipment
    {
        if (! $this->isConfigured()) {
            return $shipment;
        }

        return resolve(KiriminAjaTracker::class)->reconcile($shipment);
    }

    public function trackShipment(OrderShipment $shipment): TrackingInfo
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $result = resolve(KiriminAjaTracker::class)->refresh($shipment, reuse: true);

        if ($result === null) {
            throw new RuntimeException('KiriminAja has no tracking for this shipment yet.');
        }

        return $result['info'];
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            throw ShippingException::apiError($this->code(), 'Airway bill is required.');
        }

        try {
            return resolve(KiriminAjaTracker::class)->track($trackingNumber);
        } catch (KiriminAjaException $e) {
            throw ShippingException::apiError($this->code(), $e->getMessage());
        }
    }

    /**
     * Shopper's createShipment() has no place for the warehouse shipment
     * KiriminAja needs (lines, recipient, selected service): shipments are
     * registered with fulfillShipment(OrderShipment).
     */
    public function createShipment(Address $from, Address $to, array $packages, string $serviceCode): Shipment
    {
        throw ShippingException::notSupported('createShipment (use fulfillShipment)', $this->code());
    }

    public function syncCarriers(bool $dryRun = false): CarrierSyncResult
    {
        if (! $this->isConfigured()) {
            return CarrierSyncResult::failed('KiriminAja is not configured.');
        }

        return resolve(KiriminAjaCarrierSync::class)->sync($dryRun);
    }

    /**
     * Storefront courier list: express couriers only, with their name.
     */
    public function presentCarrier(Carrier $carrier): ?array
    {
        $code = EnabledCarriers::courierCode($carrier);

        if (! KiriminAjaCouriers::isExpressCode($code)) {
            return null;
        }

        return ['code' => $code, 'label' => (string) ($carrier->name ?: KiriminAjaCouriers::name($code) ?: strtoupper($code))];
    }

    /**
     * Billable weight in grams: per package the larger of the actual weight
     * and KiriminAja's volumetric weight (L × W × H ÷ 6, cm → grams).
     *
     * @param  array<int, Package>  $packages
     */
    private function billableGrams(array $packages): int
    {
        $grams = 0;

        foreach ($this->normalizePackages($packages) as $package) {
            $actual = max(0.0, $package->weight) * 1000;
            $volumetric = max(0.0, $package->length) * max(0.0, $package->width) * max(0.0, $package->height) / 6;
            $grams += (int) ceil(max($actual, $volumetric));
        }

        return max(1, $grams);
    }

    /**
     * KiriminAja answers e.g. "2-3"; the storefront appends its own "hari".
     */
    private static function etd(mixed $etd): ?string
    {
        $value = is_scalar($etd) ? trim((string) $etd) : '';
        $value = trim((string) preg_replace('/\s*(hari|days?)\s*$/iu', '', $value));

        return $value !== '' && $value !== '-' ? $value : null;
    }
}
