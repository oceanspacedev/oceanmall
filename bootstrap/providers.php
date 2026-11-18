<?php

declare(strict_types=1);

use App\Integrations\Komerce\KomerceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,

    // Provider modules. Remove a line (and its app/Integrations folder) to
    // run without that provider: the store falls back to Shopper's manual
    // payment, carriers with zone shipping options and manual AWB entry
    // (tests/Feature/Modules/StoreWithoutKomerceModuleTest boots without it).
    KomerceServiceProvider::class,
];
