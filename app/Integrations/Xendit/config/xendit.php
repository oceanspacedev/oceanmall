<?php

declare(strict_types=1);

/*
 | Xendit module (app/Integrations/Xendit). The ONLY source of Xendit
 | credentials and options; publish with
 | `php artisan vendor:publish --tag=xendit-config` to override key by key.
 |
 | Test vs live is decided by the secret key itself: Xendit issues
 | `xnd_development_...` (test mode) and `xnd_production_...` (live) keys for
 | the same host (https://api.xendit.co). With APP_ENV=production a
 | development key is refused (the driver reports isConfigured() = false and
 | no request is sent) unless XENDIT_ALLOW_DEVELOPMENT_KEY_IN_PRODUCTION=true,
 | so a production store never silently takes "payments" in test mode.
 */

$nullableUrl = static function (mixed $value): ?string {
    $value = is_string($value) ? trim($value) : '';

    return $value !== '' ? $value : null;
};

return [
    /* Secret API key (Dashboard > Settings > API Keys). Empty = module off. */
    'secret_key' => trim((string) env('XENDIT_SECRET_KEY', '')),

    /*
     | Webhook verification token (Dashboard > Settings > Webhooks > "View
     | Webhook Verification Token"). Xendit sends it in the `x-callback-token`
     | header of every callback; callbacks are rejected while it is empty.
     */
    'callback_token' => trim((string) env('XENDIT_CALLBACK_TOKEN', '')),

    /* One host for test and live mode. Overridable for tests / proxies only. */
    'base_url' => rtrim((string) env('XENDIT_BASE_URL', 'https://api.xendit.co'), '/'),

    /* Invoice lifetime in seconds (default 24 h; Xendit accepts 1 s to 1 year). */
    'invoice_duration' => min(31536000, max(60, (int) env('XENDIT_INVOICE_DURATION', 86400))),

    /* HTTP timeout in seconds. */
    'timeout' => max(1, (int) env('XENDIT_TIMEOUT', 15)),

    /*
     | Where the hosted invoice page sends the customer afterwards. Null = the
     | customer's order page (route account.orders.show).
     */
    'success_redirect_url' => $nullableUrl(env('XENDIT_SUCCESS_REDIRECT_URL')),
    'failure_redirect_url' => $nullableUrl(env('XENDIT_FAILURE_REDIRECT_URL')),

    /* Escape hatch for a production-like rehearsal with a test key. */
    'allow_development_key_in_production' => filter_var(
        env('XENDIT_ALLOW_DEVELOPMENT_KEY_IN_PRODUCTION', false),
        FILTER_VALIDATE_BOOLEAN,
    ),
];
