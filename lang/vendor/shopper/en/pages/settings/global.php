<?php

declare(strict_types=1);

// OceanMall additions merged on top of Shopper's English strings.
return [
    'location' => [
        'rajaongkir_origin' => 'RajaOngkir origin',
        'rajaongkir_origin_summary' => 'Destination ID used as the warehouse origin when quoting RajaOngkir Cost rates and creating Komerce Delivery orders. Leave empty to auto-fill from the location address.',
        'rajaongkir_origin_id' => 'RajaOngkir origin ID',
        'rajaongkir_origin_helper' => 'Subdistrict ID from the RajaOngkir destination search. Required so checkout shows rates from this warehouse.',
        'shipping_origin' => 'Shipping origin',
        'shipping_origin_summary' => 'Where parcels leave this warehouse. Coordinates are used by couriers that need a pickup point; provider origin IDs appear only while such a shipping provider is configured.',
        'shipping_origin_id' => ':provider origin ID',
        'shipping_origin_helper' => 'Area ID from the :provider destination search. Required so checkout shows :provider rates from this warehouse. Leave empty to auto-fill from the location address.',
        'latitude_helper' => 'Warehouse latitude, for couriers that need a pickup point. Example: -6.7366',
        'longitude_helper' => 'Warehouse longitude, for couriers that need a pickup point. Example: 108.5414',
    ],
];
