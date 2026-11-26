<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\OrderNote;
use App\Models\OrderReturnRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;

final class SubmitReturnRequest
{
    public const WINDOW_DAYS = 7;

    public function canRequest(Order $order): bool
    {
        if (! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            return false;
        }

        $eligibleStatus = $order->status === OrderStatus::Completed
            || in_array($order->shipping_status, [ShippingStatus::Delivered, ShippingStatus::PartiallyDelivered], true);

        if (! $eligibleStatus) {
            return false;
        }

        $referenceDate = $order->updated_at ?? $order->created_at;

        return $referenceDate === null || $referenceDate->greaterThanOrEqualTo(now()->subDays(self::WINDOW_DAYS));
    }

    /**
     * @param  list<array{order_item_id: int, qty: int}>  $requestedItems
     * @param  list<UploadedFile>  $evidenceFiles
     */
    public function handle(
        Order $order,
        User $customer,
        string $reason,
        ?string $description,
        array $requestedItems,
        array $evidenceFiles = [],
    ): OrderReturnRequest {
        if ((int) $order->customer_id !== (int) $customer->id) {
            abort(403);
        }

        if (! $this->canRequest($order)) {
            throw ValidationException::withMessages([
                'return' => __('Pesanan ini belum selesai dikirim atau sudah melewati batas waktu pengajuan retur (7 hari).'),
            ]);
        }

        $order->loadMissing('items');
        $itemsById = $order->items->keyBy('id');

        $alreadyRequestedQty = [];
        OrderReturnRequest::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [OrderReturnRequest::STATUS_REJECTED])
            ->get()
            ->each(static function (OrderReturnRequest $existing) use (&$alreadyRequestedQty): void {
                foreach ((array) $existing->items as $row) {
                    $id = (int) ($row['order_item_id'] ?? 0);
                    $qty = (int) ($row['qty'] ?? 0);
                    if ($id > 0 && $qty > 0) {
                        $alreadyRequestedQty[$id] = ($alreadyRequestedQty[$id] ?? 0) + $qty;
                    }
                }
            });

        $normalizedItems = [];
        foreach ($requestedItems as $entry) {
            $itemId = (int) ($entry['order_item_id'] ?? 0);
            $qty = (int) ($entry['qty'] ?? 0);
            $orderItem = $itemsById->get($itemId);

            if ($orderItem === null || $qty < 1) {
                throw ValidationException::withMessages([
                    'items' => __('Item retur atau jumlah tidak valid untuk pesanan ini.'),
                ]);
            }

            $remaining = (int) $orderItem->quantity - ($alreadyRequestedQty[$itemId] ?? 0);
            if ($qty > $remaining) {
                throw ValidationException::withMessages([
                    'items' => __('Jumlah retur untuk :name melebihi sisa qty yang tersedia (:remaining).', [
                        'name' => $orderItem->name,
                        'remaining' => max(0, $remaining),
                    ]),
                ]);
            }

            $alreadyRequestedQty[$itemId] = ($alreadyRequestedQty[$itemId] ?? 0) + $qty;
            $normalizedItems[] = [
                'order_item_id' => $itemId,
                'name' => $orderItem->name,
                'sku' => $orderItem->sku,
                'qty' => $qty,
                'unit_price_amount' => (int) $orderItem->unit_price_amount,
            ];
        }

        if ($normalizedItems === []) {
            throw ValidationException::withMessages([
                'items' => __('Pilih minimal satu item untuk diajukan retur.'),
            ]);
        }

        $evidencePaths = [];
        foreach ($evidenceFiles as $file) {
            if ($file instanceof UploadedFile) {
                $evidencePaths[] = $file->store('return-evidence/'.$order->id, 'local');
            }
        }

        return DB::transaction(static function () use ($order, $customer, $reason, $description, $normalizedItems, $evidencePaths): OrderReturnRequest {
            $returnRequest = OrderReturnRequest::query()->create([
                'order_id' => $order->id,
                'user_id' => $customer->id,
                'status' => OrderReturnRequest::STATUS_REQUESTED,
                'reason' => trim($reason),
                'description' => $description !== null ? trim($description) : null,
                'items' => $normalizedItems,
                'evidence_paths' => $evidencePaths,
            ]);

            OrderNote::query()->create([
                'order_id' => $order->id,
                'user_id' => $customer->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => sprintf('Pelanggan mengajukan retur/RMA #%d (%s).', $returnRequest->id, trim($reason)),
                'metadata' => ['return_request_id' => $returnRequest->id],
            ]);

            return $returnRequest;
        });
    }
}
