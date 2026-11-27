<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Models\OrderShipment;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Poll shipments until they are delivered, in two batches of --limit each:
 *
 * 1. provider-fulfilled shipments: re-read the provider order
 *    (ReconcilesShipments), then refresh tracking;
 * 2. shipments staff shipped by hand with an AWB (manual / zone-option rows):
 *    tracked through a configured driver that TracksShipments
 *    (ShippingCapabilities::trackerFor), least recently refreshed first, so
 *    an AWB the provider cannot find rotates to the back instead of starving
 *    the batch. Each batch has its own limit, so hand-entered AWBs never
 *    crowd out provider shipments.
 *
 * As before the refactor, polling runs only while a fulfilling provider is
 * configured (on a quote-only plan the tracking quota is not spent hourly;
 * staff and customers refresh on demand). With no fulfilling driver
 * configured this is a successful no-op.
 */
final class RefreshPendingShipmentTracking extends Command
{
    protected $signature = 'shipping:refresh-tracking {--limit=50 : Max shipments to refresh per batch}';

    /** @var list<string> */
    protected $aliases = ['komerce:refresh-shipment-tracking'];

    protected $description = 'Reconcile provider shipments, then poll their tracking (and hand-entered AWBs) until delivered';

    public function handle(ShippingCapabilities $capabilities, RefreshShipmentTracking $refresh): int
    {
        if (! $capabilities->services(FulfillsShipments::class)) {
            $this->components->info('No configured shipping driver fulfils shipments; skipping tracking refresh.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $ok = 0;
        $failed = 0;

        // Provider shipments only (see FulfillPaidShipments).
        $shipments = $this->filterLiveShipments(
            $this->undelivered($capabilities->whereFulfillable(OrderShipment::query()))
                ->where(function ($query): void {
                    $this->hasAwb($query);
                    $query->orWhere(function ($registered): void {
                        $registered->whereNotNull('external_reference')->where('external_reference', '!=', '');
                    });
                })
                ->orderBy('id')
                ->limit($limit * 3)
                ->get(),
        )->take($limit);

        foreach ($shipments as $shipment) {
            $driver = $capabilities->fulfillerFor($shipment);

            if ($driver === null) {
                continue;
            }

            try {
                if ($driver instanceof ReconcilesShipments && filled($shipment->external_reference)) {
                    $shipment = $driver->reconcileShipment($shipment);
                }

                if (trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '') {
                    $refresh->handle($shipment);
                }

                $ok++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->warn("Shipment #{$shipment->id}: {$e->getMessage()}");
            }
        }

        if ($capabilities->services(TracksShipments::class)) {
            $fulfillable = $capabilities->whereFulfillable(OrderShipment::query())->select('id');

            $handEntered = $this->filterLiveShipments(
                $this->undelivered(OrderShipment::query())
                    ->whereNotIn('id', $fulfillable)
                    ->where(fn ($query) => $this->hasAwb($query))
                    ->orderBy('updated_at')
                    ->orderBy('id')
                    ->limit($limit * 3)
                    ->get(),
            )->take($limit);

            foreach ($handEntered as $shipment) {
                try {
                    $refresh->handle($shipment);
                    $ok++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                    $this->warn("Shipment #{$shipment->id}: {$e->getMessage()}");
                } finally {
                    // Rotate to the back of the next batch, changed or not.
                    OrderShipment::query()->whereKey($shipment->id)->update(['updated_at' => now()]);
                }
            }
        }

        $this->info("Refreshed {$ok} shipment(s); {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, OrderShipment>  $candidates
     * @return Collection<int, OrderShipment>
     */
    private function filterLiveShipments(Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $hasReshipPointer = $candidates->contains(
            static fn (OrderShipment $s): bool => is_numeric(data_get($s->metadata, ShipmentMetadata::RESHIPPED_TO_SHIPMENT_ID)),
        );

        if (! $hasReshipPointer) {
            return $candidates;
        }

        $orderShipmentsByOrder = OrderShipment::query()
            ->whereIn('order_id', $candidates->pluck('order_id')->unique()->all())
            ->get(['id', 'order_id', 'metadata'])
            ->groupBy('order_id');

        return $candidates->reject(static function (OrderShipment $shipment) use ($orderShipmentsByOrder): bool {
            $siblings = $orderShipmentsByOrder->get($shipment->order_id, collect());

            return ShipmentMetadata::isSupersededByReship($shipment, $siblings);
        })->values();
    }

    /**
     * @param  Builder<OrderShipment>  $query
     * @return Builder<OrderShipment>
     */
    private function undelivered(Builder $query): Builder
    {
        return $query->where(function ($query): void {
            $query->whereNull('status')
                ->orWhereNotIn('status', [
                    NormalizeShipmentStatus::DELIVERED,
                    NormalizeShipmentStatus::RETURNED,
                    NormalizeShipmentStatus::CANCELLED,
                ]);
        });
    }

    /**
     * @param  Builder<OrderShipment>  $query
     */
    private function hasAwb(Builder $query): void
    {
        $query->where(function ($awb): void {
            $awb->whereNotNull('awb')->where('awb', '!=', '');
        })->orWhere(function ($tracking): void {
            $tracking->whereNotNull('tracking_number')->where('tracking_number', '!=', '');
        });
    }
}
