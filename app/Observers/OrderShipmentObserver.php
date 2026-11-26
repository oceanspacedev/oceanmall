<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Shipping\DispatchShipmentFulfillment;
use App\Models\OrderShipment;
use App\Support\OrderPaymentType;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Enum\OrderStatus;
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
        if (! $order instanceof Order
            || ! OrderPaymentType::canFulfill($order)
            || in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Completed, OrderStatus::Archived], true)) {
            return;
        }

        $shipmentId = (int) $shipment->id;

        DB::afterCommit(static function () use ($shipmentId): void {
            $fresh = OrderShipment::query()->with('order')->find($shipmentId);
            if (! $fresh instanceof OrderShipment || $fresh->awb || $fresh->tracking_number) {
                return;
            }

            $freshOrder = $fresh->order;
            if (! $freshOrder instanceof Order
                || ! OrderPaymentType::canFulfill($freshOrder)
                || in_array($freshOrder->status, [OrderStatus::Cancelled, OrderStatus::Completed, OrderStatus::Archived], true)) {
                return;
            }

            $dispatcher = resolve(DispatchShipmentFulfillment::class);

            if ($dispatcher->needsFulfillment($fresh)) {
                $dispatcher->dispatchOne($shipmentId);
            }
        });
    }
}
