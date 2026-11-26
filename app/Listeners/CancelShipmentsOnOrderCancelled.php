<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Order;
use Throwable;

/**
 * A cancelled order cancels the provider orders behind its warehouse
 * shipments, each through the configured driver that can (CancelsShipments).
 * Drivers make this idempotent; manual shipments have nothing to cancel.
 */
final readonly class CancelShipmentsOnOrderCancelled
{
    public function __construct(private ShippingCapabilities $capabilities) {}

    public function handle(OrderCancelled $event): void
    {
        $order = $event->order instanceof Order
            ? $event->order
            : Order::query()->find($event->order->id);

        if (! $order instanceof Order) {
            return;
        }

        foreach (OrderShipment::query()->where('order_id', $order->id)->get() as $shipment) {
            $driver = $this->capabilities->forShipmentSupporting($shipment, CancelsShipments::class);

            if ($driver === null) {
                continue;
            }

            $hasAwb = trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '';

            if (ShipmentMetadata::externalReference($shipment) === null && ! $hasAwb) {
                $status = strtolower(trim((string) $shipment->status));

                if (! in_array($status, [NormalizeShipmentStatus::CANCELLED, NormalizeShipmentStatus::DELIVERED], true)) {
                    $shipment->forceFill(['status' => NormalizeShipmentStatus::CANCELLED])->save();
                }

                continue;
            }

            try {
                $driver->cancelShipment($shipment);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
