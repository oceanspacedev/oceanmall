<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Shipping\DispatchShipmentFulfillment;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Order;

/**
 * Payment and shipping meet only here: a paid order's warehouse shipments go
 * to the shipping driver that owns them (FulfillsShipments), whichever
 * payment driver (or staff) confirmed the payment.
 */
final class FulfillShipmentsOnOrderPaid
{
    public function __construct(private readonly DispatchShipmentFulfillment $dispatch) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order instanceof Order
            ? $event->order
            : Order::query()->find($event->order->id);

        if ($order instanceof Order) {
            $this->dispatch->handle($order);
        }
    }
}
