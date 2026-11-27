<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Jobs\FulfillOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderPaymentType;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Order;
use Throwable;

/**
 * Once an order is paid, hand each warehouse shipment that has no AWB yet to
 * the shipping driver that owns it (FulfillsShipments). Shipments of manual
 * carriers / Shopper zone options are left to staff (Shopper's own label and
 * AWB flow). Idempotent: labelled or cancelled shipments are skipped and the
 * queued job is unique per shipment, so OrderPaid fired by both a webhook and
 * Shopper's cpanel "mark paid" never registers a shipment twice.
 *
 * HTTP requests run the job after the response (same PHP process) so a
 * missing queue worker cannot leave a paid order without an AWB; the queue is
 * the retry fallback.
 */
final readonly class DispatchShipmentFulfillment
{
    public function __construct(private ShippingCapabilities $capabilities) {}

    public function handle(Order $order): void
    {
        $run = function () use ($order): void {
            $order = $order->fresh() ?? $order;

            if (! OrderPaymentType::canFulfill($order)) {
                return;
            }

            if ($this->capabilities->fulfillerForOrder($order) === null) {
                return;
            }

            $shipments = resolve(EnsureOrderShipments::class)->handle($order);

            foreach ($shipments as $shipment) {
                if ($this->needsFulfillment($shipment)) {
                    $this->dispatchOne((int) $shipment->id);
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);

            return;
        }

        $run();
    }

    public function needsFulfillment(OrderShipment $shipment): bool
    {
        if (trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '') {
            return false;
        }

        if (strtolower((string) $shipment->status) === NormalizeShipmentStatus::CANCELLED) {
            return false;
        }

        if (is_numeric(data_get($shipment->metadata, ShipmentMetadata::RESHIPPED_TO_SHIPMENT_ID))) {
            $orderShipments = OrderShipment::query()
                ->where('order_id', $shipment->order_id)
                ->get(['id', 'metadata']);

            if (ShipmentMetadata::isSupersededByReship($shipment, $orderShipments)) {
                return false;
            }
        }

        return $this->capabilities->fulfillerFor($shipment) !== null;
    }

    public function dispatchOne(int $shipmentId): void
    {
        if (app()->runningUnitTests() || app()->runningInConsole()) {
            FulfillOrderShipment::dispatch($shipmentId);

            return;
        }

        app()->terminating(function () use ($shipmentId): void {
            try {
                resolve(FulfillOrderShipment::class, ['orderShipmentId' => $shipmentId])
                    ->handle($this->capabilities);
            } catch (Throwable $e) {
                report($e);
                FulfillOrderShipment::dispatch($shipmentId);
            }
        });
    }
}
