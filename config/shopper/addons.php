<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Shopper Addons
    |--------------------------------------------------------------------------
    |
    | Control which addons are enabled. Set an addon's ID to false to disable
    | it. When disabled, the addon's routes, sidebar items, and components
    | will not be registered.
    |
    | - oceanmall-store: generic store customisations of the admin.
    | - komerce: Komerce / RajaOngkir admin UI; also stays off by itself
    |   while no Komerce key is configured.
    |
    */

    'oceanmall-store' => true,

    'komerce' => true,

];
