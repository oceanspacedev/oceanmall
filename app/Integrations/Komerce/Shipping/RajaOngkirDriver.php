<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Shipping;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\KomerceCarrierSync;
use App\Integrations\Komerce\Services\KomerceShipmentTracker;
use App\Integrations\Komerce\Services\ShippingCostClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Integrations\Komerce\Support\KomerceTrackingContext;
use App\Integrations\Komerce\Support\RajaOngkirCouriers;
use App\Models\InventoryShippingOrigin;
use App\Models\OrderShipment;
use App\Shipping\AddressDestination;
use App\Shipping\CarrierSyncResult;
use App\Shipping\Contracts\DelegatesFulfillment;
use App\Shipping\Contracts\DelegatesQuotes;
use App\Shipping\Contracts\NormalizesSelectedRates;
use App\Shipping\Contracts\PresentsCarriers;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\Contracts\ValidatesOriginIds;
use App\Shipping\ShippingCapabilities;
use App\Shipping\ShippingQuoteContext;
use App\Shipping\ShippingQuoteFailure;
use App\Support\EnabledCarriers;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Package;
use Shopper\Shipping\DataTransferObjects\Shipment;
use Shopper\Shipping\DataTransferObjects\ShippingRate;
use Shopper\Shipping\DataTransferObjects\TrackingEvent;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Exceptions\ShippingException;
use Throwable;

/**
 * Shopper shipping driver for RajaOngkir Shipping Cost API V2 (rates).
 *
 * Fulfillment (store order / pickup / label) stays on the Komerce Delivery
 * driver so Cost and Delivery credentials are never mixed.
 */
final class RajaOngkirDriver extends Driver implements DelegatesFulfillment, DelegatesQuotes, NormalizesSelectedRates, PresentsCarriers, RequiresDestination, ResolvesDestinations, SyncsCarriers, TracksShipments, ValidatesOriginIds
{
    public const CODE = 'rajaongkir';

    /**
     * Contexts are scoped and resolved per call: Shopper's ShippingManager
     * memoizes this driver across queue jobs and Octane requests.
     */
    public function __construct(
        private readonly ShippingCostClient $costClient,
        private readonly RajaOngkirRateMapper $mapper,
        private readonly NormalizeShipmentStatus $normalizeStatus,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    /**
     * Shipping Cost quotes are registered with Komerce Delivery (Store Order).
     */
    public function fulfillmentDriver(): string
    {
        return KomerceShippingDriver::CODE;
    }

    /**
     * Komerce Delivery quotes these carriers first (its tariff rows are what
     * Store Order needs); Shipping Cost answers when Delivery is not
     * configured or has no service for the route.
     */
    public function preferredQuoteDriver(): string
    {
        return KomerceShippingDriver::CODE;
    }

    public function normalizeSelectedRate(array $rate, int $cost): array
    {
        return RajaOngkirCourier::deliveryRate($rate, $cost);
    }

    /**
     * Destination (subdistrict) search. Destination data is static and the
     * free Collaborator plan allows only 100 Shipping Cost requests/day, so
     * each search is remembered.
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

        if ($query === '') {
            return [];
        }

        $cacheKey = 'rajaongkir:destinations:v1:'.sha1("{$query}|{$limit}");
        $rows = Cache::get($cacheKey);

        if (! is_array($rows)) {
            $capabilities = resolve(KomerceCapabilities::class);

            if ($capabilities->quotaExhausted('shipping_cost')) {
                throw ShippingException::apiError($this->code(), 'Shipping Cost daily quota exhausted.');
            }

            try {
                $rows = $this->costClient->searchDomestic($query, $limit);
            } catch (Throwable $e) {
                throw ShippingException::apiError(
                    $this->code(),
                    $capabilities->quotaExhausted('shipping_cost')
                        ? 'Shipping Cost daily quota exhausted.'
                        : $e->getMessage(),
                );
            }

            Cache::put($cacheKey, $rows, now()->addDays(30));
        }

        // Neutral region keys of the ResolvesDestinations contract, next to the
        // RajaOngkir names that app builds released before them still read.
        return array_map(static fn (array $row): array => [
            ...$row,
            'postal_code' => $row['zip_code'] ?? null,
            'city' => $row['city_name'] ?? null,
            'state' => $row['province_name'] ?? null,
            'district' => $row['district_name'] ?? null,
        ], $rows);
    }

    public function resolveOrigin(Inventory $inventory): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if ($inventory->getKey() !== null) {
            $existing = InventoryShippingOrigin::query()
                ->where('inventory_id', $inventory->getKey())
                ->where('driver', KomerceShippingDriver::CODE)
                ->first(['external_id', 'label']);

            if ($existing !== null && trim((string) $existing->external_id) !== '') {
                return [
                    'id' => trim((string) $existing->external_id),
                    'label' => is_scalar($existing->label) && trim((string) $existing->label) !== ''
                        ? trim((string) $existing->label)
                        : null,
                ];
            }
        }

        return resolve(RajaOngkirOriginMatcher::class)->match($inventory);
    }

    /**
     * RajaOngkir destination / origin ids are positive integers.
     *
     * @return list<string>
     */
    public function originIdRules(): array
    {
        return ['nullable', 'integer', 'min:1'];
    }

    /**
     * Align the carriers this driver owns with what Shipping Cost (and Komerce
     * Delivery, when configured) offer for the warehouse route.
     */
    public function syncCarriers(bool $dryRun = false): CarrierSyncResult
    {
        if (! $this->isConfigured()) {
            return CarrierSyncResult::failed('RajaOngkir Shipping Cost is not configured.');
        }

        return resolve(KomerceCarrierSync::class)->sync($dryRun);
    }

    public function trackShipment(OrderShipment $shipment): TrackingInfo
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        return resolve(KomerceShipmentTracker::class)->track(
            $shipment,
            $this,
            KomerceShipmentTracker::costCourier($shipment),
        );
    }

    /**
     * Storefront courier list: official RajaOngkir couriers only (narrowed by
     * the optional RAJAONGKIR_COURIERS override), with their official names.
     */
    public function presentCarrier(Carrier $carrier): ?array
    {
        $code = EnabledCarriers::courierCode($carrier);
        $override = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('komerce.couriers', []),
        )));

        if (! RajaOngkirCouriers::isOfficial($code) || ($override !== [] && ! in_array($code, $override, true))) {
            return null;
        }

        return ['code' => $code, 'label' => RajaOngkirCouriers::name($code) ?? (string) $carrier->name];
    }

    public function name(): string
    {
        return 'RajaOngkir';
    }

    public function isConfigured(): bool
    {
        return Komerce::shippingCostEnabled();
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

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $context = resolve(ShippingQuoteContext::class);

        // Called directly (Shopper's CarrierRateService, third-party code):
        // derive origin / destination id / couriers from the Address DTO.
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
        $originId = (string) ($context->originId($this->code()) ?? $context->originId(KomerceShippingDriver::CODE));
        $address = $context->address();
        $shippingCapabilities = resolve(ShippingCapabilities::class);
        $primaryDriver = $shippingCapabilities->destinationDriverCode();
        $sameFamilyPrimary = $primaryDriver !== null
            && $shippingCapabilities->providerFamily($primaryDriver) === $shippingCapabilities->providerFamily($this->code());

        $destinationId = (string) (
            AddressDestination::id($address, $this->code())
            ?? AddressDestination::id($address, KomerceShippingDriver::CODE)
            ?? ($sameFamilyPrimary ? null : $context->destinationId($this->code()))
        );

        // Shopper's Address DTO has no subdistrict id: without a warehouse
        // origin and a destination id there is nothing to quote.
        if ($originId === '' || $destinationId === '') {
            return collect();
        }

        $capabilities = resolve(KomerceCapabilities::class);

        if ($capabilities->quotaExhausted('shipping_cost')) {
            $context->markFailure(
                $this->code(),
                ShippingQuoteFailure::QUOTA,
                'Shipping Cost daily quota exhausted.',
                $capabilities->quotaExhaustedUntil('shipping_cost'),
            );

            return collect();
        }

        $couriers = $this->quotableCouriers($context->couriers());

        if ($couriers === []) {
            return collect();
        }

        $weightGrams = $this->totalWeightGrams($packages);
        $cacheKey = 'shipping_rates:'.sha1("{$originId}:{$destinationId}:{$weightGrams}:".implode(',', $couriers));

        $response = Cache::get($cacheKey);

        if (! is_array($response)) {
            try {
                // Free Collaborator plan allows 100 Shipping Cost requests/day; tariffs
                // change rarely, so keep a route+weight quote for hours.
                $response = $this->costClient->calculate(
                    origin: ['id' => $originId],
                    destination: ['id' => $destinationId],
                    weightGrams: $weightGrams,
                    couriers: $couriers,
                );
            } catch (Throwable $e) {
                if ($capabilities->quotaExhausted('shipping_cost')) {
                    $context->markFailure(
                        $this->code(),
                        ShippingQuoteFailure::QUOTA,
                        $e->getMessage(),
                        $capabilities->quotaExhaustedUntil('shipping_cost'),
                    );

                    return collect();
                }

                throw ShippingException::apiError($this->code(), $e->getMessage());
            }

            if (! is_array($response)) {
                throw ShippingException::invalidResponse($this->code());
            }

            if ($this->isErrorEnvelope($response)) {
                $message = $this->envelopeMessage($response);
                $metaCode = (int) data_get($response, 'meta.code', 400);
                $capabilities->observe('shipping_cost', $metaCode >= 400 ? $metaCode : 400, json_encode($response) ?: '');

                if ($capabilities->quotaExhausted('shipping_cost')) {
                    $context->markFailure(
                        $this->code(),
                        ShippingQuoteFailure::QUOTA,
                        $message,
                        $capabilities->quotaExhaustedUntil('shipping_cost'),
                    );

                    return collect();
                }

                throw ShippingException::apiError($this->code(), $message);
            }

            Cache::put($cacheKey, $response, now()->addHours(12));
        }

        $rates = $this->mapper->toShopperRates($response, $couriers);

        foreach ($rates as $rate) {
            // RajaOngkir answers "idexpress" for the carrier seeded as "ide".
            if (strtolower($rate->carrierCode) === 'idexpress') {
                $context->describe($this->code(), $rate->serviceCode, ['carrier_slug' => 'ide']);
            }
        }

        return $rates;
    }

    /**
     * Courier codes to ask Shipping Cost for: the enabled carriers this driver
     * owns (from the quote context), else the RAJAONGKIR_COURIERS override,
     * else every official courier (the API only answers for the ones that
     * serve the route). Always narrowed to official codes and the override.
     *
     * @param  list<string>  $owned
     * @return list<string>
     */
    private function quotableCouriers(array $owned): array
    {
        $override = array_values(array_filter(array_map(
            static fn (mixed $code): string => strtolower(trim((string) $code)),
            (array) config('komerce.couriers', []),
        )));

        $codes = $owned !== [] ? $owned : ($override !== [] ? $override : array_keys(RajaOngkirCouriers::OFFICIAL));

        return RajaOngkirCouriers::filter(array_values(array_filter(
            $codes,
            static fn (string $code): bool => $override === [] || in_array($code, $override, true),
        )));
    }

    public function createShipment(
        Address $from,
        Address $to,
        array $packages,
        string $serviceCode
    ): Shipment {
        throw ShippingException::notSupported('createShipment', $this->code());
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $trackingContext = resolve(KomerceTrackingContext::class);
        $trackingNumber = trim($trackingNumber);
        $courier = strtolower(trim((string) $trackingContext->courier()));

        if ($trackingNumber === '') {
            throw ShippingException::apiError($this->code(), 'Airway bill is required.');
        }

        if ($courier === '' || preg_match('/^[a-z0-9_-]+$/', $courier) !== 1) {
            throw ShippingException::apiError($this->code(), 'RajaOngkir courier code is required to track a waybill.');
        }

        $capabilities = resolve(KomerceCapabilities::class);

        if ($capabilities->quotaExhausted('shipping_cost')) {
            throw ShippingException::apiError($this->code(), 'Shipping Cost daily quota exhausted.');
        }

        try {
            $response = $this->costClient->trackWaybill(
                $trackingNumber,
                $courier,
                $trackingContext->lastPhoneNumber(),
            );
        } catch (Throwable $e) {
            if ($capabilities->quotaExhausted('shipping_cost')) {
                throw ShippingException::apiError($this->code(), 'Shipping Cost daily quota exhausted.');
            }

            throw ShippingException::apiError($this->code(), $e->getMessage());
        }

        if (! is_array($response)) {
            throw ShippingException::invalidResponse($this->code());
        }

        if ($this->isErrorEnvelope($response)) {
            $message = $this->envelopeMessage($response);
            $metaCodeRaw = data_get($response, 'meta.code');
            $metaCode = is_numeric($metaCodeRaw) ? (int) $metaCodeRaw : 400;
            $capabilities->observe('shipping_cost', $metaCode >= 400 ? $metaCode : 400, json_encode($response) ?: '');

            if ($capabilities->quotaExhausted('shipping_cost')) {
                throw ShippingException::apiError($this->code(), 'Shipping Cost daily quota exhausted.');
            }

            throw ShippingException::apiError($this->code(), $message);
        }

        $trackingContext->setLastRaw($response);

        $rawStatus = trim((string) (
            data_get($response, 'data.delivery_status.status')
            ?? data_get($response, 'data.details.status')
            ?? data_get($response, 'data.summary.status')
            ?? ''
        ));
        $status = $this->normalizeStatus->handle($rawStatus, null)
            ?? NormalizeShipmentStatus::IN_TRANSIT;

        if (data_get($response, 'data.delivered') === true) {
            $status = NormalizeShipmentStatus::DELIVERED;
        }

        $events = $this->manifestEvents($response);
        $deliveredAt = $status === NormalizeShipmentStatus::DELIVERED
            ? ($events !== [] ? $events[array_key_last($events)]->occurredAt : now())
            : null;

        return new TrackingInfo(
            trackingNumber: $trackingNumber,
            status: $status,
            statusDescription: $rawStatus !== '' ? $rawStatus : null,
            deliveredAt: $deliveredAt,
            events: $events,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<TrackingEvent>
     */
    private function manifestEvents(array $response): array
    {
        $entries = data_get($response, 'data.manifest', []);

        if (! is_array($entries)) {
            return [];
        }

        return collect($entries)
            ->filter(static fn (mixed $entry): bool => is_array($entry))
            ->map(function (array $entry): TrackingEvent {
                $description = (string) (data_get($entry, 'manifest_description') ?? '');
                $status = (string) (data_get($entry, 'manifest_code') ?? $description);
                $date = data_get($entry, 'manifest_date');
                $time = data_get($entry, 'manifest_time');
                $combined = trim(implode(' ', array_filter([
                    is_scalar($date) ? (string) $date : null,
                    is_scalar($time) ? (string) $time : null,
                ])));

                try {
                    $occurredAt = $combined !== '' ? Carbon::parse($combined) : now();
                } catch (Throwable) {
                    $occurredAt = now();
                }

                $location = data_get($entry, 'city_name');

                return new TrackingEvent(
                    status: $status,
                    description: $description,
                    occurredAt: $occurredAt instanceof DateTimeInterface ? $occurredAt : now(),
                    location: is_scalar($location) ? (string) $location : null,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, Package>  $packages
     */
    private function totalWeightGrams(array $packages): int
    {
        $grams = array_sum(array_map(fn (Package $package): int => $this->packageWeightGrams($package), $packages));

        return max(1, (int) $grams);
    }

    private function packageWeightGrams(Package $package): int
    {
        $weight = max(0.0, $package->weight);

        if ($package->isImperial()) {
            return max(1, (int) ceil($weight * 453.59237));
        }

        return max(1, (int) ceil($weight * 1000));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isErrorEnvelope(array $response): bool
    {
        if (($response['success'] ?? null) === false) {
            return true;
        }

        $status = strtolower(trim((string) (data_get($response, 'meta.status') ?? '')));
        if ($status !== '' && ! in_array($status, ['success', 'succeeded', 'ok'], true)) {
            return true;
        }

        $codeRaw = data_get($response, 'meta.code');

        return is_numeric($codeRaw) && ((int) $codeRaw < 200 || (int) $codeRaw >= 300);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function envelopeMessage(array $response): string
    {
        foreach (['meta.message', 'message'] as $path) {
            $value = data_get($response, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return 'RajaOngkir Shipping Cost returned an error response.';
    }
}
