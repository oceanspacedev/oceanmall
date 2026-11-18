<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

/**
 * A quoting-only driver whose shipments are fulfilled by another driver of the
 * same provider (e.g. RajaOngkir Shipping Cost quotes are registered with
 * Komerce Delivery). Generic code follows the delegation once.
 */
interface DelegatesFulfillment
{
    /**
     * Shopper shipping driver code that fulfils this driver's shipments.
     */
    public function fulfillmentDriver(): string;
}
