<?php

declare(strict_types=1);

use App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController;
use App\Integrations\Xendit\Http\Middleware\AcknowledgeDisabledXenditWebhook;
use Illuminate\Support\Facades\Route;

/*
 | Xendit callbacks. Loaded by XenditServiceProvider inside the `web`
 | middleware group (CSRF is skipped for webhooks/* in bootstrap/app.php).
 | Register the URL in the Xendit Dashboard (Settings > Webhooks > Invoices
 | "Invoice paid" / "Invoice expired"); keep it stable. Without a usable key
 | and verification token it answers 200 {status: disabled}.
 */
Route::post('webhooks/xendit/invoice', XenditInvoiceWebhookController::class)
    ->middleware(['throttle:60,1', AcknowledgeDisabledXenditWebhook::class])
    ->name('webhooks.xendit.invoice');
