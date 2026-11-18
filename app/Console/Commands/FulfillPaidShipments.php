<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\ShippingCapabilities;
use Illuminate\Console\Command;
use Shopper\Core\Enum\PaymentStatus;
use Throwable;

/**
 * Retry net for paid orders: every unlabelled, non-cancelled shipment of a
 * paid order goes to the driver that fulfils it (FulfillsShipments). Manual
 * shipments (Shopper zone options) are left to staff. With no fulfilling
 * driver configured this is a successful no-op.
 */
final class FulfillPaidShipments extends Command
{
    protected $signature = 'shipping:fulfill-paid {--limit=50 : Max unlabelled paid shipments to fulfil}';

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

        // Only shipments a configured driver fulfils count against the limit:
        // manual / zone-option rows never get an AWB here and would otherwise
        // fill every batch.
        $shipments = $capabilities->whereFulfillable(OrderShipment::query())
            ->whereHas('order', static function ($query): void {
                $query->where('payment_status', PaymentStatus::Paid);
            })
            ->where(function ($query): void {
                $query->whereNull('awb')->orWhere('awb', '');
            })
            ->where(function ($query): void {
                $query->whereNull('tracking_number')->orWhere('tracking_number', '');
            })
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', NormalizeShipmentStatus::CANCELLED);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $ok = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($shipments as $shipment) {
            $driver = $capabilities->fulfillerFor($shipment);

            if ($driver === null) {
                $skipped++;

                continue;
            }

            try {
                $driver->fulfillShipment($shipment);
                $ok++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->warn("Shipment #{$shipment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Fulfilled {$ok} paid shipment(s); {$failed} failed; {$skipped} left to staff.");

        return self::SUCCESS;
    }
}
