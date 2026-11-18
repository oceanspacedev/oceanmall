<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use Shopper\Core\Models\Carrier;

/**
 * A driver that decides how the storefront advertises the carriers it owns
 * (footer courier list): its own courier names, and which carriers it cannot
 * actually quote (hidden). Without it a carrier is shown with its slug / name.
 */
interface PresentsCarriers
{
    /**
     * @return array{code: string, label: string}|null Null hides the carrier
     */
    public function presentCarrier(Carrier $carrier): ?array;
}
