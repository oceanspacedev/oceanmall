<?php

declare(strict_types=1);

/*
 * The single source of Biteship credentials and options (config key
 * `biteship`). Publish with `php artisan vendor:publish --tag=biteship-config`
 * to override key by key in config/biteship.php.
 *
 * Biteship has ONE host for both environments: the API key decides whether a
 * call is a test or a live one (`biteship_test.` / `biteship_live.` prefixes).
 * App\Integrations\Biteship\Biteship::configured() therefore guards the key,
 * not the host: a test key is refused in production (no live checkout ever
 * quotes from, or registers parcels in, Biteship's testing mode) unless
 * BITESHIP_ALLOW_TEST_KEY=true, and a live key is refused in the `local` /
 * `testing` environments (no real pickup from a laptop) unless
 * BITESHIP_ALLOW_LIVE_KEY=true.
 */
return [
    'api_key' => trim((string) env('BITESHIP_API_KEY', '')),

    'base_url' => rtrim(trim((string) env('BITESHIP_BASE_URL', 'https://api.biteship.com')), '/'),

    'allow_test_key_in_production' => filter_var(env('BITESHIP_ALLOW_TEST_KEY', false), FILTER_VALIDATE_BOOLEAN),

    'allow_live_key_outside_production' => filter_var(env('BITESHIP_ALLOW_LIVE_KEY', false), FILTER_VALIDATE_BOOLEAN),

    'timeout' => max(1, (int) env('BITESHIP_TIMEOUT', 30)),

    /*
     * Courier rates are cached per route + weight + courier list. Biteship
     * prices change rarely and every live Rates call counts against the plan.
     */
    'rate_cache_hours' => max(0, (int) env('BITESHIP_RATE_CACHE_HOURS', 12)),

    /*
     * Shared secret Biteship sends with every webhook. In the Biteship
     * dashboard (Integrations → Webhook) set the signature header KEY to
     * `webhook_header` and its VALUE to this secret. Without a secret,
     * webhook events are refused (401); tracking still updates through the
     * hourly `shipping:refresh-tracking`.
     */
    'webhook_secret' => (string) env('BITESHIP_WEBHOOK_SECRET', ''),

    'webhook_header' => trim((string) env('BITESHIP_WEBHOOK_HEADER', 'X-Biteship-Secret')) ?: 'X-Biteship-Secret',

    /*
     * Order API delivery_type: `now` (pickup as soon as possible) or
     * `scheduled` (pickup on the next pickup_time slot, WIB).
     */
    'delivery_type' => env('BITESHIP_DELIVERY_TYPE', 'now'),

    'pickup_time' => env('BITESHIP_PICKUP_TIME', '10:00'),

    /* Default Biteship item category (fashion, food_and_drink, electronic, ..., others). */
    'item_category' => env('BITESHIP_ITEM_CATEGORY', 'others'),
];
