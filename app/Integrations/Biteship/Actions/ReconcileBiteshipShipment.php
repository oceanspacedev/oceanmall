<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Actions;

use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Biteship\Services\BiteshipClient;
use App\Integrations\Biteship\Support\BiteshipShipments;
use App\Integrations\Biteship\Support\BiteshipStatus;
use App\Integrations\Biteship\Support\BiteshipTrackingHistory;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use Shopper\Core\Models\Order;

/**
 * Copy a Biteship order (GET /v1/orders/{id}, or the create-order answer)
 * onto the local shipment. Biteship is the source of truth for the waybill,
 * the tracking id, the order status and the final price:
 *
 *   order_shipments.external_reference  Biteship order id
 *   order_shipments.awb / tracking_number  courier.waybill_id (also when the courier changes it)
 *   order_shipments.status              BiteshipStatus, forward-only (cancellation wins)
 *   metadata.biteship                   order_id, tracking_id, status, price, raw order
 *
 * Idempotent: applying the same answer twice changes nothing.
 */
final readonly class ReconcileBiteshipShipment
{
    public function __construct(
        private BiteshipClient $client,
        private SyncOrderShippingFromShipments $syncOrderShipping,
    ) {}

    public function handle(OrderShipment $shipment): OrderShipment
    {
        // Never a Komerce / other provider order number (BiteshipShipments).
        $orderId = BiteshipShipments::orderId($shipment);

        if ($orderId === null) {
            return $shipment;
        }

        return $this->apply($shipment, $this->client->order($orderId));
    }

    /**
     * @param  array<string, mixed>  $order  A Biteship order object
     */
    public function apply(OrderShipment $shipment, array $order): OrderShipment
    {
        $shipment->refresh();

        $orderId = self::scalar($order['id'] ?? null) ?? ShipmentMetadata::externalReference($shipment);
        $waybill = self::scalar(data_get($order, 'courier.waybill_id'));
        $trackingId = self::scalar(data_get($order, 'courier.tracking_id'));
        $rawStatus = self::scalar($order['status'] ?? null);
        $normalized = BiteshipStatus::normalize($rawStatus);

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['biteship'] ?? null) ? $metadata['biteship'] : [];

        $bucket = array_filter([
            ...$bucket,
            'order_id' => $orderId,
            'tracking_id' => $trackingId ?? ($bucket['tracking_id'] ?? null),
            'waybill_id' => $waybill ?? ($bucket['waybill_id'] ?? null),
            'courier_company' => self::scalar(data_get($order, 'courier.company')) ?? ($bucket['courier_company'] ?? null),
            'courier_type' => self::scalar(data_get($order, 'courier.type')) ?? ($bucket['courier_type'] ?? null),
            'status' => $rawStatus ?? ($bucket['status'] ?? null),
            'price' => is_numeric($order['price'] ?? null) ? (int) $order['price'] : ($bucket['price'] ?? null),
            'link' => self::scalar(data_get($order, 'courier.link')) ?? ($bucket['link'] ?? null),
            'order' => $order,
            'reconciled_at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null);

        if (isset($bucket['price']) && (int) $bucket['price'] !== (int) $shipment->cost) {
            // Biteship bills its own (possibly re-weighed) price; the customer
            // paid the quoted cost. Kept for staff, never charged again.
            $bucket['price_differs_from_cost'] = true;
        } else {
            unset($bucket['price_differs_from_cost']);
        }

        $metadata['biteship'] = $bucket;

        $history = $this->historyFromOrder($order);

        if ($history !== []) {
            $metadata[ShipmentMetadata::TRACKING_HISTORY] = $history;
        }

        $attributes = ['metadata' => $metadata];

        if ($orderId !== null && $shipment->getAttribute('external_reference') !== $orderId) {
            $attributes['external_reference'] = $orderId;
        }

        if (! filled($shipment->getAttribute('driver'))) {
            $attributes['driver'] = 'biteship';
        }

        if ($waybill !== null) {
            $attributes['awb'] = $waybill;
            $attributes['tracking_number'] = $waybill;
        }

        if ($normalized !== null && BiteshipStatus::shouldReplace($shipment->status, $normalized)) {
            $attributes['status'] = $normalized;
        }

        $shipment->forceFill($attributes)->save();

        $parent = $shipment->order()->first();

        if ($parent instanceof Order) {
            $this->syncOrderShipping->handle($parent);
        }

        return $shipment->refresh();
    }

    /**
     * courier.history of an order detail, newest first, in the neutral
     * tracking_history shape.
     *
     * @param  array<string, mixed>  $order
     * @return list<array{description: string, datetime: string|null, location: string|null}>
     */
    private function historyFromOrder(array $order): array
    {
        $entries = data_get($order, 'courier.history');

        return is_array($entries) ? BiteshipTrackingHistory::fromEntries($entries) : [];
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
