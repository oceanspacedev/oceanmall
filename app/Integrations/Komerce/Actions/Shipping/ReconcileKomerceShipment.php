<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Actions\Shipping;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Integrations\Komerce\Support\KomerceShipments;
use App\Models\OrderShipment;
use App\Support\OrderShipmentOpsPresenter;
use RuntimeException;
use Shopper\Core\Models\Order;

/**
 * Copy Komerce Shipping Delivery detail onto the local shipment.
 * Komerce is the source of truth for order_no, AWB, and courier status.
 */
final readonly class ReconcileKomerceShipment
{
    public function __construct(
        private ShippingDeliveryClient $delivery,
        private OrderShipmentOpsPresenter $presenter,
        private NormalizeShipmentStatus $normalizeStatus,
        private SyncOrderShippingFromShipments $syncOrderShipping,
    ) {}

    public function handle(
        OrderShipment $shipment,
        bool $verifyEvenIfUnentitled = false,
        ?string $candidateOrderNo = null,
    ): OrderShipment {
        $enabled = $verifyEvenIfUnentitled
            ? $this->deliveryKeyConfigured()
            : Komerce::shippingDeliveryEnabled();

        if (! $enabled || ! KomerceShipments::owns($shipment)) {
            return $shipment;
        }

        $persistedOrderNo = $this->presenter->deliveryOrderNo($shipment);
        $candidate = is_string($candidateOrderNo) ? trim($candidateOrderNo) : '';
        $orderNo = $persistedOrderNo ?? ($candidate !== '' ? $candidate : null);

        if ($orderNo === null) {
            return $shipment;
        }

        $detail = $this->delivery->detailOrder($orderNo, allowUnentitled: $verifyEvenIfUnentitled);
        $status = data_get($detail, 'meta.status');
        $metaCode = data_get($detail, 'meta.code');
        $detailData = data_get($detail, 'data');

        if (
            ($detail['success'] ?? null) === false
            || (is_scalar($status) && strtolower(trim((string) $status)) !== 'success')
            || (is_numeric($metaCode) && ((int) $metaCode < 200 || (int) $metaCode >= 300))
            || ! is_array($detailData)
            || $detailData === []
        ) {
            $message = data_get($detail, 'meta.message') ?? data_get($detail, 'message');

            throw new RuntimeException(sprintf(
                'Komerce detail-order failed for [%s]: %s.',
                $orderNo,
                is_scalar($message) ? (string) $message : 'unknown provider error',
            ));
        }

        $awb = $this->scalar($detail, [
            'data.awb',
            'data.airway_bill',
            'data.cnote',
        ]);

        $existingAwb = trim((string) ($shipment->awb ?: $shipment->tracking_number));
        if ($persistedOrderNo === null && $existingAwb !== '' && ($awb === null || strcasecmp($existingAwb, $awb) !== 0)) {
            throw new RuntimeException(sprintf(
                'Komerce detail-order [%s] airway bill does not match shipment [%d].',
                $orderNo,
                $shipment->id,
            ));
        }

        $remoteOrderNo = $this->scalar($detail, ['data.order_no']) ?? $orderNo;
        $remoteOrderId = $this->scalar($detail, ['data.order_id']);
        $rawStatus = $this->scalar($detail, [
            'data.last_status',
            'data.status',
            'data.order_status',
        ]);
        $normalized = $this->normalizeStatus->handle(
            $rawStatus,
            is_string($shipment->status) ? $shipment->status : null,
        );

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
        $komerce['order_no'] = $remoteOrderNo;
        $komerce['detail'] = is_array(data_get($detail, 'data')) ? data_get($detail, 'data') : $detail;
        $komerce['reconciled_at'] = now()->toIso8601String();

        if ($remoteOrderId !== null) {
            $komerce['order_id'] = $remoteOrderId;
        }

        if ($awb !== null) {
            $komerce['awb'] = $awb;
            $komerce['tracking_number'] = $awb;
        }

        if ($rawStatus !== null) {
            $komerce['tracking_status'] = $rawStatus;
        }

        $attributes = ['metadata' => array_merge($metadata, ['komerce' => $komerce])];

        if ($awb !== null) {
            $attributes['awb'] = $awb;
            $attributes['tracking_number'] = $awb;
        }

        if ($normalized !== null && $this->shouldWriteStatus($shipment->status, $normalized)) {
            $attributes['status'] = $normalized;
        }

        $shipment->forceFill($attributes)->save();

        $order = $shipment->order()->first();
        if ($order instanceof Order) {
            $this->syncOrderShipping->handle($order);
        }

        return $shipment->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     */
    private function scalar(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function shouldWriteStatus(mixed $current, string $incoming): bool
    {
        return NormalizeShipmentStatus::shouldTransition(
            is_string($current) ? $current : null,
            $incoming,
        );
    }

    private function deliveryKeyConfigured(): bool
    {
        return config('komerce.enabled') !== false
            && trim((string) config('komerce.shipping_delivery_api_key', '')) !== '';
    }
}
