<?php

declare(strict_types=1);

namespace App\Actions\Cpanel;

use App\Actions\Orders\RecordOrderRefund;
use App\Models\OrderNote;
use App\Models\OrderReturnRequest;
use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderRefund;

final readonly class ProcessReturnRequest
{
    public function __construct(private RecordOrderRefund $recordRefund) {}

    public function approve(OrderReturnRequest $returnRequest, User $actor, ?string $notes = null): OrderReturnRequest
    {
        $returnRequest->forceFill([
            'status' => OrderReturnRequest::STATUS_APPROVED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'resolution_notes' => $notes,
        ])->save();

        OrderNote::query()->create([
            'order_id' => $returnRequest->order_id,
            'user_id' => $actor->id,
            'type' => OrderNote::TYPE_AUDIT,
            'body' => sprintf('Permintaan retur #%d disetujui.', $returnRequest->id),
        ]);

        return $returnRequest->refresh();
    }

    public function reject(OrderReturnRequest $returnRequest, User $actor, string $reason): OrderReturnRequest
    {
        $returnRequest->forceFill([
            'status' => OrderReturnRequest::STATUS_REJECTED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'resolution_notes' => trim($reason),
        ])->save();

        OrderNote::query()->create([
            'order_id' => $returnRequest->order_id,
            'user_id' => $actor->id,
            'type' => OrderNote::TYPE_AUDIT,
            'body' => sprintf('Permintaan retur #%d ditolak: %s', $returnRequest->id, trim($reason)),
        ]);

        return $returnRequest->refresh();
    }

    public function restock(OrderReturnRequest $returnRequest, User $actor, ?int $inventoryId = null): OrderReturnRequest
    {
        return DB::transaction(static function () use ($returnRequest, $actor, $inventoryId): OrderReturnRequest {
            $locked = OrderReturnRequest::query()->lockForUpdate()->findOrFail($returnRequest->id);

            if ($locked->restocked_at !== null) {
                return $locked;
            }

            $order = Order::query()->with('items')->lockForUpdate()->findOrFail($locked->order_id);
            $itemsById = $order->items->keyBy('id');
            $shipments = OrderShipment::query()->with('lines')->where('order_id', $order->id)->get();

            foreach ((array) $locked->items as $entry) {
                $itemId = (int) ($entry['order_item_id'] ?? 0);
                $qty = (int) ($entry['qty'] ?? 0);
                /** @var OrderItem|null $orderItem */
                $orderItem = $itemsById->get($itemId);

                if ($orderItem === null || $qty <= 0) {
                    continue;
                }

                $targetInventory = $inventoryId ?? self::resolveShipmentInventory($shipments, $orderItem);
                if ($targetInventory <= 0) {
                    throw new RuntimeException('Gudang tujuan restock tidak ditemukan.');
                }

                $purchasable = self::resolvePurchasable($orderItem->product_type, (int) $orderItem->product_id);
                if ($purchasable !== null && method_exists($purchasable, 'mutateStock')) {
                    $purchasable->mutateStock(
                        $targetInventory,
                        $qty,
                        $qty,
                        'rma_restock',
                        sprintf('Restock retur RMA #%d', $locked->id),
                        $actor->id,
                        $order,
                    );
                }
            }

            $locked->forceFill([
                'status' => OrderReturnRequest::STATUS_RESTOCKED,
                'received_at' => $locked->received_at ?? now(),
                'restocked_at' => now(),
                'restock_inventory_id' => $inventoryId,
            ])->save();

            $orderQty = (int) $order->items->sum('quantity');
            $returnedQty = array_sum(array_map(static fn (array $i): int => (int) ($i['qty'] ?? 0), (array) $locked->items));

            $order->forceFill([
                'shipping_status' => $returnedQty >= $orderQty ? ShippingStatus::Returned : ShippingStatus::PartiallyReturned,
            ])->save();

            OrderNote::query()->create([
                'order_id' => $order->id,
                'user_id' => $actor->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => sprintf('Barang retur #%d diterima & dikembalikan ke stok gudang.', $locked->id),
                'metadata' => ['return_request_id' => $locked->id, 'inventory_id' => $inventoryId],
            ]);

            return $locked->refresh();
        });
    }

    public function refund(
        OrderReturnRequest $returnRequest,
        User $actor,
        int $amount,
        string $method = 'manual_bank_transfer',
        ?string $reference = null,
    ): OrderRefund {
        $order = Order::query()->findOrFail($returnRequest->order_id);

        $refund = $this->recordRefund->handle(
            order: $order,
            amount: $amount,
            reason: 'Refund RMA #'.$returnRequest->id.' — '.$returnRequest->reason,
            actor: $actor,
            method: $method,
            reference: $reference,
            returnRequestId: $returnRequest->id,
        );

        $returnRequest->forceFill([
            'status' => OrderReturnRequest::STATUS_REFUNDED,
        ])->save();

        return $refund;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrderShipment>  $shipments
     */
    private static function resolveShipmentInventory($shipments, OrderItem $orderItem): int
    {
        foreach ($shipments as $shipment) {
            /** @var OrderShipmentLine|null $match */
            $match = $shipment->lines->first(
                static fn (OrderShipmentLine $l): bool => (int) $l->purchasable_id === (int) $orderItem->product_id
                    && (string) $l->purchasable_type === (string) $orderItem->product_type,
            );

            if ($match !== null && (int) $shipment->inventory_id > 0) {
                return (int) $shipment->inventory_id;
            }
        }

        return (int) ($shipments->first()?->inventory_id ?? 0);
    }

    private static function resolvePurchasable(mixed $type, int $id): ?Model
    {
        if (! is_string($type) || $type === '' || $id <= 0) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($id);
    }
}
