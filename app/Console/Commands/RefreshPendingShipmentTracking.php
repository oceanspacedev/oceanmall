<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Models\OrderShipment;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\ShippingCapabilities;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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
        if (! $capabilities->supports(FulfillsShipments::class)) {
            $this->components->info('No configured shipping driver fulfils shipments; skipping tracking refresh.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $ok = 0;
        $failed = 0;

        // Provider shipments only (see FulfillPaidShipments).
        $shipments = $this->undelivered($capabilities->whereFulfillable(OrderShipment::query()))
            ->where(function ($query): void {
                $this->hasAwb($query);
                $query->orWhere(function ($registered): void {
                    $registered->whereNotNull('external_reference')->where('external_reference', '!=', '');
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

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

        if ($capabilities->supports(TracksShipments::class)) {
            $fulfillable = $capabilities->whereFulfillable(OrderShipment::query())->select('id');

            $handEntered = $this->undelivered(OrderShipment::query())
                ->whereNotIn('id', $fulfillable)
                ->where(fn ($query) => $this->hasAwb($query))
                ->orderBy('updated_at')
                ->orderBy('id')
                ->limit($limit)
                ->get();

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
     * @param  Builder<OrderShipment>  $query
     * @return Builder<OrderShipment>
     */
    private function undelivered(Builder $query): Builder
    {
        return $query->where(function ($query): void {
            $query->whereNull('status')
                ->orWhereNotIn('status', [
                    NormalizeShipmentStatus::DELIVERED,
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
