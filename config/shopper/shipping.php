<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The shipping provider the store sells through (store setting)
    |--------------------------------------------------------------------------
    |
    | Only ONE shipping provider quotes couriers, owns the address
    | vocabulary and syncs carriers at a time, however many modules hold a
    | key (App\Support\ActiveProvider). Name one of its driver codes
    | (komerce or rajaongkir: the same provider; biteship; kiriminaja);
    | 'none' sells Shopper's zone options only. Left empty, the first
    | configured provider in bootstrap/providers.php order is used. The
    | other keyed providers quote nothing but still finish the parcels they
    | already hold (tracking, webhooks, cancel, labels).
    |
    */

    'provider' => env('SHIPPING_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Shipping Drivers
    |--------------------------------------------------------------------------
    |
    | Configure shipping provider drivers. Each driver connects to a shipping
    | carrier API (UPS, FedEx, DHL, etc.) for real-time rate calculation,
    | shipment creation, and tracking.
    |
    | Credentials should be stored in your .env file, never in the database.
    |
    */

    'drivers' => [

        'ups' => [
            'enabled' => env('SHIPPING_UPS_ENABLED', false),
            'sandbox' => env('SHIPPING_SANDBOX', false),
            'credentials' => [
                'client_id' => env('UPS_CLIENT_ID'),
                'client_secret' => env('UPS_CLIENT_SECRET'),
                'user_id' => env('UPS_USER_ID'),
                'account_number' => env('UPS_ACCOUNT_NUMBER'),
            ],
        ],

        'fedex' => [
            'enabled' => env('SHIPPING_FEDEX_ENABLED', false),
            'sandbox' => env('SHIPPING_SANDBOX', false),
            'credentials' => [
                'client_id' => env('FEDEX_CLIENT_ID'),
                'client_secret' => env('FEDEX_CLIENT_SECRET'),
                'account_number' => env('FEDEX_ACCOUNT_NUMBER'),
            ],
        ],

        'usps' => [
            'enabled' => env('SHIPPING_USPS_ENABLED', false),
            'sandbox' => env('SHIPPING_SANDBOX', false),
            'credentials' => [
                'client_id' => env('USPS_CLIENT_ID'),
                'client_secret' => env('USPS_CLIENT_SECRET'),
            ],
        ],

        'canada_post' => [
            'enabled' => env('SHIPPING_CANADA_POST_ENABLED', false),
            'sandbox' => env('SHIPPING_SANDBOX', false),
            'credentials' => [
                'customer_number' => env('CANADA_POST_CUSTOMER_NUMBER'),
                'username' => env('CANADA_POST_USERNAME'),
                'password' => env('CANADA_POST_PASSWORD'),
            ],
        ],

        'purolator' => [
            'enabled' => env('SHIPPING_PUROLATOR_ENABLED', false),
            'sandbox' => env('SHIPPING_SANDBOX', false),
            'credentials' => [
                'key' => env('PUROLATOR_KEY'),
                'password' => env('PUROLATOR_PASSWORD'),
                'billing_account' => env('PUROLATOR_BILLING_ACCOUNT'),
                'registered_account' => env('PUROLATOR_REGISTERED_ACCOUNT'),
                'user_token' => env('PUROLATOR_USER_TOKEN'),
            ],
        ],

        /*
         | RajaOngkir Shipping Cost (real-time domestic rates) and Komerce
         | Shipping Delivery (store order, pickup, AWB, label, tracking), both
         | registered by the Komerce module (app/Integrations/Komerce).
         | Credentials and base URLs live only in the module's config (config
         | key `komerce`, app/Integrations/Komerce/config/komerce.php).
         | 'enabled' is the operator switch (set false to take a configured
         | driver out of checkout, fulfilment and tracking); readiness itself
         | is each driver's isConfigured() (key + plan), asked per request by
         | App\Shipping\ShippingCapabilities. Every provider driver follows
         | Shopper's own rule (see App\Support\OperatorSwitch): a driver
         | without an 'enabled' entry is OFF, and the value is parsed as a
         | boolean, so SHIPPING_X_ENABLED=0 / off / false all switch it off.
         */
        'rajaongkir' => [
            'enabled' => filter_var(env('SHIPPING_RAJAONGKIR_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],

        'komerce' => [
            'enabled' => filter_var(env('SHIPPING_KOMERCE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],

        /*
         | Biteship (rates, area search, orders / pickup, tracking) and
         | KiriminAja (rates, kecamatan search, request pickup, tracking),
         | registered by their modules (app/Integrations/Biteship,
         | app/Integrations/KiriminAja). Credentials live in each module's
         | config (keys `biteship`, `kiriminaja`). A driver without an API key
         | reports isConfigured() = false and takes no part, so leaving these
         | switches on is harmless: filling the key plugs the provider in.
         */
        'biteship' => [
            'enabled' => filter_var(env('SHIPPING_BITESHIP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],

        'kiriminaja' => [
            'enabled' => filter_var(env('SHIPPING_KIRIMINAJA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Primary destination driver (store setting)
    |--------------------------------------------------------------------------
    |
    | Only the selected provider's drivers are considered (see 'provider'
    | above), so this matters only when that one provider has several
    | destination-based drivers: address forms search the FIRST one, or the
    | driver code named here. Ignored while that driver is not configured.
    |
    */

    'destination_driver' => env('SHIPPING_DESTINATION_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Default Measurement Units
    |--------------------------------------------------------------------------
    |
    | Define the default units for weight and dimensions used in shipping
    | calculations. Supported: 'metric' (kg, cm) or 'imperial' (lb, in)
    |
    */

    'units' => env('SHIPPING_UNITS', 'metric'),

    /*
    |--------------------------------------------------------------------------
    | Zone options when a provider returns nothing (store setting)
    |--------------------------------------------------------------------------
    |
    | With a real-time shipping provider configured, checkout shows only its
    | quotes. When it returns nothing (out of daily quota, failing, no
    | warehouse origin, destination outside its coverage) checkout shows no
    | courier plus an explanation, as before provider drivers existed:
    | Shopper's flat zone options (e.g. the seeded "Reguler") may be priced
    | for a different route and would sell shipping at the wrong price.
    |
    | Set true to offer Shopper's zone options in that case instead. Without
    | any configured provider, zone options are always used.
    |
    */

    'fallback_to_zone_options' => filter_var(env('SHIPPING_FALLBACK_TO_ZONE_OPTIONS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Zone options next to provider quotes (store setting)
    |--------------------------------------------------------------------------
    |
    | Default false: with a provider configured, checkout shows only its
    | courier quotes. Set true to also offer Shopper's zone options of the
    | carriers no configured provider owns (e.g. a manual "Ambil di toko" or
    | "Kurir Toko" option) next to them, also when the provider answers
    | nothing. Carriers a provider owns are always
    | quoted by that provider, never priced from zone options.
    |
    */

    'combine_zone_options' => filter_var(env('SHIPPING_COMBINE_ZONE_OPTIONS', false), FILTER_VALIDATE_BOOLEAN),

];
