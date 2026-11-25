<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Models\OrderShipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderCompleted;
use Shopper\Core\Models\Order;

final readonly class ConfirmOrderReceived
{
    public function __construct(private SyncOrderShippingFromShipments $syncOrderShipping) {}

    public function handle(Order $order): Order
    {
        if ($order->status === OrderStatus::Completed && $order->shipping_status?->value === 'delivered') {
            return $order;
        }

        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'received' => __('This order was cancelled and cannot be confirmed as received.'),
            ]);
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'received' => __('Payment must be completed before confirming delivery.'),
            ]);
        }

        $shipments = OrderShipment::query()->where('order_id', $order->id)->get();

        if ($shipments->isEmpty()) {
            throw ValidationException::withMessages([
                'received' => __('This order has no shipments to confirm.'),
            ]);
        }

        // A cancelled parcel is never coming and is never marked delivered.
        $live = $shipments->reject(static fn (OrderShipment $shipment): bool => $shipment->status === NormalizeShipmentStatus::CANCELLED)->values();

        // Shipped: it carries an airway bill, or its status says it left the
        // warehouse. When staff marked the whole order shipped by hand
        // (Shopper's own label / AWB entry is copied to one parcel only),
        // every parcel counts as shipped.
        $orderShipped = in_array($order->shipping_status, [ShippingStatus::Shipped, ShippingStatus::PartiallyDelivered, ShippingStatus::Delivered], true);
        $shipped = static fn (OrderShipment $shipment): bool => $orderShipped
            || (is_string($shipment->awb) && trim($shipment->awb) !== '')
            || (is_string($shipment->tracking_number) && trim($shipment->tracking_number) !== '')
            || in_array($shipment->status, [NormalizeShipmentStatus::PICKED_UP, NormalizeShipmentStatus::IN_TRANSIT, NormalizeShipmentStatus::DELIVERED], true);

        if ($live->isEmpty() || ! $live->contains($shipped)) {
            throw ValidationException::withMessages([
                'received' => __('Shipments must have an airway bill before you can confirm receipt.'),
            ]);
        }

        // The order is received when EVERY parcel arrived: one that has not
        // even shipped yet (a second warehouse) cannot be confirmed with it.
        if (! $live->every($shipped)) {
            throw ValidationException::withMessages([
                'received' => __('Some packages of this order have not been shipped yet. You can confirm receipt once all of them have arrived.'),
            ]);
        }

        return DB::transaction(function () use ($order, $live): Order {
            foreach ($live as $shipment) {
                $shipment->forceFill(['status' => NormalizeShipmentStatus::DELIVERED])->save();
            }

            // Every live parcel is delivered now: the sync moves the order to
            // delivered / completed and fires OrderCompleted.
            $order = $this->syncOrderShipping->handle($order->refresh());

            // The customer said so: the order is completed also where the
            // sync does not do it on its own (a cancelled parcel next to the
            // delivered ones, an order staff had archived).
            if ($order->status !== OrderStatus::Completed) {
                $order->forceFill(['status' => OrderStatus::Completed])->save();
                event(new OrderCompleted($order));
            }

            return $order;
        });
    }
}
