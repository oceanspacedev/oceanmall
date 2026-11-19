<?php

declare(strict_types=1);

use App\Integrations\Biteship\Http\Controllers\BiteshipWebhookController;
use App\Integrations\Biteship\Http\Middleware\AcknowledgeDisabledBiteshipWebhook;
use Illuminate\Support\Facades\Route;

/*
 | Biteship webhooks. Loaded by BiteshipServiceProvider inside the `web`
 | middleware group (CSRF is skipped for webhooks/* in bootstrap/app.php).
 | The URL is registered in the Biteship dashboard: keep it stable. While the
 | module is off it answers 200 {status: disabled}.
 */
Route::post('webhooks/biteship', BiteshipWebhookController::class)
    ->middleware(['throttle:120,1', AcknowledgeDisabledBiteshipWebhook::class])
    ->name('webhooks.biteship.order');
