<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Stock\ReleaseOrderShipmentStock;
use App\Enums\OrderNotificationType;
use App\Models\OrderShipment;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\PaymentActions;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\ShippingCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;

/**
 * Staff "cancel order" (every order, provider-routed or manual / COD), routed
 * by the order's own drivers:
 *
 * - unpaid with a payment driver that ExpiresPayments: the driver cancels the
 *   remote payment first (and refuses when the provider reports it paid);
 * - otherwise the order is cancelled here: an unpaid payment is voided and,
 *   unless goods already left the warehouse (hasShipped()), the reserved
 *   stock is released (once) and shipments no provider handles are marked
 *   cancelled.
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
        private ShippingCapabilities $shipping,
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
                $result = $driver->expireUnpaidOrder($order, 'Cancelled by staff');

                // Not every driver's own cancel closes the parcels no
                // provider ships (manual / zone-option carriers).
                if ($result->status === OrderStatus::Cancelled) {
                    $shipments = OrderShipment::query()->where('order_id', $result->id)->get();

                    if (! $this->parcelLeftWarehouse($result, $shipments)) {
                        $this->cancelUnshippedLocalShipments($shipments);
                    }
                }

                return $result;
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

            if (! $this->parcelLeftWarehouse($order, $shipments)) {
                $this->releaseStock->handle($order->refresh());
                $this->cancelUnshippedLocalShipments($shipments);
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

    /**
     * Whether goods of this order already left the warehouse, so cancelling
     * it must not put them back into stock: a warehouse shipment has an
     * airway bill, or staff recorded a Shopper shipping label with a tracking
     * number or a status past "pending" (picked up, in transit, delivered...).
     * The label's tracking number is optional and is mirrored onto the
     * warehouse shipment only when that shipment is unambiguous, so the label
     * itself is checked too.
     */
    public function hasShipped(Order $order): bool
    {
        return $this->parcelLeftWarehouse(
            $order,
            OrderShipment::query()->where('order_id', $order->id)->get(),
        );
    }

    /**
     * @param  Collection<int, OrderShipment>  $shipments
     */
    private function parcelLeftWarehouse(Order $order, Collection $shipments): bool
    {
        $hasAirwayBill = $shipments->contains(
            static fn (OrderShipment $shipment): bool => trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '',
        );

        if ($hasAirwayBill) {
            return true;
        }

        return OrderShipping::query()
            ->where('order_id', $order->id)
            ->where(static function (Builder $query): void {
                $query
                    ->where(static function (Builder $tracked): void {
                        $tracked->whereNotNull('tracking_number')->where('tracking_number', '!=', '');
                    })
                    ->orWhere(static function (Builder $moved): void {
                        $moved->whereNotNull('status')->where('status', '!=', ShipmentStatus::Pending->value);
                    });
            })
            ->exists();
    }

    /**
     * Shipments no configured driver can cancel (manual / zone-option
     * carriers) are closed here, so the customer and the warehouse no longer
     * see a parcel waiting for a label. Provider shipments are left to their
     * driver (CancelShipmentsOnOrderCancelled).
     *
     * @param  Collection<int, OrderShipment>  $shipments
     */
    private function cancelUnshippedLocalShipments(Collection $shipments): void
    {
        foreach ($shipments as $shipment) {
            $status = strtolower(trim((string) $shipment->status));

            if (in_array($status, [NormalizeShipmentStatus::CANCELLED, NormalizeShipmentStatus::DELIVERED], true)) {
                continue;
            }

            if ($this->shipping->forShipmentSupporting($shipment, CancelsShipments::class) !== null) {
                continue;
            }

            $shipment->forceFill(['status' => NormalizeShipmentStatus::CANCELLED])->save();
        }
    }
}
