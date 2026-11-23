<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Services;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\Biteship\Actions\ReconcileBiteshipShipment;
use App\Integrations\Biteship\Biteship;
use App\Integrations\Biteship\Exceptions\BiteshipApiException;
use App\Integrations\Biteship\Shipping\BiteshipRate;
use App\Integrations\Biteship\Support\BiteshipShipments;
use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use App\Shipping\AddressDestination;
use App\Shipping\DestinationCoordinateResolver;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShipmentNotFulfillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Shopper\Core\Enum\Dimension\Length;
use Shopper\Core\Enum\Dimension\Weight;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Throwable;

/**
 * Register one paid warehouse shipment with Biteship (POST /v1/orders): the
 * Biteship order IS the pickup request, and its answer carries the waybill
 * (or the waybill arrives later through the webhook / tracking refresh).
 * Owned by BiteshipShippingDriver (FulfillsShipments); generic code never
 * calls it directly.
 *
 * Idempotent across queue retries and duplicate dispatches:
 *
 *  - a shipment with an AWB is left alone;
 *  - a shipment that already holds a Biteship order id is reconciled, never
 *    registered twice;
 *  - the Biteship `reference_id` is stable per shipment, so when a previous
 *    attempt created the order but crashed before saving it, Biteship
 *    answers "reference id already used" (40002060) with the order id, which
 *    is adopted instead of creating another parcel.
 *
 * Errors are written to the neutral metadata.fulfillment_error and rethrown
 * so App\Jobs\FulfillOrderShipment retries.
 */
final readonly class BiteshipFulfillment
{
    private const RELATIONS = ['order.shippingAddress', 'order.items', 'order.customer', 'inventory', 'lines.purchasable'];

    public function __construct(
        private BiteshipClient $client,
        private ReconcileBiteshipShipment $reconcile,
    ) {}

    public function fulfill(OrderShipment $shipment): void
    {
        $shipment = OrderShipment::query()->with(self::RELATIONS)->findOrFail($shipment->id);

        if (trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '') {
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
    }

    private function execute(OrderShipment $shipment): void
    {
        if (BiteshipShipments::registeredElsewhere($shipment)) {
            // Another provider already holds this parcel (a legacy shipment
            // routed here as the only fulfilment driver): never book a second
            // pickup with Biteship.
            throw new RuntimeException(sprintf(
                'Shipment is already registered with another provider [%s]; it is not registered with Biteship.',
                ShipmentMetadata::externalReference($shipment),
            ));
        }

        if (BiteshipShipments::orderId($shipment) !== null) {
            $shipment = $this->reconcile->handle($shipment);

            if (strtolower((string) $shipment->status) === NormalizeShipmentStatus::CANCELLED) {
                throw new RuntimeException(sprintf(
                    'Biteship order [%s] is cancelled; it is not registered again automatically.',
                    ShipmentMetadata::externalReference($shipment),
                ));
            }

            // Registered: the waybill follows through the webhook or the
            // hourly tracking refresh. Never create a second parcel.
            return;
        }

        $payload = $this->payload($shipment);

        // A cancelled / completed order, or a parcel that left meanwhile, is
        // never booked: re-read right before the paid booking call.
        ShipmentFulfillment::assertEligible($shipment);

        try {
            $response = $this->client->createOrder($payload);
        } catch (BiteshipApiException $e) {
            $existing = $e->biteshipCode === BiteshipApiException::REFERENCE_ID_USED
                ? data_get($e->details, 'order_id')
                : null;

            if (! is_scalar($existing) || trim((string) $existing) === '') {
                throw $e;
            }

            // An earlier attempt registered this shipment: adopt that order.
            // apply() records it as a Biteship order (external_reference,
            // metadata.biteship.order_id, driver) from Biteship's own answer.
            $this->reconcile->apply($shipment, $this->client->order(trim((string) $existing)));
            $this->markPickupRequested($shipment);

            return;
        }

        if (! is_scalar($response['id'] ?? null) || trim((string) $response['id']) === '') {
            throw new RuntimeException('Biteship create-order response has no order id.');
        }

        $this->reconcile->apply($shipment, $response);
        $this->markPickupRequested($shipment);
    }

    /**
     * POST /v1/orders body (https://biteship.com/id/docs/api/orders/create).
     *
     * @return array<string, mixed>
     */
    public function payload(OrderShipment $shipment): array
    {
        $order = $shipment->order;

        if (! $order instanceof Order) {
            throw new RuntimeException('Shipment is missing an order.');
        }

        $inventory = $shipment->inventory;

        if (! $inventory instanceof Inventory) {
            throw new RuntimeException('Shipment is missing its inventory pickup location.');
        }

        $address = $this->shippingAddress($order);
        $rate = BiteshipRate::fromShipment($shipment);
        $items = $this->items($shipment, $order);

        if ($items === []) {
            throw new RuntimeException('Shipment must contain at least one item.');
        }

        $storeName = $this->string(shopper_setting('name') ?: shopper_setting('legal_name') ?: config('app.name'));

        $payload = [
            'shipper_contact_name' => $storeName,
            'shipper_contact_phone' => $this->phone($inventory->getAttribute('phone_number') ?: shopper_setting('phone_number')),
            'shipper_contact_email' => $this->string($inventory->getAttribute('email') ?: shopper_setting('email')),
            'shipper_organization' => $storeName,

            'origin_contact_name' => $this->required($inventory->getAttribute('name') ?: $storeName, 'Warehouse name'),
            'origin_contact_phone' => $this->requiredPhone($inventory->getAttribute('phone_number') ?: shopper_setting('phone_number'), 'Warehouse phone'),
            'origin_address' => $this->required($this->joined([
                $inventory->getAttribute('street_address'),
                $inventory->getAttribute('street_address_plus'),
                $inventory->getAttribute('city'),
                $inventory->getAttribute('postal_code'),
            ]), 'Warehouse address'),

            'destination_contact_name' => $this->required($this->joined([$address['first_name'] ?? null, $address['last_name'] ?? null], ' '), 'Receiver name'),
            'destination_contact_phone' => $this->requiredPhone($address['phone_number'] ?? null, 'Receiver phone'),
            'destination_contact_email' => $this->string($order->customer?->email),
            'destination_address' => $this->required($this->joined([
                $address['street_address'] ?? null,
                $address['street_address_plus'] ?? null,
                $address['city'] ?? null,
                $address['state'] ?? null,
                $address['postal_code'] ?? null,
            ]), 'Receiver address'),

            'courier_company' => $rate['courier_company'],
            'courier_type' => $rate['courier_type'],
            'reference_id' => $this->referenceId($order, $shipment),
            'order_note' => $this->string('Order '.$order->number),
            'metadata' => ['order_number' => (string) $order->number, 'shipment_id' => (int) $shipment->id],
            'items' => $items,
            ...$this->delivery(),
        ];

        $originArea = resolve(InventoryOrigins::class)->get($inventory, Biteship::CODE);
        $originPostal = $this->postalCode($inventory->getAttribute('postal_code'));
        $destinationArea = AddressDestination::id($address, Biteship::CODE);
        $destinationPostal = $this->postalCode($address['postal_code'] ?? null);

        if ($originArea === null && $originPostal === null) {
            throw new RuntimeException('Warehouse needs a Biteship origin area id (Settings → Locations) or a 5-digit postal code.');
        }

        if ($destinationArea === null && $destinationPostal === null) {
            throw new RuntimeException('Receiver address needs a Biteship area or a 5-digit postal code.');
        }

        // The area id is the most precise location; the postal code is the
        // fallback (Biteship needs one of area id, postal code or coordinate).
        if ($originArea !== null) {
            $payload['origin_area_id'] = $originArea;
        } else {
            $payload['origin_postal_code'] = (int) $originPostal;
        }

        if ($destinationArea !== null) {
            $payload['destination_area_id'] = $destinationArea;
        } else {
            $payload['destination_postal_code'] = (int) $destinationPostal;
        }

        $originPin = $this->pin($inventory->getAttribute('latitude'), $inventory->getAttribute('longitude'));
        $destinationPin = AddressDestination::pinPoint($address);
        $resolver = resolve(DestinationCoordinateResolver::class);

        if ($originPin !== null && $destinationPin !== null && $resolver->isValidPinPoint($destinationPin)) {
            [$lat, $lng] = array_map('floatval', explode(',', $originPin));
            $payload['origin_coordinate'] = ['latitude' => $lat, 'longitude' => $lng];
            [$lat, $lng] = array_map(static fn (string $part): float => (float) trim($part), explode(',', $destinationPin));
            $payload['destination_coordinate'] = ['latitude' => $lat, 'longitude' => $lng];
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Stable per shipment (Biteship rejects a reused reference_id and tells
     * which order holds it), so a retry never registers a second parcel.
     */
    private function referenceId(Order $order, OrderShipment $shipment): string
    {
        return substr((string) $order->number, 0, 60).'-S'.$shipment->id;
    }

    /**
     * @return array<string, string>
     */
    private function delivery(): array
    {
        $type = strtolower(trim((string) config('biteship.delivery_type', 'now')));

        if ($type !== 'scheduled') {
            return ['delivery_type' => 'now'];
        }

        $time = trim((string) config('biteship.pickup_time', '10:00'));

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new RuntimeException('BITESHIP_PICKUP_TIME must use the HH:mm format.');
        }

        $now = now('Asia/Jakarta');
        $slot = $now->copy()->setTimeFromTimeString($time);
        $date = $now->copy()->addMinutes(90)->lessThanOrEqualTo($slot) ? $now : $now->copy()->addDay();

        return [
            'delivery_type' => 'scheduled',
            'delivery_date' => $date->format('Y-m-d'),
            'delivery_time' => $time,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(OrderShipment $shipment, Order $order): array
    {
        $order->loadMissing('items');
        $category = trim((string) config('biteship.item_category', 'others')) ?: 'others';

        return $shipment->lines
            ->map(function (OrderShipmentLine $line) use ($order, $category): array {
                $purchasable = $line->purchasable;

                if (! $purchasable instanceof Model) {
                    throw new RuntimeException(sprintf('Shipment line [%d] has no purchasable model.', $line->getKey()));
                }

                $qty = max(1, (int) $line->qty);

                return array_filter([
                    'name' => $this->required($purchasable->getAttribute('name'), sprintf('Shipment line [%d] product name', $line->getKey())),
                    'sku' => $this->string($purchasable->getAttribute('sku')),
                    'category' => $category,
                    'value' => $this->unitPrice($order, $purchasable),
                    'quantity' => $qty,
                    'weight' => $this->weightGrams($purchasable),
                    'length' => $this->centimeters($purchasable, 'depth'),
                    'width' => $this->centimeters($purchasable, 'width'),
                    'height' => $this->centimeters($purchasable, 'height'),
                ], static fn (mixed $value): bool => $value !== null);
            })
            ->values()
            ->all();
    }

    /**
     * Integer rupiah (IDR is zero-decimal in this store).
     */
    private function unitPrice(Order $order, Model $purchasable): int
    {
        foreach ($order->items as $item) {
            if ((string) $item->getAttribute('product_type') === $purchasable->getMorphClass()
                && (int) $item->getAttribute('product_id') === (int) $purchasable->getKey()) {
                return max(0, (int) $item->getAttribute('unit_price_amount'));
            }
        }

        return 0;
    }

    private function weightGrams(Model $model): int
    {
        [$raw, $unit] = $this->measure($model, 'weight');

        if (! is_numeric($raw) || (float) $raw <= 0) {
            return 100;
        }

        $unit = $unit instanceof Weight ? $unit->value : strtolower(trim((string) ($unit ?: Weight::KG->value)));

        return max(1, match ($unit) {
            Weight::G->value => (int) ceil((float) $raw),
            Weight::LBS->value => (int) ceil((float) $raw * 453.59237),
            default => (int) ceil((float) $raw * 1000),
        });
    }

    private function centimeters(Model $model, string $dimension): ?int
    {
        [$raw, $unit] = $this->measure($model, $dimension);

        if (! is_numeric($raw) || (float) $raw <= 0) {
            return null;
        }

        $unit = $unit instanceof Length ? $unit->value : strtolower(trim((string) $unit));

        $cm = match ($unit) {
            Length::M->value => (float) $raw * 100,
            Length::MM->value => (float) $raw / 10,
            Length::FT->value => (float) $raw * 30.48,
            Length::IN->value => (float) $raw * 2.54,
            default => (float) $raw,
        };

        return max(1, (int) ceil($cm));
    }

    /**
     * A variant without its own measure falls back to its product.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function measure(Model $model, string $name): array
    {
        $raw = $model->getAttribute($name.'_value');
        $unit = $model->getAttribute($name.'_unit');

        if ((! is_numeric($raw) || (float) $raw <= 0) && method_exists($model, 'product') && $model->product instanceof Model) {
            $raw = $model->product->getAttribute($name.'_value');
            $unit = $model->product->getAttribute($name.'_unit');
        }

        return [$raw, $unit];
    }

    /**
     * The order's shipping address with the neutral destination keys
     * (destination_id / destination_driver / pin_point) from the order
     * metadata, as CreateOrder stored them.
     *
     * @return array<string, mixed>
     */
    private function shippingAddress(Order $order): array
    {
        $address = $order->shippingAddress;
        $metadata = $order->getAttribute('metadata');
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $metadata = is_array($metadata) ? $metadata : [];
        $stored = is_array($metadata['shipping_address'] ?? null) ? $metadata['shipping_address'] : [];

        $fields = [
            'first_name' => $address?->first_name ?? ($stored['first_name'] ?? null),
            'last_name' => $address?->last_name ?? ($stored['last_name'] ?? null),
            'street_address' => $address?->street_address ?? ($stored['street_address'] ?? null),
            'street_address_plus' => $address?->street_address_plus ?? ($stored['street_address_plus'] ?? null),
            'postal_code' => $address?->postal_code ?? ($stored['postal_code'] ?? null),
            'city' => $address?->city ?? ($stored['city'] ?? null),
            'state' => $address?->getAttribute('state') ?? ($stored['state'] ?? null),
            'phone_number' => $address?->phone_number
                ?? $address?->phone
                ?? ($stored['phone_number'] ?? null)
                ?? ($metadata['phone_number'] ?? null),
        ];

        foreach (AddressDestination::fromInput([...$metadata, ...$stored]) as $key => $value) {
            $fields[$key] = $value;
        }

        return $fields;
    }

    private function markPickupRequested(OrderShipment $shipment): void
    {
        $shipment->refresh();
        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];

        if (filled($metadata[ShipmentMetadata::PICKUP_REQUESTED_AT] ?? null)) {
            return;
        }

        $metadata[ShipmentMetadata::PICKUP_REQUESTED_AT] = now()->toIso8601String();
        $shipment->forceFill(['metadata' => $metadata])->save();
    }

    private function recordError(OrderShipment $shipment, string $message): void
    {
        $fresh = OrderShipment::query()->find($shipment->id) ?? $shipment;
        $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
        $metadata[ShipmentMetadata::FULFILLMENT_ERROR] = $message;
        $bucket = is_array($metadata['biteship'] ?? null) ? $metadata['biteship'] : [];
        $bucket['fulfillment_failed_at'] = now()->toIso8601String();
        $metadata['biteship'] = $bucket;

        $fresh->forceFill(['metadata' => $metadata])->save();
    }

    private function clearError(OrderShipment $shipment): void
    {
        $fresh = OrderShipment::query()->find($shipment->id);

        if (! $fresh instanceof OrderShipment || ! is_array($fresh->metadata)) {
            return;
        }

        $metadata = $fresh->metadata;

        if (! array_key_exists(ShipmentMetadata::FULFILLMENT_ERROR, $metadata) && ! isset($metadata['biteship']['fulfillment_failed_at'])) {
            return;
        }

        unset($metadata[ShipmentMetadata::FULFILLMENT_ERROR], $metadata['biteship']['fulfillment_failed_at']);
        $fresh->forceFill(['metadata' => $metadata])->save();
    }

    private function pin(mixed $lat, mixed $lng): ?string
    {
        return is_numeric($lat) && is_numeric($lng) ? $lat.','.$lng : null;
    }

    private function postalCode(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^\d{5}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  list<mixed>  $parts
     */
    private function joined(array $parts, string $glue = ', '): string
    {
        return implode($glue, array_map(
            static fn (mixed $part): string => trim((string) $part),
            array_filter($parts, static fn (mixed $part): bool => is_scalar($part) && trim((string) $part) !== ''),
        ));
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function required(mixed $value, string $field): string
    {
        return $this->string($value) ?? throw new RuntimeException("{$field} is required for Biteship.");
    }

    private function phone(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value !== null ? (preg_replace('/[^0-9+]/', '', $value) ?: null) : null;
    }

    private function requiredPhone(mixed $value, string $field): string
    {
        $phone = $this->phone($value);

        if ($phone === null || preg_match('/^\+?[0-9]{8,16}$/', $phone) !== 1) {
            throw new RuntimeException("{$field} must be a valid phone number for Biteship.");
        }

        return $phone;
    }
}
