<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use App\Support\OrderPaymentType;
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
        if ($order->shipping_status === ShippingStatus::Delivered) {
            if ($order->payment_status === PaymentStatus::Paid && $order->status !== OrderStatus::Completed) {
                $order->forceFill(['status' => OrderStatus::Completed])->save();
                event(new OrderCompleted($order));
            }

            return $order;
        }

        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'received' => __('This order was cancelled and cannot be confirmed as received.'),
            ]);
        }

        $isPaid = $order->payment_status === PaymentStatus::Paid;

        if (! $isPaid && ! OrderPaymentType::isCod($order)) {
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

        // Cancelled parcels and returned/failed parcels already superseded by a
        // reshipment never arrive; unreshipped returned/failed parcels must go
        // through RTS/reship first.
        $live = $shipments->reject(
            static fn (OrderShipment $shipment): bool => strtolower((string) $shipment->status) === NormalizeShipmentStatus::CANCELLED
                || ShipmentMetadata::isSupersededByReship($shipment, $shipments),
        )->values();

        if (in_array($order->shipping_status, [ShippingStatus::Returned, ShippingStatus::PartiallyReturned], true)
            && $live->count() === $shipments->reject(static fn (OrderShipment $s): bool => strtolower((string) $s->status) === NormalizeShipmentStatus::CANCELLED)->count()) {
            throw ValidationException::withMessages([
                'received' => __('Paket telah dikembalikan ke pengirim dan tidak dapat dikonfirmasi diterima.'),
            ]);
        }

        if ($live->contains(static fn (OrderShipment $s): bool => in_array(
            strtolower((string) $s->status),
            [NormalizeShipmentStatus::RETURNED, NormalizeShipmentStatus::DELIVERY_FAILED],
            true,
        ))) {
            throw ValidationException::withMessages([
                'received' => __('Paket yang gagal kirim atau diretur tidak dapat dikonfirmasi diterima.'),
            ]);
        }

        // Shipped: it carries an airway bill (staff or the provider entered it).
        $shipped = static fn (OrderShipment $shipment): bool => (is_string($shipment->awb) && trim($shipment->awb) !== '')
            || (is_string($shipment->tracking_number) && trim($shipment->tracking_number) !== '');

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

        return DB::transaction(function () use ($order, $live, $isPaid): Order {
            foreach ($live as $shipment) {
                $shipment->forceFill(['status' => NormalizeShipmentStatus::DELIVERED])->save();
            }

            // Every live parcel is delivered now: moves shipping_status to delivered,
            // and marks Completed only when payment is already Paid (unpaid COD waits for remittance).
            $order = $this->syncOrderShipping->handle($order->refresh());

            if ($isPaid && $order->status !== OrderStatus::Completed) {
                $order->forceFill(['status' => OrderStatus::Completed])->save();
                event(new OrderCompleted($order));
            }

            return $order;
        });
    }
}
