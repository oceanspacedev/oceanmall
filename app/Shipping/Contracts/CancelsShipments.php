<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use App\Models\OrderShipment;

interface CancelsShipments
{
    /**
     * Cancel the provider order behind a shipment. Must be idempotent and a
     * no-op for shipments the provider never registered.
     */
    public function cancelShipment(OrderShipment $shipment): void;
}
