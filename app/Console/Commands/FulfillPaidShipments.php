<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FulfillOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShippingCapabilities;
use Illuminate\Console\Command;
use Shopper\Core\Enum\PaymentStatus;
use Throwable;

/**
 * Retry net for paid orders: every unlabelled shipment of a paid, still open
 * order goes to the driver that fulfils it (FulfillsShipments). Manual
 * shipments (Shopper zone options) are left to staff. With no fulfilling
 * driver configured this is a successful no-op.
 *
 * Never books a shipment of a cancelled / completed / archived, refunded or
 * already shipped order, a cancelled or picked-up shipment, or a shipment
 * allocated more than --max-age days ago (staff ship those by hand; a provider
 * that becomes available later must not book historic parcels). Each shipment is booked
 * under App\Shipping\ShipmentFulfillment's per-shipment lock, re-checked
 * after the lock is taken, so overlapping runs, the paid-order job and this
 * sweep never book one shipment twice.
 */
final class FulfillPaidShipments extends Command
{
    protected $signature = 'shipping:fulfill-paid
        {--limit=50 : Max unlabelled paid shipments to fulfil}
        {--max-age=30 : Only shipments allocated within this many days (0 = no limit)}';

    /** @var list<string> */
    protected $aliases = ['komerce:fulfill-paid-orders'];

    protected $description = 'Register paid shipments that still have no airway bill with their shipping provider';

    public function handle(ShippingCapabilities $capabilities): int
    {
        if (! $capabilities->supports(FulfillsShipments::class)) {
            $this->components->info('No configured shipping driver fulfils shipments; paid shipments are left to staff.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $maxAgeDays = max(0, (int) $this->option('max-age'));

        // Only shipments a configured driver fulfils count against the limit:
        // manual / zone-option rows never get an AWB here and would otherwise
        // fill every batch.
        $shipments = ShipmentFulfillment::scopeEligible($capabilities->whereFulfillable(OrderShipment::query()))
            ->whereHas('order', static function ($query): void {
                $query->where('payment_status', PaymentStatus::Paid);
            })
            ->when($maxAgeDays > 0, static function ($query) use ($maxAgeDays): void {
                // Shipments are created when the order is placed (and an
                // unpaid order expires within a day): a row older than this
                // predates the retry net (e.g. shipped by hand on a plan
                // without the provider's booking API) and is left to staff.
                $query->where('created_at', '>=', now()->subDays($maxAgeDays));
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $ok = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($shipments as $shipmentId) {
            try {
                // The paid-order job's own work, inline: same per-shipment
                // lock and re-check as the queued / after-response runs.
                $outcome = (new FulfillOrderShipment((int) $shipmentId))->handle($capabilities);
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->warn("Shipment #{$shipmentId}: {$e->getMessage()}");

                continue;
            }

            $outcome === ShipmentFulfillment::BOOKED ? $ok++ : $skipped++;
        }

        $this->info("Fulfilled {$ok} paid shipment(s); {$failed} failed; {$skipped} left to staff.");

        return self::SUCCESS;
    }
}
