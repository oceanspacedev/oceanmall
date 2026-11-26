<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Orders\GenerateInvoiceNumber;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Order;

/**
 * Ensures every paid order receives `paid_at`, a sequential `invoice_number`,
 * and `net_settlement` regardless of whether payment arrived via gateway
 * webhook, reconcile, proof verification, COD remittance, or cpanel Mark Paid.
 */
final class RecordOrderPaidFinancials
{
    public function __construct(private readonly GenerateInvoiceNumber $invoices) {}

    public function handle(OrderPaid $event): void
    {
        $orderId = $event->order instanceof Order ? $event->order->id : ($event->order->id ?? null);
        if (! $orderId) {
            return;
        }

        DB::transaction(function () use ($orderId): void {
            $locked = Order::query()->lockForUpdate()->find($orderId);

            if (! $locked instanceof Order || $locked->payment_status !== PaymentStatus::Paid) {
                return;
            }

            $updates = [];

            if ($locked->paid_at === null) {
                $updates['paid_at'] = now();
                $locked->paid_at = $updates['paid_at'];
            }

            if (! is_string($locked->invoice_number) || trim($locked->invoice_number) === '') {
                $updates['invoice_number'] = $this->invoices->handle($locked);
            }

            if ($locked->net_settlement === null) {
                $fee = (int) ($locked->gateway_fee ?? 0);
                $updates['net_settlement'] = max(0, (int) $locked->price_amount - $fee);
            }

            if ($updates !== []) {
                $locked->forceFill($updates)->save();
            }
        });
    }
}
