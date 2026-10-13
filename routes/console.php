<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('komerce:fulfill-paid-orders')->everyFiveMinutes();

Schedule::command('komerce:refresh-shipment-tracking')->hourly();

// Learn what the Collaborator account is entitled to, then align the courier list with it.
Schedule::command('komerce:capabilities')->dailyAt('00:05');
Schedule::command('komerce:sync-carriers')->dailyAt('00:15');

Schedule::command('komerce:expire-unpaid-orders')->everyFifteenMinutes();
