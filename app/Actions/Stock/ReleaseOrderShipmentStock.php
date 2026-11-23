<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Shopper\Core\Models\Order;

final class ReleaseOrderShipmentStock
{
    /**
     * Gives the order's reserved shipment lines back to their warehouses once:
     * metadata.stock_released_at marks the release, so a second call (a
     * retried cancel, an expiry after a staff cancel) changes nothing.
     */
    public function handle(Order $order): void
    {
        $metadata = $this->metadata($order);

        // Legacy rows recorded the release under metadata.komerce.
        $released = data_get($metadata, 'stock_released_at')
            ?? data_get($metadata, 'komerce.stock_released_at');

        if (is_string($released) && $released !== '') {
            return;
        }

        $shipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->with('lines')
            ->get();

        foreach ($shipments as $shipment) {
            foreach ($shipment->lines as $line) {
                $this->releaseLine($shipment, $line, $order);
            }
        }

        // Merge into the existing metadata (address snapshot, payment refs):
        // Shopper's Order does not cast it, so it is stored as JSON text.
        $metadata['stock_released_at'] = now()->toIso8601String();
        $order->forceFill(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Order $order): array
    {
        $metadata = $order->getAttribute('metadata');

        if (is_string($metadata) && trim($metadata) !== '') {
            $metadata = json_decode($metadata, true);
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function releaseLine(OrderShipment $shipment, OrderShipmentLine $line, Order $order): void
    {
        $purchasable = $this->resolvePurchasable($line);

        if ($purchasable === null || ! method_exists($purchasable, 'mutateStock')) {
            return;
        }

        $inventoryId = (int) $shipment->inventory_id;
        $qty = (int) $line->qty;

        if ($inventoryId <= 0 || $qty <= 0) {
            return;
        }

        $purchasable->mutateStock(
            $inventoryId,
            $qty,
            0,
            'payment_expired',
            'Release stock after unpaid order expired or was cancelled',
            null,
            $order,
        );
    }

    private function resolvePurchasable(OrderShipmentLine $line): ?Model
    {
        $type = (string) $line->purchasable_type;
        $id = (int) $line->purchasable_id;

        if ($type === '' || $id <= 0) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        /** @var Model|null $model */
        $model = $class::query()->find($id);

        return $model;
    }
}
