<?php

declare(strict_types=1);

/*
 * KiriminAja Mitra API (https://developer.kiriminaja.com/docs). This file is
 * the ONLY credential source of the module (merged under the `kiriminaja`
 * config key by KiriminAjaServiceProvider; publish it with
 * `php artisan vendor:publish --tag=kiriminaja-config` to override).
 *
 * Without KIRIMINAJA_API_KEY the `kiriminaja` shipping driver reports
 * isConfigured() = false and takes no part in checkout, fulfilment, tracking,
 * carrier sync or webhooks.
 */

/*
 * The sandbox host is only a convenience default outside production. In
 * production KIRIMINAJA_BASE_URL must be set explicitly
 * (https://client.kiriminaja.com): without it the driver stays unconfigured
 * instead of silently sending live shipments to the sandbox.
 */
$sandboxDefault = static fn (string $url): ?string => env('APP_ENV') === 'production' ? null : $url;

return [
    /*
     * Bearer API key from the KiriminAja dashboard (Integrasi). Sandbox keys
     * come from https://sandbox.kiriminaja.com, production keys from the
     * production dashboard: pair each key with its own base URL.
     */
    'api_key' => trim((string) env('KIRIMINAJA_API_KEY', '')),

    /*
     * Sandbox: https://tdev.kiriminaja.com
     * Production: https://client.kiriminaja.com (must be set explicitly).
     */
    'base_url' => env('KIRIMINAJA_BASE_URL', $sandboxDefault('https://tdev.kiriminaja.com')),

    /*
     * Shared secret for the callback URL. KiriminAja signs callbacks with
     * `Authorization: Bearer {api_key}`; when this is set,
     * `php artisan kiriminaja:set-callback` also appends ?token=<secret> to the
     * registered URL, and a callback carrying the right token is accepted too.
     * Every callback is only a signal: shipment state is re-read from the
     * Tracking API before anything changes.
     */
    'webhook_secret' => (string) env('KIRIMINAJA_WEBHOOK_SECRET', ''),

    'timeout' => max(1, (int) env('KIRIMINAJA_TIMEOUT', 30)),

    /*
     * Checkout quotes (POST /api/mitra/v6.1/shipping_price) are cached per
     * route + weight + item value + courier set. Destination searches are
     * cached 30 days (the endpoint is rate-limited).
     */
    'rate_cache_hours' => max(0, (int) env('KIRIMINAJA_RATE_CACHE_HOURS', 12)),

    /*
     * Insure every parcel (insurance=1 on quotes, insurance_amount on the
     * order). Services that force insurance are always insured. The insurance
     * fee is added to the shipping price the customer pays.
     */
    'insurance' => filter_var(env('KIRIMINAJA_INSURANCE', false), FILTER_VALIDATE_BOOLEAN),

    'pickup' => [
        /*
         * `auto` = earliest open slot from POST /api/mitra/v2/schedules;
         * `HH:MM` = a fixed daily pickup time (today when it is at least an
         * hour away and before 16:00 WIB, otherwise the next day).
         */
        'schedule' => (string) env('KIRIMINAJA_PICKUP_SCHEDULE', 'auto'),

        /*
         * Drop the parcels at a courier counter instead of a pickup.
         */
        'drop_off' => filter_var(env('KIRIMINAJA_DROP_OFF', false), FILTER_VALIDATE_BOOLEAN),

        /*
         * KiriminAja package type (7 = general goods in the official examples).
         */
        'package_type_id' => max(1, (int) env('KIRIMINAJA_PACKAGE_TYPE_ID', 7)),

        /*
         * Prefix of the partner order id sent to KiriminAja ("must have a
         * string prefix", max 20 characters in total). Letters / digits only.
         */
        'order_prefix' => (string) env('KIRIMINAJA_ORDER_PREFIX', 'OM'),

        /*
         * Create-order endpoint. The official PHP SDK uses v6.1; v6.2 adds
         * per-item metadata and requires coordinates on both ends.
         */
        'endpoint' => (string) env('KIRIMINAJA_REQUEST_PICKUP_PATH', 'api/mitra/v6.1/request_pickup'),
    ],

    /*
     * `shipping:sync-carriers` also sends the enabled courier codes to
     * POST /api/mitra/v3/set_whitelist_services (account-wide preference that
     * narrows every quote). Off by default.
     */
    'sync_whitelist' => filter_var(env('KIRIMINAJA_SYNC_WHITELIST', false), FILTER_VALIDATE_BOOLEAN),
];
