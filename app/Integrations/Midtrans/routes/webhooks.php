<?php

declare(strict_types=1);

use App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController;
use App\Integrations\Midtrans\Http\Middleware\AcknowledgeDisabledMidtransWebhook;
use Illuminate\Support\Facades\Route;

/*
 | Midtrans HTTP notifications. Loaded by MidtransServiceProvider inside the
 | `web` middleware group (CSRF is skipped for webhooks/* in bootstrap/app.php).
 | The URL is entered in the Midtrans dashboard: keep it stable. Without a
 | server key it answers 200 {status: disabled}.
 */
Route::post('webhooks/midtrans/notification', MidtransNotificationController::class)
    ->middleware(['throttle:120,1', AcknowledgeDisabledMidtransWebhook::class])
    ->name('webhooks.midtrans.notification');
