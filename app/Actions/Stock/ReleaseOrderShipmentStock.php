<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Shopper\Core\Models\Order;

final class ReleaseOrderShipmentStock
{
    /**
     * Gives reserved shipment lines back to their warehouses once (either for the
     * whole order or for a subset of unshipped shipments in split-warehouse orders).
     *
     * @param  iterable<int, OrderShipment>|null  $onlyShipments
     */
    public function handle(Order $order, ?iterable $onlyShipments = null): void
    {
        $metadata = $this->metadata($order);

        // Legacy rows recorded the release under metadata.komerce.
        $released = data_get($metadata, 'stock_released_at')
            ?? data_get($metadata, 'komerce.stock_released_at');

        if (is_string($released) && $released !== '') {
            return;
        }

        $releasedIds = array_map('intval', (array) ($metadata['released_shipment_ids'] ?? []));

        $allShipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->with('lines')
            ->get();

        $activeShipments = $allShipments->reject(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED,
        )->values();

        if ($onlyShipments === null) {
            // When active shipments exist, cancelled historical shipments were
            // superseded and must never double-release or release stock held
            // by an already-shipped (AWB-bearing) active shipment.
            $targets = $activeShipments->isNotEmpty()
                ? $activeShipments->reject(
                    static fn (OrderShipment $shipment): bool => trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '',
                )->values()
                : $allShipments->reject(
                    static fn (OrderShipment $shipment): bool => filled(data_get($shipment->metadata, 'reshipped_to_shipment_id')),
                )->values();
        } else {
            $onlyIds = collect($onlyShipments)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $targets = ($activeShipments->isNotEmpty() ? $activeShipments : $allShipments)
                ->whereIn('id', $onlyIds)
                ->values();
        }

        if ($targets->isEmpty() && $activeShipments->isNotEmpty()) {
            return;
        }

        foreach ($targets as $shipment) {
            if (in_array((int) $shipment->id, $releasedIds, true)) {
                continue;
            }

            foreach ($shipment->lines as $line) {
                $this->releaseLine($shipment, $line, $order);
            }

            $releasedIds[] = (int) $shipment->id;
        }

        // Merge into the existing metadata (address snapshot, payment refs):
        // Shopper's Order does not cast it, so it is stored as JSON text.
        $metadata['released_shipment_ids'] = array_values(array_unique($releasedIds));

        $expectedScope = $activeShipments->isNotEmpty() ? $activeShipments : $allShipments;
        if (($onlyShipments === null && $targets->count() === $expectedScope->count()) || count($metadata['released_shipment_ids']) >= $expectedScope->count()) {
            $metadata['stock_released_at'] = now()->toIso8601String();
        }

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
