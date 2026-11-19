<?php

declare(strict_types=1);

use App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController;
use App\Integrations\KiriminAja\Http\Middleware\AcknowledgeDisabledKiriminAjaWebhook;
use Illuminate\Support\Facades\Route;

/*
 | KiriminAja callbacks (one URL per account, registered with
 | `php artisan kiriminaja:set-callback`). Loaded by KiriminAjaServiceProvider
 | inside the `web` middleware group (CSRF is skipped for webhooks/*). Keep the
 | URL and name stable: the URL is stored at KiriminAja.
 */
Route::post('webhooks/kiriminaja', KiriminAjaWebhookController::class)
    ->middleware([AcknowledgeDisabledKiriminAjaWebhook::class, 'throttle:300,1'])
    ->name('webhooks.kiriminaja.callback');
