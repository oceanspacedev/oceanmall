<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Stock\ReleaseOrderShipmentStock;
use App\Enums\OrderNotificationType;
use App\Models\OrderNote;
use App\Models\OrderShipment;
use App\Models\User;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\PaymentActions;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
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
    public function handle(
        Order $order,
        bool $throughPaymentProvider = true,
        string $reason = 'Cancelled by staff',
        ?User $actor = null,
    ): Order {
        if ($order->status === OrderStatus::Cancelled) {
            event(new OrderCancelled($order));

            return $order;
        }

        $paid = $order->payment_status === PaymentStatus::Paid;

        if (! $paid && $throughPaymentProvider) {
            $driver = $this->payments->driver($order);

            if ($driver instanceof ExpiresPayments && $this->payments->reconciler($order) !== null) {
                $result = $driver->expireUnpaidOrder($order, $reason);

                // Not every driver's own cancel closes the parcels no
                // provider ships (manual / zone-option carriers).
                if ($result->status === OrderStatus::Cancelled) {
                    $this->closeUnshippedLocalShipments($result);

                    if (! OrderNote::query()
                        ->where('order_id', $result->id)
                        ->where('type', OrderNote::TYPE_AUDIT)
                        ->where('body', 'like', 'Pesanan dibatalkan:%')
                        ->exists()) {
                        OrderNote::query()->create([
                            'order_id' => $result->id,
                            'user_id' => $actor?->id,
                            'type' => OrderNote::TYPE_AUDIT,
                            'body' => 'Pesanan dibatalkan: '.$reason,
                            'metadata' => ['reason' => $reason],
                        ]);
                    }
                }

                return $result;
            }
        }

        $cancelled = DB::transaction(function () use ($order, $paid, $throughPaymentProvider, $reason, $actor): ?Order {
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

            if (! $paid && ! in_array($order->payment_status, [
                PaymentStatus::Paid,
                PaymentStatus::PartiallyRefunded,
                PaymentStatus::Refunded,
            ], true)) {
                $updates['payment_status'] = PaymentStatus::Voided;
            }

            $order->forceFill($updates)->save();

            $shipments = OrderShipment::query()->where('order_id', $order->id)->get();
            $activeShipments = $this->activeShipments($shipments);
            $cancelledShipments = $this->cancelledShipments($shipments);

            if ($activeShipments->isNotEmpty()) {
                $unshipped = $this->unshippedShipments($order, $activeShipments, $cancelledShipments);

                if ($unshipped->isNotEmpty()) {
                    $releaseScope = $unshipped->count() === $activeShipments->count() ? null : $unshipped;
                    $this->releaseStock->handle($order->refresh(), $releaseScope);
                    $this->cancelUnshippedLocalShipments($unshipped);
                }
            } elseif ($cancelledShipments->isNotEmpty() && ! $this->unmappedMovedOrderShippingExists($order, $cancelledShipments)) {
                $this->releaseStock->handle($order->refresh());
            }

            OrderNote::query()->create([
                'order_id' => $order->id,
                'user_id' => $actor?->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => 'Pesanan dibatalkan: '.$reason,
                'metadata' => ['reason' => $reason],
            ]);

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
     * it must not put them back into stock.
     */
    public function hasShipped(Order $order): bool
    {
        $shipments = OrderShipment::query()->where('order_id', $order->id)->get();
        $activeShipments = $this->activeShipments($shipments);

        if ($activeShipments->isEmpty()) {
            return $this->unmappedMovedOrderShippingExists(
                $order,
                $shipments->isEmpty() ? null : $this->cancelledShipments($shipments),
            );
        }

        return $this->unshippedShipments($order, $activeShipments, $this->cancelledShipments($shipments))->count() < $activeShipments->count();
    }

    public function closeUnshippedLocalShipments(Order $order): void
    {
        $shipments = OrderShipment::query()->where('order_id', $order->id)->get();
        $activeShipments = $this->activeShipments($shipments);

        if ($activeShipments->isEmpty()) {
            return;
        }

        $unshipped = $this->unshippedShipments($order, $activeShipments, $this->cancelledShipments($shipments));

        if ($unshipped->isNotEmpty()) {
            $this->cancelUnshippedLocalShipments($unshipped);
        }
    }

    /**
     * @param  Collection<int, OrderShipment>  $shipments
     * @return Collection<int, OrderShipment>
     */
    private function activeShipments(Collection $shipments): Collection
    {
        return $shipments->reject(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED,
        )->values();
    }

    /**
     * @param  Collection<int, OrderShipment>  $shipments
     * @return Collection<int, OrderShipment>
     */
    private function cancelledShipments(Collection $shipments): Collection
    {
        return $shipments->filter(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED,
        )->values();
    }

    /**
     * Per-shipment determination for split-warehouse orders: a shipped Warehouse A
     * keeps its stock & label untouched while an unshipped Warehouse B can still
     * be released and cancelled. Unmapped Shopper OrderShipping rows that moved
     * stay conservatively locked.
     *
     * @param  Collection<int, OrderShipment>  $activeShipments
     * @param  Collection<int, OrderShipment>  $cancelledShipments
     * @return Collection<int, OrderShipment>
     */
    private function unshippedShipments(Order $order, Collection $activeShipments, Collection $cancelledShipments): Collection
    {
        if ($activeShipments->isEmpty()) {
            return new Collection;
        }

        $shippings = OrderShipping::query()->where('order_id', $order->id)->get();
        $order->loadMissing('items');

        $movedShippings = $shippings->filter(static function (OrderShipping $row): bool {
            $hasTracking = trim((string) $row->tracking_number) !== '';
            $status = $row->status instanceof ShipmentStatus ? $row->status->value : (string) $row->status;

            return $hasTracking || ($status !== '' && $status !== ShipmentStatus::Pending->value);
        });

        $mappedShipmentIds = [];

        foreach ($movedShippings as $row) {
            $tracking = strtolower(trim((string) $row->tracking_number));
            $matchedByTracking = $tracking !== ''
                ? $activeShipments->first(static fn (OrderShipment $s): bool => in_array(
                    $tracking,
                    [strtolower(trim((string) $s->awb)), strtolower(trim((string) $s->tracking_number))],
                    true,
                ))
                : null;

            if ($matchedByTracking instanceof OrderShipment) {
                $mappedShipmentIds[] = (int) $matchedByTracking->id;

                continue;
            }

            if ($tracking !== '' && $cancelledShipments->contains(static fn (OrderShipment $s): bool => in_array(
                $tracking,
                [strtolower(trim((string) $s->awb)), strtolower(trim((string) $s->tracking_number))],
                true,
            ))) {
                continue;
            }

            $linkedItems = $order->items->where('order_shipping_id', $row->id);
            if ($linkedItems->isNotEmpty()) {
                foreach ($activeShipments as $s) {
                    $s->loadMissing('lines');
                    foreach ($s->lines as $line) {
                        if ($linkedItems->contains(static fn ($item): bool => (int) $item->product_id === (int) $line->purchasable_id && (string) $item->product_type === (string) $line->purchasable_type)) {
                            $mappedShipmentIds[] = (int) $s->id;
                        }
                    }
                }

                continue;
            }

            // Unmapped moved OrderShipping (no tracking & no item link, e.g. PickedUp without tracking):
            // conservatively treat the whole order as having left the warehouse so stock isn't double-released.
            return new Collection;
        }

        $mappedShipmentIds = array_values(array_unique($mappedShipmentIds));

        return $activeShipments->reject(static function (OrderShipment $shipment) use ($mappedShipmentIds): bool {
            if (trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '') {
                return true;
            }

            return in_array((int) $shipment->id, $mappedShipmentIds, true);
        })->values();
    }

    /**
     * @param  Collection<int, OrderShipment>|null  $cancelledShipments
     */
    private function unmappedMovedOrderShippingExists(Order $order, ?Collection $cancelledShipments = null): bool
    {
        $cancelledTrackings = $cancelledShipments?->flatMap(static fn (OrderShipment $s): array => [
            strtolower(trim((string) $s->awb)),
            strtolower(trim((string) $s->tracking_number)),
        ])->filter()->unique()->values()->all() ?? [];

        return OrderShipping::query()
            ->where('order_id', $order->id)
            ->where(static function ($query): void {
                $query
                    ->where(static function ($tracked): void {
                        $tracked->whereNotNull('tracking_number')->where('tracking_number', '!=', '');
                    })
                    ->orWhere(static function ($moved): void {
                        $moved->whereNotNull('status')->where('status', '!=', ShipmentStatus::Pending->value);
                    });
            })
            ->get()
            ->contains(static function (OrderShipping $row) use ($cancelledTrackings): bool {
                if ($cancelledTrackings === []) {
                    return true;
                }

                $tracking = strtolower(trim((string) $row->tracking_number));

                return $tracking === '' || ! in_array($tracking, $cancelledTrackings, true);
            });
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

            if ($this->shipping->forShipmentSupporting($shipment, CancelsShipments::class) !== null
                && ShipmentMetadata::externalReference($shipment) !== null) {
                continue;
            }

            $shipment->forceFill(['status' => NormalizeShipmentStatus::CANCELLED])->save();
        }
    }
}
