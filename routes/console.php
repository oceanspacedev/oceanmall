<?php

declare(strict_types=1);

use App\Console\Commands\ExpireUnpaidOrders;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\ShippingCapabilities;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Order lifecycle schedules are provider-neutral: each runs only while some
 | configured Shopper driver has the capability (the commands themselves are
 | also a successful no-op without one), so a keyless store schedules no work.
 */
$shippingSupports = static fn (string $contract): Closure => static fn (): bool => resolve(ShippingCapabilities::class)->supports($contract);

Schedule::command('shipping:fulfill-paid')
    ->everyFiveMinutes()
    ->when($shippingSupports(FulfillsShipments::class));

Schedule::command('shipping:refresh-tracking')
    ->hourly()
    ->when($shippingSupports(FulfillsShipments::class));

Schedule::command('shipping:sync-carriers')
    ->dailyAt('00:15')
    ->when($shippingSupports(SyncsCarriers::class));

// Runs while a ready (switched on + configured) driver ExpiresPayments, or a
// switched-off / unconfigured one still has unpaid orders to expire locally.
Schedule::command('orders:expire-unpaid')
    ->everyFifteenMinutes()
    ->when(static fn (): bool => ExpireUnpaidOrders::hasWork());

// Provider-specific schedules (e.g. komerce:capabilities) are registered by
// the provider's own service provider, see app/Integrations/*.
