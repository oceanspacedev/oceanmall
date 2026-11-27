<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Shipping;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\Komerce\Actions\Shipping\CancelKomerceDelivery;
use App\Integrations\Komerce\Actions\Shipping\ReconcileKomerceShipment;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\KomerceCarrierSync;
use App\Integrations\Komerce\Services\KomerceDeliveryFulfillment;
use App\Integrations\Komerce\Services\KomerceShipmentTracker;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Integrations\Komerce\Support\KomerceCourierAssets;
use App\Integrations\Komerce\Support\KomerceLabelResponse;
use App\Integrations\Komerce\Support\KomerceTrackingContext;
use App\Integrations\Komerce\Support\RajaOngkirCouriers;
use App\Models\InventoryShippingOrigin;
use App\Models\OrderShipment;
use App\Shipping\AddressDestination;
use App\Shipping\CarrierSyncResult;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\NormalizesSelectedRates;
use App\Shipping\Contracts\PresentsCarriers;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\RequiresCoordinates;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\RequiresOriginId;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\Contracts\ValidatesOriginIds;
use App\Shipping\DestinationCoordinateResolver;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShippingCapabilities;
use App\Shipping\ShippingQuoteContext;
use App\Shipping\ShippingQuoteFailure;
use App\Support\EnabledCarriers;
use App\Support\OrderShipmentOpsPresenter;
use DateTimeInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
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
use Shopper\Shipping\Facades\Shipping;
use Throwable;

/**
 * Shopper shipping driver for Komerce Shipping Delivery: checkout tariff
 * (`GET /tariff/api/v1/calculate`), Store Order + pickup (AWB), labels,
 * cancel, reconcile and tracking. Uses only the Shipping Delivery key.
 *
 * Komerce Delivery speaks the RajaOngkir destination vocabulary, so it quotes
 * the carriers the RajaOngkir driver owns (RajaOngkirDriver delegates its
 * quotes here first, see DelegatesQuotes) and reads RajaOngkir origin /
 * destination ids from the neutral ShippingQuoteContext.
 */
final class KomerceShippingDriver extends Driver implements CancelsShipments, FulfillsShipments, NormalizesSelectedRates, PresentsCarriers, PrintsLabels, ReconcilesShipments, RequiresCoordinates, RequiresDestination, RequiresOriginId, ResolvesDestinations, SyncsCarriers, TracksShipments, ValidatesOriginIds
{
    public const CODE = 'komerce';

    /** Rate `provider` marker of an official Delivery tariff row (reused by Store Order). */
    public const TARIFF_PROVIDER = 'shipping_delivery';

    /** Order Komerce tariff buckets are shown in. */
    private const BUCKETS = ['calculate_reguler', 'calculate_cargo', 'calculate_instant'];

    /**
     * Contexts are scoped and resolved per call: Shopper's ShippingManager
     * memoizes this driver across queue jobs and Octane requests.
     */
    public function __construct(
        private readonly ShippingDeliveryClient $delivery,
        private readonly NormalizeShipmentStatus $normalizeStatus,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    /**
     * Register the shipment with Komerce Delivery (Store Order + pickup). The
     * fulfilment service records errors on the shipment and rethrows so the
     * generic queue job retries.
     */
    public function fulfillShipment(OrderShipment $shipment): void
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        resolve(KomerceDeliveryFulfillment::class)->fulfill($shipment);
    }

    public function normalizeSelectedRate(array $rate, int $cost): array
    {
        return RajaOngkirCourier::deliveryRate($rate, $cost);
    }

    /**
     * Destination (subdistrict) search via Shipping Delivery.
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

        $cacheKey = 'komerce:delivery:destinations:v1:'.sha1($query);
        $rows = Cache::get($cacheKey);

        if (! is_array($rows)) {
            $capabilities = resolve(KomerceCapabilities::class);

            if ($capabilities->quotaExhausted('shipping_delivery')) {
                throw ShippingException::apiError($this->code(), 'Komerce Delivery daily quota exhausted.');
            }

            try {
                $response = $this->delivery->searchDestinations($query);
            } catch (Throwable $e) {
                throw ShippingException::apiError(
                    $this->code(),
                    $capabilities->quotaExhausted('shipping_delivery')
                        ? 'Komerce Delivery daily quota exhausted.'
                        : $e->getMessage(),
                );
            }

            if ($this->isErrorEnvelope($response)) {
                $message = $this->envelopeMessage($response);
                $metaCode = (int) data_get($response, 'meta.code', 400);
                $capabilities->observe('shipping_delivery', $metaCode >= 400 ? $metaCode : 400, json_encode($response) ?: '');

                throw ShippingException::apiError(
                    $this->code(),
                    $capabilities->quotaExhausted('shipping_delivery')
                        ? 'Komerce Delivery daily quota exhausted.'
                        : $message,
                );
            }

            $rawRows = data_get($response, 'data', []);
            $rows = collect(is_array($rawRows) ? $rawRows : [])
                ->filter(static fn (mixed $row): bool => is_array($row) && isset($row['id']))
                ->map(static function (array $row): array {
                    $zip = isset($row['zip_code']) ? (string) $row['zip_code'] : null;
                    $city = isset($row['city_name']) ? (string) $row['city_name'] : null;
                    $province = isset($row['province_name']) ? (string) $row['province_name'] : null;
                    $district = isset($row['district_name']) ? (string) $row['district_name'] : null;
                    $subdistrict = isset($row['subdistrict_name']) ? (string) $row['subdistrict_name'] : null;

                    return [
                        'id' => (string) $row['id'],
                        'label' => (string) ($row['label'] ?? $row['id']),
                        'province_name' => $province,
                        'city_name' => $city,
                        'district_name' => $district,
                        'subdistrict_name' => $subdistrict,
                        'zip_code' => $zip,
                        'postal_code' => $zip,
                        'city' => $city,
                        'state' => $province,
                        'district' => $district,
                    ];
                })
                ->values()
                ->all();

            Cache::put($cacheKey, $rows, now()->addDays(30));
        }

        return array_slice($rows, 0, $limit);
    }

    public function resolveOrigin(Inventory $inventory): ?array
    {
        // In Komship mode (both Cost and Delivery enabled), RajaOngkirDriver owns
        // origin resolution in the shared vocabulary to avoid duplicate lookups/rows.
        if (! $this->isConfigured() || Komerce::shippingCostEnabled()) {
            return null;
        }

        // Reuse an existing RajaOngkir origin in the shared vocabulary without
        // firing another API lookup (e.g., when migrating from Starter to Delivery-only).
        if ($inventory->getKey() !== null) {
            $existing = InventoryShippingOrigin::query()
                ->where('inventory_id', $inventory->getKey())
                ->where('driver', RajaOngkirDriver::CODE)
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

        return resolve(RajaOngkirOriginMatcher::class)->matchWith(
            $inventory,
            fn (string $query): array => $this->searchDestinations($query, 50),
            'shipping_delivery',
        );
    }

    /**
     * @return list<string>
     */
    public function originIdRules(): array
    {
        return ['nullable', 'integer', 'min:1'];
    }

    public function syncCarriers(bool $dryRun = false): CarrierSyncResult
    {
        if (! $this->isConfigured()
            && (trim((string) config('komerce.shipping_delivery_api_key', '')) === '' || ! Komerce::shippingCostEnabled())) {
            return CarrierSyncResult::failed('Komerce Shipping Delivery is not configured.');
        }

        return resolve(KomerceCarrierSync::class)->sync($dryRun);
    }

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

    /**
     * @param  iterable<OrderShipment>  $shipments
     * @return array{label_url: string|null, pdf: string|null, response: array<string, mixed>}
     */
    public function printShipmentLabels(iterable $shipments, ?string $format = null): array
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $page = $format ?? ShippingDeliveryClient::DEFAULT_LABEL_PAGE;

        if (! in_array($page, ShippingDeliveryClient::LABEL_PAGES, true)) {
            throw new RuntimeException('Format label tidak dikenal.');
        }

        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $printable = [];
        $registered = false;

        foreach ($shipments as $shipment) {
            if (! $shipment instanceof OrderShipment) {
                continue;
            }

            $orderNo = $presenter->deliveryOrderNo($shipment);
            $registered = $registered || $orderNo !== null;

            if ($orderNo !== null && $presenter->canPrintLabel($shipment)) {
                $printable[] = $orderNo;
            }
        }

        $printable = array_values(array_unique($printable));

        if ($printable === []) {
            throw new RuntimeException(
                $registered
                    ? 'Menunggu nomor resi (AWB) dari Komerce. Stiker baru bisa dicetak setelah kurir menerbitkan AWB; tekan Refresh Tracking untuk mengecek.'
                    : 'Daftarkan paket ke Komerce dan request pickup dulu sebelum mencetak stiker resi.',
            );
        }

        try {
            $response = $this->delivery->printLabel($printable, $page);
        } catch (Throwable $e) {
            if ($this->shouldFallbackToThermalLabel($e)) {
                return ['label_url' => null, 'pdf' => null, 'response' => []];
            }

            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return [
            'label_url' => KomerceLabelResponse::absoluteUrl($response),
            'pdf' => KomerceLabelResponse::pdfBinary($response),
            'response' => $response,
        ];
    }

    public function cancelShipment(OrderShipment $shipment): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        resolve(CancelKomerceDelivery::class)->cancelShipment($shipment);
    }

    public function reconcileShipment(OrderShipment $shipment): OrderShipment
    {
        return resolve(ReconcileKomerceShipment::class)->handle($shipment);
    }

    public function trackShipment(OrderShipment $shipment): TrackingInfo
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        return resolve(KomerceShipmentTracker::class)->track(
            $shipment,
            $this,
            KomerceShipmentTracker::deliveryCourier($shipment),
        );
    }

    public function name(): string
    {
        return 'Komerce';
    }

    public function isConfigured(): bool
    {
        return Komerce::shippingDeliveryEnabled();
    }

    public function supportsRealTimeRates(): bool
    {
        return true;
    }

    public function supportsLabels(): bool
    {
        return true;
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    /**
     * Delivery tariff for the checkout quote context: only couriers active for
     * this account, with the exact shipping_cost / cashback / service_fee that
     * Store Order expects (kept on each rate through ShippingQuoteContext).
     * Empty when the context lacks a warehouse origin, pin points or the
     * destination id. Called outside checkout, the context is derived from
     * the Address DTO (ShippingQuoteContext::beginFromAddress()).
     */
    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $context = resolve(ShippingQuoteContext::class);

        // Called directly (Shopper's CarrierRateService, third-party code):
        // derive warehouse / destination id from the Address DTO, with the
        // RajaOngkir destination vocabulary Delivery uses.
        $resolver = $context->active() ? null : $this->destinationResolver();
        $resolverCode = $resolver?->code() ?? RajaOngkirDriver::CODE;

        if ($resolver === $this) {
            $defaultOrigin = Inventory::query()->where('is_default', true)->first()
                ?? Inventory::query()->orderBy('id')->first();
            $origins = resolve(InventoryOrigins::class);

            if ($defaultOrigin instanceof Inventory
                && $origins->get($defaultOrigin, $this->code()) === null
                && $origins->get($defaultOrigin, RajaOngkirDriver::CODE) !== null) {
                $resolverCode = RajaOngkirDriver::CODE;
            }
        }

        $derived = $context->beginFromAddress($to, $resolver, $resolverCode, [$this->code(), RajaOngkirDriver::CODE]);

        try {
            return $this->quote($context, $packages);
        } finally {
            if ($derived) {
                $context->end();
            }
        }
    }

    private function destinationResolver(): ResolvesDestinations
    {
        try {
            $driver = Shipping::driver(RajaOngkirDriver::CODE);
        } catch (Throwable) {
            $driver = null;
        }

        return $driver instanceof RajaOngkirDriver && $driver->isConfigured() ? $driver : $this;
    }

    /**
     * @param  array<int, Package>  $packages
     * @return Collection<int, ShippingRate>
     */
    private function quote(ShippingQuoteContext $context, array $packages): Collection
    {
        $inventory = $context->origin();
        $originId = (string) ($context->originId($this->code()) ?? $context->originId(RajaOngkirDriver::CODE));
        $originPin = $this->pinPoint($inventory?->getAttribute('latitude'), $inventory?->getAttribute('longitude'));
        $address = $context->address();
        $shippingCapabilities = resolve(ShippingCapabilities::class);
        $primaryDriver = $shippingCapabilities->destinationDriverCode();
        $sameFamilyPrimary = $primaryDriver !== null
            && $shippingCapabilities->providerFamily($primaryDriver) === $shippingCapabilities->providerFamily($this->code());

        $destinationId = (string) (
            AddressDestination::id($address, $this->code())
            ?? AddressDestination::id($address, RajaOngkirDriver::CODE)
            ?? ($sameFamilyPrimary ? null : $context->destinationId($this->code()))
        );

        if ($inventory === null || $originId === '' || $originPin === null || $destinationId === '') {
            return collect();
        }

        $destinationPin = resolve(DestinationCoordinateResolver::class)->resolve($address, $originPin);

        if ($destinationPin === null) {
            return collect();
        }

        $capabilities = resolve(KomerceCapabilities::class);

        if ($capabilities->quotaExhausted('shipping_delivery')) {
            $context->markFailure(
                $this->code(),
                ShippingQuoteFailure::QUOTA,
                'Komerce Delivery daily quota exhausted.',
                $capabilities->quotaExhaustedUntil('shipping_delivery'),
            );

            return collect();
        }

        $weightKg = $this->weightKilograms($packages);
        // Komerce rejects item_value <= 0 ("Item value is required and must be greater than 0").
        $itemValue = max(1, $context->itemValue());
        $cacheKey = 'komerce_delivery_rates:'.sha1("{$originId}:{$destinationId}:{$originPin}:{$destinationPin}:{$weightKg}:{$itemValue}");

        $response = Cache::get($cacheKey);

        if (! is_array($response)) {
            try {
                $response = $this->delivery->calculate(
                    $originId,
                    $destinationId,
                    $originPin,
                    $destinationPin,
                    $weightKg,
                    $itemValue,
                );
            } catch (Throwable $e) {
                if ($capabilities->quotaExhausted('shipping_delivery')) {
                    $context->markFailure(
                        $this->code(),
                        ShippingQuoteFailure::QUOTA,
                        $e->getMessage(),
                        $capabilities->quotaExhaustedUntil('shipping_delivery'),
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
                $capabilities->observe('shipping_delivery', $metaCode >= 400 ? $metaCode : 400, json_encode($response) ?: '');

                if ($capabilities->quotaExhausted('shipping_delivery')) {
                    $context->markFailure(
                        $this->code(),
                        ShippingQuoteFailure::QUOTA,
                        $message,
                        $capabilities->quotaExhaustedUntil('shipping_delivery'),
                    );

                    return collect();
                }

                throw ShippingException::apiError($this->code(), $message);
            }

            Cache::put($cacheKey, $response, now()->addMinutes(10));
        }

        return $this->tariffRates($response, $context);
    }

    /**
     * Shopper's contract has no place for the warehouse shipment Komerce
     * needs (lines, receiver, COD, selected tariff row): shipments are
     * registered with fulfillShipment(OrderShipment) instead.
     */
    public function createShipment(
        Address $from,
        Address $to,
        array $packages,
        string $serviceCode
    ): Shipment {
        throw ShippingException::notSupported('createShipment (use fulfillShipment)', $this->code());
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        if (! $this->isConfigured()) {
            throw ShippingException::notConfigured($this->code());
        }

        $trackingContext = resolve(KomerceTrackingContext::class);
        $trackingNumber = trim($trackingNumber);
        $courier = $trackingContext->courier();

        if ($trackingNumber === '') {
            throw ShippingException::apiError($this->code(), 'Airway bill is required.');
        }

        if ($courier === null || $courier === '') {
            throw ShippingException::apiError($this->code(), 'Courier (shipping) is required to track a Shipping Delivery airway bill.');
        }

        $capabilities = resolve(KomerceCapabilities::class);

        if ($capabilities->quotaExhausted('shipping_delivery')) {
            throw ShippingException::apiError($this->code(), 'Komerce Delivery daily quota exhausted.');
        }

        try {
            $response = $this->delivery->track($trackingNumber, $courier);
        } catch (Throwable $e) {
            if ($capabilities->quotaExhausted('shipping_delivery')) {
                throw ShippingException::apiError($this->code(), 'Komerce Delivery daily quota exhausted.');
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
            $capabilities->observe('shipping_delivery', $metaCode >= 400 ? $metaCode : 400, json_encode($response) ?: '');

            if ($capabilities->quotaExhausted('shipping_delivery')) {
                throw ShippingException::apiError($this->code(), 'Komerce Delivery daily quota exhausted.');
            }

            throw ShippingException::apiError($this->code(), $message);
        }

        $trackingContext->setLastRaw($response);

        $rawStatus = trim((string) (
            data_get($response, 'data.last_status')
            ?? data_get($response, 'data.status')
            ?? ''
        ));
        $status = $this->normalizeStatus->handle($rawStatus, NormalizeShipmentStatus::PENDING)
            ?? NormalizeShipmentStatus::PENDING;

        $events = $this->events($response);
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
    private function events(array $response): array
    {
        $entries = [];

        foreach (['data.history', 'data.manifest', 'data.histories', 'data.tracking_history'] as $path) {
            $value = data_get($response, $path);

            if (is_array($value) && $value !== []) {
                $entries = $value;
                break;
            }
        }

        return collect($entries)
            ->filter(static fn (mixed $entry): bool => is_array($entry))
            ->map(function (array $entry): TrackingEvent {
                $description = data_get($entry, 'desc')
                    ?? data_get($entry, 'description')
                    ?? data_get($entry, 'manifest_description')
                    ?? data_get($entry, 'status')
                    ?? '';
                $status = data_get($entry, 'status') ?? data_get($entry, 'code') ?? $description;
                $occurredAt = $this->occurredAt($entry);

                $location = data_get($entry, 'city_name')
                    ?? data_get($entry, 'city')
                    ?? data_get($entry, 'location');

                return new TrackingEvent(
                    status: is_scalar($status) ? (string) $status : '',
                    description: is_scalar($description) ? (string) $description : '',
                    occurredAt: $occurredAt,
                    location: is_scalar($location) ? (string) $location : null,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function occurredAt(array $entry): DateTimeInterface
    {
        $date = data_get($entry, 'date') ?? data_get($entry, 'manifest_date');
        $time = data_get($entry, 'time') ?? data_get($entry, 'manifest_time');
        $combined = trim(implode(' ', array_filter([
            is_scalar($date) ? (string) $date : null,
            is_scalar($time) ? (string) $time : null,
        ])));

        if ($combined === '') {
            return now();
        }

        try {
            return Carbon::parse($combined);
        } catch (Throwable) {
            return now();
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return Collection<int, ShippingRate>
     */
    private function tariffRates(array $response, ShippingQuoteContext $context): Collection
    {
        $rates = collect();

        foreach (self::BUCKETS as $bucket) {
            foreach ((array) data_get($response, "data.{$bucket}", []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $shippingName = trim((string) ($row['shipping_name'] ?? ''));
                $serviceName = trim((string) ($row['service_name'] ?? ''));
                $cost = $row['shipping_cost'] ?? null;

                if ($shippingName === '' || $serviceName === '' || ! is_numeric($cost) || (int) $cost < 1) {
                    continue;
                }

                $carrierCode = RajaOngkirCouriers::codeFromKomerceName($shippingName) ?? strtolower($shippingName);
                $serviceCode = "{$carrierCode}:{$serviceName}";

                $rates->push(new ShippingRate(
                    serviceCode: $serviceCode,
                    serviceName: $serviceName,
                    amount: (int) $cost,
                    currency: 'IDR',
                    carrierCode: $carrierCode,
                    estimatedDays: $this->etd($row['etd'] ?? null),
                ));

                // Official Delivery tariff row, persisted as-is for Store Order.
                $context->describe($this->code(), $serviceCode, [
                    'carrier_name' => RajaOngkirCouriers::name($carrierCode) ?? $shippingName,
                    'carrier_logo' => KomerceCourierAssets::logoUrl($carrierCode),
                    'provider' => self::TARIFF_PROVIDER,
                    'group' => str_replace('calculate_', '', $bucket),
                    'shipping_name' => $shippingName,
                    'shipping_cost' => (int) $cost,
                    'shipping_cashback' => (int) ($row['shipping_cashback'] ?? 0),
                    'shipping_cost_net' => (int) ($row['shipping_cost_net'] ?? ((int) $cost - (int) ($row['shipping_cashback'] ?? 0))),
                    'service_fee' => (int) ($row['service_fee'] ?? 0),
                    'grandtotal' => (int) ($row['grandtotal'] ?? 0),
                    'is_cod' => (bool) ($row['is_cod'] ?? false),
                ]);
            }
        }

        return $rates;
    }

    /**
     * @param  array<int, Package>  $packages
     */
    private function weightKilograms(array $packages): float
    {
        $grams = 0.0;

        foreach ($packages as $package) {
            $weight = max(0.0, $package->weight);
            $grams += $package->isImperial() ? $weight * 453.59237 : $weight * 1000;
        }

        return round(max(0.01, $grams / 1000), 3);
    }

    private function pinPoint(mixed $lat, mixed $lng): ?string
    {
        return is_numeric($lat) && is_numeric($lng) ? $lat.','.$lng : null;
    }

    /**
     * Komerce returns e.g. "1-2 day" / "-"; the storefront appends its own
     * "hari", so keep only the number range.
     */
    private function etd(mixed $etd): ?string
    {
        $value = is_scalar($etd) ? trim((string) $etd) : '';
        $value = trim((string) preg_replace('/\s*(hari|days?)\s*$/iu', '', $value));

        return $value !== '' && $value !== '-' ? $value : null;
    }

    /**
     * Sandbox answers 404 / 500 / "awb not found" when a synthetic order has no
     * downloadable PDF yet. Auth/entitlement (401/403), quota (429), and generic
     * 4xx rejections must surface as real errors instead of rendering 200 thermal.
     */
    private function shouldFallbackToThermalLabel(Throwable $e): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $previous = $e->getPrevious();
        $status = null;

        if ($e instanceof RequestException) {
            $status = $e->response->status();
        } elseif ($previous instanceof RequestException) {
            $status = $previous->response->status();
        }

        $message = $e->getMessage();

        if ($status === null && preg_match('/HTTP request returned status code (\d{3})/i', $message, $matches) === 1) {
            $status = (int) $matches[1];
        }

        if ($status !== null && ! in_array($status, [404, 500], true)) {
            return false;
        }

        $lower = strtolower($message);

        if (preg_match('/(invalid api ?key|unauthorized|forbidden|quota|kuota|limit|too many)/i', $lower) === 1) {
            return false;
        }

        return str_contains($lower, 'awb not found')
            || str_contains($lower, 'label not found')
            || in_array($status, [404, 500], true);
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

        return 'Komerce Delivery returned an error response.';
    }
}
