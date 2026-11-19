<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Actions;

use App\Actions\Shipping\RefreshShipmentTracking;
use App\Integrations\Biteship\Biteship;
use App\Models\OrderShipment;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Apply one authenticated Biteship webhook (order.status, order.waybill_id,
 * order.price). The payload is only a signal: the order is re-read with
 * GET /v1/orders/{id} (ReconcileBiteshipShipment) and its tracking refreshed
 * before anything changes locally, so a forged or stale payload cannot move
 * an order.
 *
 * Idempotent: the reconcile is state based (forward-only statuses, same
 * waybill), and an identical payload seen within the dedupe window is
 * answered `duplicate` without calling Biteship again.
 */
final readonly class ApplyBiteshipWebhook
{
    private const DEDUPE_MINUTES = 10;

    public function __construct(
        private ReconcileBiteshipShipment $reconcile,
        private RefreshShipmentTracking $refreshTracking,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return 'handled'|'duplicate'|'not_found'|'ignored'
     */
    public function handle(array $payload): string
    {
        $event = is_scalar($payload['event'] ?? null) ? trim((string) $payload['event']) : '';
        $orderId = is_scalar($payload['order_id'] ?? null) ? trim((string) $payload['order_id']) : '';

        if (! in_array($event, ['order.status', 'order.waybill_id', 'order.price'], true) || $orderId === '') {
            return 'ignored';
        }

        $fingerprint = 'biteship:webhook:'.sha1((string) json_encode($payload));

        if (Cache::has($fingerprint)) {
            return 'duplicate';
        }

        $shipment = $this->findShipment($orderId);

        if ($shipment === null) {
            // An order created in the Biteship dashboard, or by another store.
            return 'not_found';
        }

        $shipment = $this->reconcile->handle($shipment);

        if (trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '') {
            try {
                $shipment = $this->refreshTracking->handle($shipment);
            } catch (Throwable $e) {
                // The order itself is reconciled; the hourly refresh retries tracking.
                report($e);
            }
        }

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $bucket = is_array($metadata['biteship'] ?? null) ? $metadata['biteship'] : [];
        $bucket['webhook'] = [
            'event' => $event,
            'reported_status' => is_scalar($payload['status'] ?? null) ? (string) $payload['status'] : null,
            'received_at' => now()->toIso8601String(),
        ];
        $metadata['biteship'] = $bucket;
        $shipment->forceFill(['metadata' => $metadata])->save();

        Cache::put($fingerprint, true, now()->addMinutes(self::DEDUPE_MINUTES));

        return 'handled';
    }

    private function findShipment(string $orderId): ?OrderShipment
    {
        return OrderShipment::query()
            ->where('external_reference', $orderId)
            ->where(static function ($query): void {
                $query->where('driver', Biteship::CODE)->orWhereNotNull('metadata->biteship->order_id');
            })
            ->orderByDesc('id')
            ->first();
    }
}
