<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderNote;
use App\Models\OrderShipment;
use App\Models\User;
use App\Shipping\ShipmentMetadata;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Models\Order;

/**
 * Clones a returned / failed-delivery shipment into a fresh pending shipment so
 * warehouse staff can issue a new AWB without colliding with provider order IDs
 * or double-charging the customer in OrderSummary.
 */
final class ReshipOrderShipment
{
    public function handle(OrderShipment $failedShipment, ?User $actor = null): OrderShipment
    {
        return DB::transaction(function () use ($failedShipment, $actor): OrderShipment {
            $locked = OrderShipment::query()
                ->with(['lines', 'order'])
                ->lockForUpdate()
                ->findOrFail($failedShipment->id);

            $status = strtolower(trim((string) $locked->status));

            if (! in_array($status, [
                NormalizeShipmentStatus::RETURNED,
                NormalizeShipmentStatus::DELIVERY_FAILED,
            ], true)) {
                throw new RuntimeException('Hanya pengiriman berstatus gagal kirim atau retur (RTS) yang dapat dikirim ulang.');
            }

            $order = $locked->order;

            if (! $order instanceof Order || in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Archived], true)) {
                throw new RuntimeException('Pesanan ini sudah dibatalkan atau diarsipkan.');
            }

            if ($locked->lines->isEmpty()) {
                throw new RuntimeException('Shipment tidak memiliki item untuk dikirim ulang.');
            }

            $originalMeta = is_array($locked->metadata) ? $locked->metadata : [];

            $reship = OrderShipment::query()->create([
                'order_id' => $locked->order_id,
                'inventory_id' => $locked->inventory_id,
                'driver' => $locked->driver,
                'carrier_id' => $locked->carrier_id,
                'carrier_code' => $locked->carrier_code,
                'carrier_name' => $locked->carrier_name,
                'service_code' => $locked->service_code,
                'service_name' => $locked->service_name,
                'cost' => 0,
                'currency_code' => $locked->currency_code,
                'status' => NormalizeShipmentStatus::PENDING,
                'metadata' => array_filter([
                    ShipmentMetadata::RESHIP_OF_SHIPMENT_ID => (int) $locked->id,
                    'rate' => is_array($originalMeta['rate'] ?? null) ? $originalMeta['rate'] : null,
                ], static fn (mixed $v): bool => $v !== null),
            ]);

            foreach ($locked->lines as $line) {
                $reship->lines()->create([
                    'purchasable_type' => $line->purchasable_type,
                    'purchasable_id' => $line->purchasable_id,
                    'qty' => $line->qty,
                ]);
            }

            $originalMeta[ShipmentMetadata::RESHIPPED_TO_SHIPMENT_ID] = (int) $reship->id;
            $locked->forceFill(['metadata' => $originalMeta])->save();

            OrderNote::query()->create([
                'order_id' => $order->id,
                'user_id' => $actor?->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => sprintf(
                    'Pengiriman ulang dibuat (Shipment #%d dari Shipment #%d yang %s).',
                    $reship->id,
                    $locked->id,
                    $status,
                ),
                'metadata' => [
                    'source_shipment_id' => $locked->id,
                    'reship_shipment_id' => $reship->id,
                ],
            ]);

            resolve(SyncOrderShippingFromShipments::class)->handle($order->refresh());

            return $reship;
        });
    }
}
