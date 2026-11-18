<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use App\Models\OrderShipment;

interface ReconcilesShipments
{
    /**
     * Pull the provider's view of a registered shipment (order number, AWB,
     * status) and persist it locally.
     */
    public function reconcileShipment(OrderShipment $shipment): OrderShipment;
}
