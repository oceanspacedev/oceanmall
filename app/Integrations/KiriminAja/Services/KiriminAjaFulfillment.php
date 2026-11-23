<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Services;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\KiriminAja\Exceptions\KiriminAjaException;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Integrations\KiriminAja\Support\KiriminAjaDestination;
use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use App\Shipping\AddressDestination;
use App\Shipping\DestinationCoordinateResolver;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShipmentNotFulfillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Shopper\Core\Enum\Dimension\Length;
use Shopper\Core\Enum\Dimension\Weight;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Throwable;

/**
 * Registers one paid warehouse shipment with KiriminAja: one express package
 * (POST /api/mitra/v6.1/request_pickup) built from the shipment lines, the
 * warehouse (sender) and the order's shipping address (recipient), with the
 * courier service and price the customer selected at checkout.
 *
 * Idempotent across retries and crashes:
 *
 * - the partner order id is derived from the shipment (prefix + id + creation
 *   time), recorded BEFORE the request is sent;
 * - a shipment that already has an external_reference is only reconciled
 *   (Tracking API), never registered twice;
 * - a shipment with a recorded attempt but no reference is first looked up
 *   by its order id, so a request that reached KiriminAja before a crash is
 *   adopted instead of duplicated.
 *
 * KiriminAja issues the AWB after the pickup request is processed (for
 * accounts without Term of Payment / KA Credit, after the merchant pays the
 * returned QR); it then arrives through the callback or the hourly
 * reconcile. Non-COD only: the order must be paid before it is shipped.
 *
 * Errors are stored under the neutral metadata.fulfillment_error key and
 * rethrown so the queue / shipping:fulfill-paid retries.
 */
final readonly class KiriminAjaFulfillment
{
    private const RELATIONS = ['order.shippingAddress', 'order.items', 'order.customer', 'inventory', 'lines.purchasable'];

    private const RECONCILE_EVERY_MINUTES = 15;

    public function __construct(
        private KiriminAjaClient $client,
        private KiriminAjaTracker $tracker,
        private InventoryOrigins $origins,
    ) {}

    public function fulfill(OrderShipment $shipment): void
    {
        // The paid-order listener's job and the 5-minute shipping:fulfill-paid
        // sweep may reach the same shipment at once: one registers it, the
        // other leaves it (the partner order id would make KiriminAja reject
        // the duplicate anyway, but this spares the round trip and the error).
        $lock = Cache::lock('kiriminaja:fulfill:'.$shipment->id, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $shipment = OrderShipment::query()->with(self::RELATIONS)->findOrFail($shipment->id);

            if (KiriminAjaTracker::awb($shipment) !== null || $shipment->status === NormalizeShipmentStatus::CANCELLED) {
                return;
            }

            try {
                $this->execute($shipment);
                $this->clearError($shipment);
            } catch (ShipmentNotFulfillable $e) {
                // Not a provider failure: the order was closed / the parcel left.
                throw $e;
            } catch (Throwable $e) {
                $this->recordError($shipment, $e->getMessage());

                throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
            }
        } finally {
            $lock->release();
        }
    }

    private function execute(OrderShipment $shipment): void
    {
        // Already registered: only read back what KiriminAja knows (AWB...).
        // The AWB may wait for the merchant to pay the pickup QR, during which
        // shipping:fulfill-paid sweeps this shipment every 5 minutes: read the
        // rate-limited Tracking API at most every RECONCILE_EVERY_MINUTES
        // (callbacks and shipping:refresh-tracking bring the AWB meanwhile).
        if (ShipmentMetadata::externalReference($shipment) !== null) {
            if (! self::trackedRecently($shipment)) {
                $this->tracker->reconcile($shipment);
            }

            return;
        }

        $order = $shipment->order;

        if (! $order instanceof Order) {
            throw new RuntimeException('Shipment is missing its order.');
        }

        if (! self::paid($order)) {
            throw new RuntimeException('KiriminAja only ships paid (non-COD) orders; this order is not paid yet.');
        }

        $orderId = $this->partnerOrderId($shipment);
        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : [];

        // A previous attempt may have reached KiriminAja before we could store
        // its answer: adopt that registration instead of creating a duplicate.
        if (isset($bucket['attempted_at']) && $this->adoptExisting($shipment, $orderId)) {
            return;
        }

        $payload = $this->payload($shipment, $order, $orderId);

        // A cancelled / completed order, or a parcel that left meanwhile, is
        // never booked: re-read right before the pickup request.
        ShipmentFulfillment::assertEligible($shipment);

        $bucket['order_id'] = $orderId;
        $bucket['attempted_at'] = now()->toIso8601String();
        $metadata['kiriminaja'] = $bucket;
        $shipment->forceFill(['metadata' => $metadata])->save();

        try {
            $response = $this->client->requestPickup($payload);
        } catch (KiriminAjaException $e) {
            // A duplicate order id means the parcel exists already.
            if ($this->adoptExisting($shipment, $orderId)) {
                return;
            }

            throw $e;
        }

        $this->recordRegistration($shipment, $orderId, $response, $payload);
    }

    /**
     * Look the partner order id up in KiriminAja; when it exists, record the
     * registration and reconcile it.
     */
    private function adoptExisting(OrderShipment $shipment, string $orderId): bool
    {
        try {
            $response = $this->client->tracking($orderId);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $found = data_get($response, 'details.order_id');

        if (($response['status'] ?? false) !== true || ! is_scalar($found) || strcasecmp(trim((string) $found), $orderId) !== 0) {
            return false;
        }

        $this->recordRegistration($shipment, $orderId, ['status' => true, 'text' => 'adopted from tracking', 'details' => []], null);
        $this->tracker->reconcile($shipment->refresh());

        return true;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $payload
     */
    private function recordRegistration(OrderShipment $shipment, string $orderId, array $response, ?array $payload): void
    {
        $shipment->refresh();
        $item = null;

        foreach ((array) ($response['details'] ?? []) as $row) {
            if (is_array($row) && strcasecmp((string) ($row['order_id'] ?? ''), $orderId) === 0) {
                $item = $row;
                break;
            }
        }

        $awb = is_array($item) && is_scalar($item['awb'] ?? null) ? trim((string) $item['awb']) : '';
        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : [];

        $bucket = array_merge($bucket, array_filter([
            'order_id' => $orderId,
            'kj_order_id' => is_array($item) ? ($item['kj_order_id'] ?? null) : null,
            'pickup_number' => $response['pickup_number'] ?? ($bucket['pickup_number'] ?? null),
            'payment_status' => $response['payment_status'] ?? ($bucket['payment_status'] ?? null),
            'qr_url' => $response['qr_url'] ?? ($bucket['qr_url'] ?? null),
            'service' => is_array($item) ? ($item['service'] ?? null) : ($payload['packages'][0]['service'] ?? null),
            'service_type' => is_array($item) ? ($item['service_type'] ?? null) : ($payload['packages'][0]['service_type'] ?? null),
            'schedule' => $payload['schedule'] ?? null,
            'registered_at' => now()->toIso8601String(),
            'awb' => $awb !== '' ? $awb : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $metadata['kiriminaja'] = $bucket;
        $metadata[ShipmentMetadata::PICKUP_REQUESTED_AT] ??= now()->toIso8601String();
        unset($metadata[ShipmentMetadata::FULFILLMENT_ERROR]);

        $attributes = [
            'external_reference' => $orderId,
            'metadata' => $metadata,
            'status' => $awb !== '' ? NormalizeShipmentStatus::LABELED : NormalizeShipmentStatus::PENDING,
        ];

        if ($awb !== '') {
            $attributes['awb'] = $awb;
            $attributes['tracking_number'] = $awb;
        }

        $shipment->forceFill($attributes)->save();

        $order = $shipment->order()->first();

        if ($order instanceof Order) {
            try {
                resolve(SyncOrderShippingFromShipments::class)->handle($order);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Express order payload (field names of the official docs / PHP SDK).
     *
     * @return array<string, mixed>
     */
    public function payload(OrderShipment $shipment, Order $order, string $orderId): array
    {
        $inventory = $shipment->inventory;

        if (! $inventory instanceof Inventory) {
            throw new RuntimeException('Shipment is missing its warehouse (inventory).');
        }

        $origin = KiriminAjaDestination::parse($this->origins->get($inventory, KiriminAjaDriver::CODE));

        if ($origin === null) {
            throw new RuntimeException('The warehouse has no KiriminAja origin. Fill it in cpanel → Settings → Locations.');
        }

        $address = self::shippingAddress($order);
        $destination = $this->destination($address);
        $rate = $this->rate($shipment);
        $lines = $this->lines($shipment, $order);
        $itemValue = array_sum(array_map(static fn (array $line): int => $line['price'] * $line['qty'], $lines));
        $originSubdistrict = $origin->subdistrict ?? $this->subdistrictFor($origin->district, (string) $inventory->getAttribute('postal_code'));
        $destinationSubdistrict = $destination->subdistrict ?? $this->subdistrictFor($destination->district, (string) ($address['postal_code'] ?? ''));

        $originPin = self::pin($inventory->getAttribute('latitude'), $inventory->getAttribute('longitude'));
        $destinationPin = resolve(DestinationCoordinateResolver::class)->resolve($address);
        [$destinationLat, $destinationLng] = $destinationPin !== null ? array_map('floatval', explode(',', $destinationPin)) : [null, null];

        $package = array_filter([
            'order_id' => $orderId,
            'destination_name' => self::limit(self::receiverName($address), 50),
            'destination_phone' => self::phone($address['phone_number'] ?? null, 'Recipient phone'),
            'destination_address' => self::limit(self::requiredAddress([
                $address['street_address'] ?? null,
                $address['street_address_plus'] ?? null,
                $address['city'] ?? null,
                $address['state'] ?? null,
                $address['postal_code'] ?? null,
            ], 'Recipient address'), 255),
            'destination_kecamatan_id' => $destination->district,
            'destination_kelurahan_id' => $destinationSubdistrict,
            'destination_zipcode' => self::postalCode($address['postal_code'] ?? null),
            'destination_latitude' => $destinationLat,
            'destination_longitude' => $destinationLng,
            'weight' => max(1, array_sum(array_map(static fn (array $line): int => $line['weight'] * $line['qty'], $lines))),
            'width' => max(1, ...array_column($lines, 'width')),
            'length' => max(1, ...array_column($lines, 'length')),
            'height' => max(1, array_sum(array_map(static fn (array $line): int => $line['height'] * $line['qty'], $lines))),
            'qty' => array_sum(array_column($lines, 'qty')),
            'item_value' => max(1000, $itemValue),
            'shipping_cost' => $rate['cost'],
            'service' => $rate['service'],
            'service_type' => $rate['service_type'],
            'insurance_amount' => $rate['insurance'],
            'cod' => 0,
            'package_type_id' => (int) config('kiriminaja.pickup.package_type_id', 7),
            'item_name' => self::limit($lines[0]['name'].(count($lines) > 1 ? ' +'.(count($lines) - 1).' item' : ''), 255),
            'drop' => (bool) config('kiriminaja.pickup.drop_off', false),
            'note' => self::limit('Order '.($order->number ?? $order->id), 50),
            'items' => array_map(static fn (array $line): array => [
                'name' => self::limit($line['name'], 255),
                'price' => $line['price'],
                'weight' => $line['weight'],
                'width' => $line['width'],
                'height' => $line['height'],
                'length' => $line['length'],
                'qty' => $line['qty'],
                'metadata' => array_filter(['sku' => $line['sku']]),
            ], $lines),
        ], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'address' => self::limit(self::requiredAddress([
                $inventory->getAttribute('street_address'),
                $inventory->getAttribute('street_address_plus'),
                $inventory->getAttribute('city'),
                $inventory->getAttribute('postal_code'),
            ], 'Warehouse address'), 200),
            'phone' => self::phone($inventory->getAttribute('phone_number') ?: shopper_setting('phone_number'), 'Warehouse phone'),
            'name' => self::limit(self::required($inventory->getAttribute('name'), 'Warehouse name'), 50),
            'zipcode' => self::postalCode($inventory->getAttribute('postal_code')),
            'kecamatan_id' => $origin->district,
            'kelurahan_id' => $originSubdistrict,
            'latitude' => $originPin !== null ? (float) $originPin[0] : null,
            'longitude' => $originPin !== null ? (float) $originPin[1] : null,
            'platform_name' => self::limit((string) (shopper_setting('name') ?: config('app.name')), 50) ?: null,
            'schedule' => $this->schedule(),
            'packages' => [$package],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The partner order id: string prefix, shipment id and its creation time
     * (so a re-seeded sandbox database never collides with an earlier
     * registration), at most 20 characters.
     */
    public function partnerOrderId(OrderShipment $shipment): string
    {
        $recorded = data_get($shipment->metadata, 'kiriminaja.order_id');

        if (is_string($recorded) && trim($recorded) !== '') {
            return trim($recorded);
        }

        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) config('kiriminaja.pickup.order_prefix', 'OM')));
        $prefix = substr($prefix !== '' && ! ctype_digit($prefix[0]) ? $prefix : 'OM'.$prefix, 0, 6);
        $stamp = strtoupper(base_convert((string) ($shipment->created_at?->getTimestamp() ?? now()->getTimestamp()), 10, 36));

        return substr($prefix.$shipment->id.'-'.$stamp, 0, 20);
    }

    /**
     * Pickup time: `auto` = earliest open KiriminAja slot, or a fixed HH:MM
     * (WIB) today when at least an hour away and before 16:00, else tomorrow.
     */
    private function schedule(): string
    {
        $setting = trim((string) config('kiriminaja.pickup.schedule', 'auto'));

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $setting) === 1) {
            $now = now('Asia/Jakarta');
            $today = $now->copy()->setTimeFromTimeString($setting.':00');

            $slot = $now->copy()->addHour()->lessThanOrEqualTo($today) && $today->hour < 16
                ? $today
                : $today->addDay();

            return $slot->format('Y-m-d H:i:s');
        }

        $response = $this->client->schedules();
        $now = now()->getTimestamp();

        foreach ((array) ($response['schedules'] ?? []) as $slot) {
            if (! is_array($slot) || ($slot['libur'] ?? false) === true) {
                continue;
            }

            $expired = $slot['expired'] ?? null;

            if (is_numeric($expired) && (int) $expired <= $now) {
                continue;
            }

            $clock = is_scalar($slot['clock'] ?? null) ? trim((string) $slot['clock']) : '';

            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $clock) === 1) {
                return $clock;
            }
        }

        throw new RuntimeException('KiriminAja offered no open pickup schedule. Set KIRIMINAJA_PICKUP_SCHEDULE=HH:MM or retry later.');
    }

    /**
     * The service the customer selected (rate details recorded at checkout by
     * KiriminAjaDriver), else parsed from the shipment's courier / service
     * code ("jne:REG23"). The price is what the customer paid for shipping.
     *
     * @return array{service: string, service_type: string, cost: int, insurance: int}
     */
    private function rate(OrderShipment $shipment): array
    {
        $rate = data_get($shipment->metadata, 'rate');
        $rate = is_array($rate) ? $rate : [];
        $serviceCode = (string) ($rate['service_code'] ?? $shipment->service_code ?? '');
        [$codeCourier, $codeType] = array_pad(explode(':', $serviceCode, 2), 2, '');

        $service = strtolower(trim((string) ($rate['kiriminaja_service'] ?? $codeCourier ?: $shipment->carrier_code)));
        $type = trim((string) ($rate['kiriminaja_service_type'] ?? $codeType));
        $insurance = max(0, (int) ($rate['kiriminaja_insurance'] ?? 0));
        $cost = isset($rate['kiriminaja_cost']) && is_numeric($rate['kiriminaja_cost'])
            ? (int) $rate['kiriminaja_cost']
            : (int) $shipment->cost - $insurance;

        if ($service === '' || $type === '') {
            throw new RuntimeException('Shipment has no KiriminAja courier service (service / service_type) to register.');
        }

        if ($cost < 1) {
            throw new RuntimeException('Shipment has no KiriminAja shipping cost to register.');
        }

        return ['service' => $service, 'service_type' => $type, 'cost' => $cost, 'insurance' => $insurance];
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function destination(array $address): KiriminAjaDestination
    {
        $destination = KiriminAjaDestination::parse(AddressDestination::id($address, KiriminAjaDriver::CODE));

        if ($destination !== null) {
            return $destination;
        }

        // The address holds another provider's destination (or none): find
        // ours from the exact postal code, as checkout quoting did.
        $postal = self::postalCode($address['postal_code'] ?? null);
        $row = $postal !== null ? $this->firstRowForPostalCode($postal) : null;
        $destination = KiriminAjaDestination::parse($row['id'] ?? null);

        if ($destination === null) {
            throw new RuntimeException('The shipping address has no KiriminAja destination (kecamatan) for its postal code.');
        }

        return $destination;
    }

    private function subdistrictFor(int $district, string $postalCode): ?int
    {
        $postal = self::postalCode($postalCode);

        if ($postal === null) {
            return null;
        }

        foreach ($this->rowsForPostalCode($postal) as $row) {
            if ((int) ($row['kecamatan_id'] ?? 0) === $district && is_int($row['kelurahan_id'] ?? null)) {
                return $row['kelurahan_id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstRowForPostalCode(string $postal): ?array
    {
        return $this->rowsForPostalCode($postal)[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForPostalCode(string $postal): array
    {
        $driver = resolve(KiriminAjaDriver::class);

        return array_values(array_filter(
            $driver->searchDestinations($postal, 50),
            static fn (array $row): bool => ($row['postal_code'] ?? null) === $postal,
        ));
    }

    /**
     * @return list<array{name: string, sku: string|null, price: int, weight: int, width: int, height: int, length: int, qty: int}>
     */
    private function lines(OrderShipment $shipment, Order $order): array
    {
        $order->loadMissing('items');
        $lines = [];

        foreach ($shipment->lines as $line) {
            /** @var OrderShipmentLine $line */
            $purchasable = $line->purchasable;

            if (! $purchasable instanceof Model) {
                throw new RuntimeException(sprintf('Shipment line [%d] has no product.', $line->getKey()));
            }

            $qty = (int) $line->qty;

            if ($qty < 1) {
                continue;
            }

            $price = null;

            foreach ($order->items as $item) {
                if ((string) $item->getAttribute('product_type') === $purchasable->getMorphClass()
                    && (int) $item->getAttribute('product_id') === (int) $purchasable->getKey()) {
                    $price = (int) $item->getAttribute('unit_price_amount');
                    break;
                }
            }

            $name = trim((string) ($purchasable->getAttribute('name') ?: (method_exists($purchasable, 'product') ? $purchasable->product?->getAttribute('name') : '')));
            $sku = $purchasable->getAttribute('sku');

            $lines[] = [
                'name' => $name !== '' ? $name : 'Produk',
                'sku' => is_scalar($sku) && trim((string) $sku) !== '' ? (string) $sku : null,
                // IDR is zero-decimal: unit_price_amount is whole rupiah.
                'price' => max(0, (int) ($price ?? 0)),
                'weight' => self::grams($purchasable),
                'width' => self::centimeters($purchasable, 'width'),
                'height' => self::centimeters($purchasable, 'height'),
                'length' => self::centimeters($purchasable, 'depth'),
                'qty' => $qty,
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('Shipment has no items to ship.');
        }

        return $lines;
    }

    private static function grams(Model $model): int
    {
        [$raw, $unit] = self::measure($model, 'weight');

        if ($raw === null) {
            return 100;
        }

        return max(1, (int) ceil(match ($unit) {
            Weight::G->value => $raw,
            Weight::LBS->value => $raw * 453.59237,
            default => $raw * 1000,
        }));
    }

    private static function centimeters(Model $model, string $dimension): int
    {
        [$raw, $unit] = self::measure($model, $dimension);

        if ($raw === null) {
            return 1;
        }

        return max(1, (int) ceil(match ($unit) {
            Length::M->value => $raw * 100,
            Length::MM->value => $raw / 10,
            Length::FT->value => $raw * 30.48,
            Length::IN->value => $raw * 2.54,
            default => $raw,
        }));
    }

    /**
     * @return array{0: float|null, 1: string}
     */
    private static function measure(Model $model, string $field): array
    {
        $raw = $model->getAttribute($field.'_value');
        $unit = $model->getAttribute($field.'_unit');

        if ((! is_numeric($raw) || (float) $raw <= 0) && method_exists($model, 'product') && $model->product instanceof Model) {
            $raw = $model->product->getAttribute($field.'_value');
            $unit = $model->product->getAttribute($field.'_unit');
        }

        if ($unit instanceof Weight || $unit instanceof Length) {
            $unit = $unit->value;
        }

        return [is_numeric($raw) && (float) $raw > 0 ? (float) $raw : null, strtolower(trim((string) $unit))];
    }

    /**
     * @return array<string, mixed>
     */
    public static function shippingAddress(Order $order): array
    {
        $model = $order->shippingAddress;
        $metadata = $order->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : (json_decode((string) $metadata, true) ?: []);
        $stored = is_array($metadata['shipping_address'] ?? null) ? $metadata['shipping_address'] : [];

        $address = [
            'first_name' => $model?->first_name ?? ($stored['first_name'] ?? null),
            'last_name' => $model?->last_name ?? ($stored['last_name'] ?? null),
            'street_address' => $model?->street_address ?? ($stored['street_address'] ?? null),
            'street_address_plus' => $model?->street_address_plus ?? ($stored['street_address_plus'] ?? null),
            'postal_code' => $model?->postal_code ?? ($stored['postal_code'] ?? null),
            'city' => $model?->city ?? ($stored['city'] ?? null),
            'state' => $model?->getAttribute('state') ?? ($stored['state'] ?? null),
            'phone_number' => $model?->getAttribute('phone_number') ?? $model?->getAttribute('phone') ?? ($stored['phone_number'] ?? ($metadata['phone_number'] ?? null)),
        ];

        foreach (AddressDestination::fromInput([...$metadata, ...$stored]) as $key => $value) {
            $address[$key] = $value;
        }

        foreach (['latitude', 'longitude'] as $key) {
            $value = $stored[$key] ?? $metadata[$key] ?? $model?->getAttribute($key);

            if (is_numeric($value)) {
                $address[$key] = $value;
            }
        }

        return $address;
    }

    private static function trackedRecently(OrderShipment $shipment): bool
    {
        $tracked = data_get($shipment->metadata, 'kiriminaja.tracked_at');

        if (! is_string($tracked) || trim($tracked) === '') {
            return false;
        }

        try {
            return Carbon::parse($tracked)->greaterThan(now()->subMinutes(self::RECONCILE_EVERY_MINUTES));
        } catch (Throwable) {
            return false;
        }
    }

    private static function paid(Order $order): bool
    {
        $status = $order->payment_status;

        return $status === PaymentStatus::Paid
            || (is_string($status) && $status === PaymentStatus::Paid->value);
    }

    /**
     * Indonesian phone in the 08... / 02... / 628... format KiriminAja
     * validates: the patterns of v6.2 (`^08\d{8,12}$`, `^02\d{7,11}$`,
     * `^628\d{8,12}$`) within v6.1's 9-13 character limit, so a number
     * accepted here passes either endpoint.
     */
    private static function phone(mixed $value, string $label): string
    {
        $phone = preg_replace('/[\s\-().]/', '', is_scalar($value) ? (string) $value : '') ?? '';
        $phone = ltrim($phone, '+');

        if (str_starts_with($phone, '8')) {
            $phone = '0'.$phone;
        }

        if (preg_match('/^(08\d{8,11}|02\d{7,11}|628\d{8,10})$/', $phone) !== 1) {
            throw new RuntimeException("{$label} is not a valid Indonesian phone number (08... / 62...).");
        }

        return $phone;
    }

    private static function postalCode(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^\d{5}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private static function requiredAddress(array $parts, string $label): string
    {
        $address = implode(', ', array_filter(
            array_map(static fn (mixed $part): string => is_scalar($part) ? trim((string) $part) : '', $parts),
            static fn (string $part): bool => $part !== '',
        ));

        if (mb_strlen($address) < 10) {
            throw new RuntimeException("{$label} is too short for KiriminAja (minimum 10 characters).");
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private static function receiverName(array $address): string
    {
        return self::required(trim(implode(' ', array_filter([
            is_scalar($address['first_name'] ?? null) ? trim((string) $address['first_name']) : '',
            is_scalar($address['last_name'] ?? null) ? trim((string) $address['last_name']) : '',
        ]))), 'Recipient name');
    }

    private static function required(mixed $value, string $label): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            throw new RuntimeException("{$label} is required by KiriminAja.");
        }

        return $value;
    }

    private static function limit(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function pin(mixed $lat, mixed $lng): ?array
    {
        return is_numeric($lat) && is_numeric($lng) && ((float) $lat !== 0.0 || (float) $lng !== 0.0)
            ? [(string) $lat, (string) $lng]
            : null;
    }

    private function recordError(OrderShipment $shipment, string $message): void
    {
        $fresh = OrderShipment::query()->find($shipment->id) ?? $shipment;
        $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
        $metadata[ShipmentMetadata::FULFILLMENT_ERROR] = $message;
        $bucket = is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : [];
        $bucket['fulfillment_failed_at'] = now()->toIso8601String();
        $metadata['kiriminaja'] = $bucket;

        $fresh->forceFill(['metadata' => $metadata])->save();
    }

    private function clearError(OrderShipment $shipment): void
    {
        $fresh = OrderShipment::query()->find($shipment->id);

        if (! $fresh instanceof OrderShipment) {
            return;
        }

        $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];

        if (! array_key_exists(ShipmentMetadata::FULFILLMENT_ERROR, $metadata)) {
            return;
        }

        unset($metadata[ShipmentMetadata::FULFILLMENT_ERROR]);
        $fresh->forceFill(['metadata' => $metadata])->save();
    }
}
