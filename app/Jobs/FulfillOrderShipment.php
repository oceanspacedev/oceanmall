<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OrderShipment;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShippingCapabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Register one paid warehouse shipment with the shipping provider that owns
 * it (App\Shipping\Contracts\FulfillsShipments), whichever provider that is.
 * The driver records its own errors on the shipment and throws so the queue
 * retries.
 *
 * ShouldBeUnique only dedupes queue entries; the booking itself goes through
 * App\Shipping\ShipmentFulfillment, which locks the shipment against the
 * inline after-response run and the shipping:fulfill-paid sweep and re-checks,
 * on every attempt, that the order is still open and the shipment unlabelled.
 */
final class FulfillOrderShipment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $orderShipmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderShipmentId;
    }

    /**
     * @return string one of the ShipmentFulfillment outcomes (BOOKED, SKIPPED,
     *                BUSY, NO_DRIVER), for callers that run the job inline
     */
    public function handle(ShippingCapabilities $capabilities): string
    {
        return ShipmentFulfillment::run($this->orderShipmentId, static function (OrderShipment $shipment) use ($capabilities): bool {
            $driver = $capabilities->fulfillerFor($shipment);

            if ($driver === null) {
                return false;
            }

            $driver->fulfillShipment($shipment);

            return true;
        });
    }
}
