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

// Finishing parcels: a previously selected provider that still holds a key
// keeps its in-flight shipments moving (ShippingCapabilities::services()).
$shippingServices = static fn (string $contract): Closure => static fn (): bool => resolve(ShippingCapabilities::class)->services($contract);

/*
 | Every lifecycle command talks to providers (bookings, quota-limited APIs):
 | a slow run must never overlap the next one, and with several app servers
 | only one runs it (onOneServer needs the shared database / redis cache).
 | The overlap mutex expires (minutes) so a killed run cannot block the
 | schedule for the default 24 hours.
 */
Schedule::command('shipping:fulfill-paid')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->when($shippingServices(FulfillsShipments::class));

Schedule::command('shipping:refresh-tracking')
    ->hourly()
    ->withoutOverlapping(120)
    ->onOneServer()
    ->when($shippingServices(FulfillsShipments::class));

Schedule::command('shipping:sync-carriers')
    ->dailyAt('00:15')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->when($shippingSupports(SyncsCarriers::class));

// Runs while a ready (switched on + configured) driver ExpiresPayments, or a
// switched-off / unconfigured one still has unpaid orders to expire locally.
Schedule::command('orders:expire-unpaid')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60)
    ->onOneServer()
    ->when(static fn (): bool => ExpireUnpaidOrders::hasWork());

Schedule::command('shopper:prune-carts')
    ->dailyAt('02:30')
    ->withoutOverlapping(60)
    ->onOneServer();

// Provider-specific schedules (e.g. komerce:capabilities) are registered by
// the provider's own service provider, see app/Integrations/*.
