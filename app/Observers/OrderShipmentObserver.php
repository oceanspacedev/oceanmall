<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Shipping\DispatchShipmentFulfillment;
use App\Models\OrderShipment;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

/**
 * A shipment created for an already-paid order (allocation override, lazy
 * EnsureOrderShipments) is handed to its provider straight away.
 */
final class OrderShipmentObserver
{
    public function created(OrderShipment $shipment): void
    {
        if (app()->runningUnitTests() || $shipment->awb || $shipment->tracking_number) {
            return;
        }

        $order = $shipment->order;
        if (! $order instanceof Order || $order->payment_status !== PaymentStatus::Paid) {
            return;
        }

        $dispatcher = resolve(DispatchShipmentFulfillment::class);

        if ($dispatcher->needsFulfillment($shipment)) {
            $dispatcher->dispatchOne((int) $shipment->id);
        }
    }
}
