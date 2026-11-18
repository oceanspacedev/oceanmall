<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

/**
 * Marker: this driver quotes / fulfils with a destination pin point
 * ("lat,lng") and warehouse latitude / longitude.
 *
 * Contract for implementers: the driver must work WITHOUT a customer-supplied
 * pin. Clients send one (address metadata `pin_point`) only when they can:
 * web checkout offers the browser's current location when the Inertia prop
 * shippingCapabilities.requiresCoordinates is true, while mobile app builds
 * do not collect one (the API still accepts `pin_point` /
 * `latitude` + `longitude`). Resolve a missing pin server-side with
 * App\Shipping\DestinationCoordinateResolver (address pin, then lat/lng,
 * then a cached geocode of the destination, then a fallback you pass).
 */
interface RequiresCoordinates {}
