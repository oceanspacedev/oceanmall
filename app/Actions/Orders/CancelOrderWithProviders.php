<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Stock\ReleaseOrderShipmentStock;
use App\Enums\OrderNotificationType;
use App\Models\OrderShipment;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\PaymentActions;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Order;

/**
 * Staff "cancel order" for an order a provider is involved in, routed by the
 * order's own drivers:
 *
 * - unpaid with a payment driver that ExpiresPayments: the driver cancels the
 *   remote payment first (and refuses when the provider reports it paid);
 * - otherwise the order is cancelled here, stock is released unless a parcel
 *   already has an AWB.
 *
 * Shopper's OrderCancelled is then fired after the transaction; its listener
 * CancelShipmentsOnOrderCancelled cancels every provider shipment whose
 * driver CancelsShipments (the single place that does it).
 */
final readonly class CancelOrderWithProviders
{
    public function __construct(
        private PaymentActions $payments,
        private ReleaseOrderShipmentStock $releaseStock,
        private NotifyOrderCustomer $notifyOrderCustomer,
    ) {}

    /**
     * @param  bool  $throughPaymentProvider  False cancels an unpaid order here
     *                                        without asking its payment driver (orders:expire-unpaid for
     *                                        a driver that is switched off or unconfigured)
     */
    public function handle(Order $order, bool $throughPaymentProvider = true): Order
    {
        if ($order->status === OrderStatus::Cancelled) {
            event(new OrderCancelled($order));

            return $order;
        }

        $paid = $order->payment_status === PaymentStatus::Paid;

        if (! $paid && $throughPaymentProvider) {
            $driver = $this->payments->driver($order);

            if ($driver instanceof ExpiresPayments && $this->payments->reconciler($order) !== null) {
                return $driver->expireUnpaidOrder($order, 'Cancelled by staff');
            }
        }

        $cancelled = DB::transaction(function () use ($order, $paid, $throughPaymentProvider): ?Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === OrderStatus::Cancelled) {
                return null;
            }

            // An automatic expiry never cancels an order paid meanwhile.
            if (! $throughPaymentProvider && ! $paid && $order->payment_status === PaymentStatus::Paid) {
                return null;
            }

            $updates = [
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ];

            if (! $paid && $order->payment_status !== PaymentStatus::Paid) {
                $updates['payment_status'] = PaymentStatus::Voided;
            }

            $order->forceFill($updates)->save();

            $shipments = OrderShipment::query()->where('order_id', $order->id)->get();
            $hasAirwayBill = $shipments->contains(
                static fn (OrderShipment $shipment): bool => trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '',
            );

            if (! $hasAirwayBill) {
                $this->releaseStock->handle($order->refresh());
            }

            return $order->refresh();
        });

        if ($cancelled === null) {
            return $order->refresh();
        }

        // Outside the transaction that held the order lock: provider HTTP calls
        // happen in the OrderCancelled listener (CancelShipmentsOnOrderCancelled),
        // once, through each shipment's CancelsShipments driver.
        $this->notifyOrderCustomer->handle($cancelled, OrderNotificationType::Cancelled);
        event(new OrderCancelled($cancelled));

        return $cancelled;
    }
}
