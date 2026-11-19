<?php

declare(strict_types=1);

use App\Integrations\Biteship\BiteshipServiceProvider;
use App\Integrations\KiriminAja\KiriminAjaServiceProvider;
use App\Integrations\Komerce\KomerceServiceProvider;
use App\Integrations\Midtrans\MidtransServiceProvider;
use App\Integrations\Xendit\XenditServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,

    // Provider modules (app/Integrations/<Name>). Each one is plug-and-play:
    // it only takes part once its API key is set (driver isConfigured()) and
    // its operator switch (config/shopper/{payment,shipping}.php) is on.
    // Remove a line (and its folder) to drop the module entirely; with none
    // left the store falls back to Shopper's manual payment, carriers with
    // zone shipping options and manual AWB entry
    // (tests/Feature/Modules/StoreWithoutKomerceModuleTest boots without them).
    // Order matters only as the default primary destination vocabulary when
    // several destination-based shipping drivers are configured at once
    // (override with SHIPPING_DESTINATION_DRIVER).
    KomerceServiceProvider::class,
    XenditServiceProvider::class,
    MidtransServiceProvider::class,
    BiteshipServiceProvider::class,
    KiriminAjaServiceProvider::class,
];
