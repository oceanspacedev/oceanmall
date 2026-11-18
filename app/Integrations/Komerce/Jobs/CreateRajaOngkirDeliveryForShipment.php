<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Jobs;

use App\Integrations\Komerce\Services\KomerceDeliveryFulfillment;
use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Models\OrderShipment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shopper\Shipping\Facades\Shipping;
use Throwable;

/**
 * Komerce Delivery fulfilment of one shipment, as a queue job.
 *
 * @deprecated Kept so jobs already queued by older releases still run: they
 *             were serialized as App\Jobs\CreateRajaOngkirDeliveryForShipment,
 *             which KomerceServiceProvider::LEGACY_CLASSES aliases to this class.
 *             New code dispatches App\Jobs\FulfillOrderShipment, which routes
 *             the shipment to its own driver (FulfillsShipments); the Store
 *             Order logic lives in App\Integrations\Komerce\Services\KomerceDeliveryFulfillment.
 */
final class CreateRajaOngkirDeliveryForShipment implements ShouldBeUnique, ShouldQueue
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

    public function handle(): void
    {
        $driver = Shipping::driver(KomerceShippingDriver::CODE);

        if (! $driver instanceof KomerceShippingDriver || ! $driver->isConfigured()) {
            return;
        }

        $driver->fulfillShipment(OrderShipment::query()->findOrFail($this->orderShipmentId));
    }

    public function failed(?Throwable $exception): void
    {
        resolve(KomerceDeliveryFulfillment::class)->recordFailure(
            $this->orderShipmentId,
            $exception?->getMessage() ?? 'Komerce delivery job failed.',
        );
    }
}
