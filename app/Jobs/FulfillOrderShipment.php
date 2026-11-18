<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OrderShipment;
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

    public function handle(ShippingCapabilities $capabilities): void
    {
        $shipment = OrderShipment::query()->find($this->orderShipmentId);

        if (! $shipment instanceof OrderShipment) {
            return;
        }

        if (trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '') {
            return;
        }

        $capabilities->fulfillerFor($shipment)?->fulfillShipment($shipment);
    }
}
