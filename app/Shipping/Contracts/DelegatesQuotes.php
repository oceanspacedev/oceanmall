<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

/**
 * A driver whose carriers are preferably quoted by another driver of the same
 * provider, with this driver as the fallback. E.g. RajaOngkir Cost carriers
 * are quoted by Komerce Delivery's tariff (the rows its Store Order needs)
 * when that product is configured, and by Shipping Cost otherwise.
 *
 * Generic quoting (FetchDeliveryRates) follows the delegation once: the
 * preferred driver is asked first, and this driver only when the preferred
 * one is not configured or returns no rate. The carriers stay owned by this
 * driver (carriers.driver = this driver's code()).
 */
interface DelegatesQuotes
{
    /**
     * Shopper shipping driver code asked first for this driver's carriers.
     */
    public function preferredQuoteDriver(): string;
}
