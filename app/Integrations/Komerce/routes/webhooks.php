<?php

declare(strict_types=1);

use App\Integrations\Komerce\Http\Controllers\KomerceDeliveryWebhookController;
use App\Integrations\Komerce\Http\Controllers\KomercePaymentWebhookController;
use App\Integrations\Komerce\Http\Controllers\KomerceQrislyWebhookController;
use App\Integrations\Komerce\Http\Middleware\AcknowledgeDisabledWebhook;
use Illuminate\Support\Facades\Route;

/*
 | Komerce callbacks. Loaded by KomerceServiceProvider inside the `web`
 | middleware group (CSRF is skipped for webhooks/* in bootstrap/app.php).
 | URLs and route names are part of the Collaborator dashboard setup: keep
 | them stable. A product that is switched off answers 200 {status: disabled}.
 */
Route::post('webhooks/komerce/payment', KomercePaymentWebhookController::class)
    ->middleware(['throttle:60,1', AcknowledgeDisabledWebhook::class.':payment'])
    ->name('webhooks.komerce.payment');

Route::match(['POST', 'PUT'], 'webhooks/komerce/delivery', KomerceDeliveryWebhookController::class)
    ->middleware(['throttle:60,1', AcknowledgeDisabledWebhook::class.':shipping_delivery'])
    ->name('webhooks.komerce.delivery');

Route::post('webhooks/komerce/qrisly', KomerceQrislyWebhookController::class)
    ->middleware(['throttle:60,1', AcknowledgeDisabledWebhook::class.':qrisly'])
    ->name('webhooks.komerce.qrisly');
