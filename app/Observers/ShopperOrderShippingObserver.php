<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Shipping\MirrorManualShopperShipping;
use Shopper\Core\Models\OrderShipping;

/**
 * Shopper's own label / shipment-event flow in cpanel writes OrderShipping
 * rows; mirror manual parcels onto the warehouse shipment the customer sees.
 */
final class ShopperOrderShippingObserver
{
    public function saved(OrderShipping $shipping): void
    {
        resolve(MirrorManualShopperShipping::class)->handle($shipping);
    }
}
