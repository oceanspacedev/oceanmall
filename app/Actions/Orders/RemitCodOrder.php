<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\OrderNote;
use App\Models\User;
use App\Support\OrderPaymentType;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderCompleted;
use Shopper\Core\Models\Order;

/**
 * Settles a COD order after cash has been collected/remitted by the courier,
 * keeping delivery confirmation separate from payment remittance until both exist.
 */
final readonly class RemitCodOrder
{
    public function __construct(private MarkOrderPaid $markOrderPaid) {}

    public function handle(Order $order, User $actor, ?string $reference = null, ?string $notes = null): Order
    {
        if (! OrderPaymentType::isCod($order)) {
            throw new RuntimeException('Hanya pesanan COD yang dapat direkonsiliasi melalui pelunasan COD.');
        }

        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Archived], true)) {
            throw new RuntimeException('Pesanan sudah dibatalkan atau diarsipkan.');
        }

        $ref = trim((string) $reference) !== '' ? trim((string) $reference) : 'cod-remit-'.$order->number;

        $outcome = $this->markOrderPaid->handle(
            order: $order,
            driver: 'manual',
            reference: $ref,
            confirmedAmount: (int) $order->price_amount,
            transactionMetadata: array_filter([
                'cod_remittance' => true,
                'remitted_by' => $actor->id,
                'notes' => $notes,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        );

        if ($outcome === MarkOrderPaid::CANCELLED_ORDER) {
            throw new RuntimeException('Pesanan batal atau dibatalkan pembayarannya.');
        }

        return DB::transaction(static function () use ($order, $actor, $ref, $notes): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $completedNow = false;

            $updates = [
                'cod_remitted_at' => $locked->cod_remitted_at ?? now(),
                'cod_remitted_by' => $locked->cod_remitted_by ?? $actor->id,
            ];

            if ($locked->shipping_status === ShippingStatus::Delivered
                && $locked->status !== OrderStatus::Completed) {
                $updates['status'] = OrderStatus::Completed;
                $completedNow = true;
            }

            $locked->forceFill($updates)->save();

            OrderNote::query()->create([
                'order_id' => $locked->id,
                'user_id' => $actor->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => trim('Dana COD dikonfirmasi diterima (Ref: '.$ref.'). '.($notes ?? '')),
                'metadata' => ['cod_remitted' => true, 'reference' => $ref],
            ]);

            $fresh = $locked->refresh();

            if ($completedNow) {
                event(new OrderCompleted($fresh));
            }

            return $fresh;
        });
    }
}
