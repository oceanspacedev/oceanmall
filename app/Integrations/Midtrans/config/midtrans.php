<?php

declare(strict_types=1);

/*
 * Midtrans (Snap hosted checkout + Core API status/cancel/expire).
 *
 * This file is the ONLY source of Midtrans credentials and options (config
 * key `midtrans`). Publish it to override key by key:
 * php artisan vendor:publish --tag=midtrans-config
 *
 * Host choice is explicit: MIDTRANS_IS_PRODUCTION=true selects
 * app.midtrans.com / api.midtrans.com, false selects the sandbox hosts. With
 * APP_ENV=production the variable must be set (true or false): while it is
 * unset there the driver reports isConfigured() = false instead of guessing,
 * so live checkouts are never silently sent to the sandbox (or the other way
 * round). A sandbox (SB-) server key with MIDTRANS_IS_PRODUCTION=true is
 * refused the same way, see
 * App\Integrations\Midtrans\Midtrans::configurationError().
 */
$isProduction = env('MIDTRANS_IS_PRODUCTION');

return [
    /*
     * Server key (Dashboard > Settings > Access Keys). Basic-auth username
     * for every API call and the secret of the notification signature.
     * Empty = the module takes no part in checkout, cpanel or webhooks.
     */
    'server_key' => trim((string) env('MIDTRANS_SERVER_KEY', '')),

    /*
     * Client key: optional, only needed for a future in-page Snap.js popup.
     * The hosted redirect flow does not use it.
     */
    'client_key' => trim((string) env('MIDTRANS_CLIENT_KEY', '')),

    /*
     * true = production hosts, false = sandbox hosts, null = not set (only
     * acceptable outside APP_ENV=production, where it means sandbox).
     */
    'is_production' => $isProduction === null || trim((string) $isProduction) === ''
        ? null
        : filter_var($isProduction, FILTER_VALIDATE_BOOLEAN),

    'hosts' => [
        'production' => [
            'snap' => 'https://app.midtrans.com',
            'api' => 'https://api.midtrans.com',
        ],
        'sandbox' => [
            'snap' => 'https://app.sandbox.midtrans.com',
            'api' => 'https://api.sandbox.midtrans.com',
        ],
    ],

    /*
     * How long a Snap payment stays payable, in minutes (Snap `expiry`).
     * Unpaid orders are cancelled by the generic orders:expire-unpaid
     * schedule once this has passed. Bounded to 1 minute .. 7 days.
     */
    'expiry_minutes' => min(10_080, max(1, (int) env('MIDTRANS_EXPIRY_MINUTES', 1440))),

    /*
     * Where Snap sends the customer after paying. Empty = the customer's
     * order page (account.orders.show). Snap appends order_id / status_code /
     * transaction_status query parameters; the page never trusts them, the
     * notification webhook and the status API settle the order.
     */
    'finish_url' => trim((string) env('MIDTRANS_FINISH_URL', '')),

    'timeout' => max(1, (int) env('MIDTRANS_TIMEOUT', 30)),
];
