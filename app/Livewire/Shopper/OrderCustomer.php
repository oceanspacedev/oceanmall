<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use Illuminate\Contracts\View\View;
use Shopper\Livewire\Components\Orders\OrderCustomer as ShopperOrderCustomer;

/**
 * Drops Shopper's shipping-address block (the Komerce shipping panel already
 * shows the destination) and shows billing only when it differs. Shopper's own
 * view is included as-is by the wrapper, so upstream changes still apply.
 */
final class OrderCustomer extends ShopperOrderCustomer
{
    public function render(): View
    {
        $this->order->loadMissing(['shippingAddress', 'billingAddress']);

        $shippingAddress = $this->order->shippingAddress;
        $billingAddress = $this->order->billingAddress;

        return view('shopper.livewire.order-customer', [
            'billingAddress' => $billingAddress,
            'separateBillingAddress' => $billingAddress && (! $shippingAddress || ! $billingAddress->is($shippingAddress))
                ? $billingAddress
                : null,
        ]);
    }
}
