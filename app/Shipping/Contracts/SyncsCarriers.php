<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use App\Shipping\CarrierSyncResult;

/**
 * A driver that can tell which couriers it offers and update the Shopper
 * carriers it owns (carriers.driver = this driver's code()).
 */
interface SyncsCarriers
{
    public function syncCarriers(bool $dryRun = false): CarrierSyncResult;
}
