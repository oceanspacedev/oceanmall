<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Drivers
    |--------------------------------------------------------------------------
    |
    | Configure payment provider drivers. Each driver connects to a payment
    | gateway API (Stripe, PayPal, etc.) for processing payments,
    | captures, and refunds.
    |
    | Credentials should be stored in your .env file, never in the database.
    |
    */

    'drivers' => [

        'stripe' => [
            'enabled' => env('PAYMENT_STRIPE_ENABLED', false),
            'sandbox' => env('PAYMENT_SANDBOX', false),
            'credentials' => [
                'secret_key' => env('STRIPE_SECRET_KEY'),
                'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            ],
        ],

        'paypal' => [
            'enabled' => env('PAYMENT_PAYPAL_ENABLED', false),
            'sandbox' => env('PAYMENT_SANDBOX', false),
            'credentials' => [
                'client_id' => env('PAYPAL_CLIENT_ID'),
                'client_secret' => env('PAYPAL_CLIENT_SECRET'),
                'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            ],
        ],

        /*
         | Komerce Payment API (VA / QRIS) + optional QRISLY, registered by the
         | Komerce module (app/Integrations/Komerce). Only the operator switch
         | lives here: credentials, base URLs and the sandbox/production host
         | choice are in the module's config (config key `komerce`, see
         | app/Integrations/Komerce/config/komerce.php). Whether the methods
         | can take payments is KomerceDriver::isConfigured() (product key and
         | plan), see PaymentMethodCatalog (app/Payment).
         */
        'komerce' => [
            'enabled' => filter_var(env('PAYMENT_KOMERCE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],

        /*
         | Xendit (Invoice) and Midtrans (Snap), registered by their modules
         | (app/Integrations/Xendit, app/Integrations/Midtrans). Credentials
         | live in each module's config (keys `xendit`, `midtrans`). Without a
         | key the driver reports isConfigured() = false and its methods are
         | not offered, so filling the key plugs the gateway in.
         */
        'xendit' => [
            'enabled' => filter_var(env('PAYMENT_XENDIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],

        'midtrans' => [
            'enabled' => filter_var(env('PAYMENT_MIDTRANS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],

    ],

];
