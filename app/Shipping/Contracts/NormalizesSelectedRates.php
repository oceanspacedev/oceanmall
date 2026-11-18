<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

/**
 * A driver that rewrites the customer's selected rate into the shape its own
 * fulfilment needs (provider courier / service names, fee breakdown) before
 * CreateOrder stores it on the warehouse shipment.
 */
interface NormalizesSelectedRates
{
    /**
     * @param  array<string, mixed>  $rate  The rate as selected at checkout
     * @param  int  $cost  The shipment cost CreateOrder charges
     * @return array<string, mixed>
     */
    public function normalizeSelectedRate(array $rate, int $cost): array;
}
