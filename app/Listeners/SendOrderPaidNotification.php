<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Enums\OrderNotificationType;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Order;
use Throwable;

/**
 * Tell the customer their order is paid, whoever confirmed it: a gateway
 * webhook / reconcile (MarkOrderPaid) or staff with Shopper's cpanel
 * "mark paid". NotifyOrderCustomer sends each type once per order.
 */
final class SendOrderPaidNotification
{
    public function __construct(private readonly NotifyOrderCustomer $notify) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order instanceof Order
            ? $event->order
            : Order::query()->find($event->order->id);

        if (! $order instanceof Order) {
            return;
        }

        // This listener runs before the ones that fulfil the paid order: a
        // notification that cannot be stored or mailed must not stop them.
        try {
            $this->notify->handle($order->refresh(), OrderNotificationType::Paid);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
