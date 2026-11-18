<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

/**
 * Marker: this driver quotes or fulfils from a warehouse origin id in its
 * own vocabulary (inventory_shipping_origins, InventoryOrigins::get($inventory,
 * code())) but has no customer destination search, e.g. a provider whose
 * pickup points are registered in its dashboard.
 *
 * The cpanel location form shows an origin id field for every configured
 * driver that implements this, ResolvesDestinations or ValidatesOriginIds
 * (ShippingCapabilities::originDrivers()). Only ResolvesDestinations drivers
 * can resolve the origin automatically from the warehouse address.
 */
interface RequiresOriginId {}
