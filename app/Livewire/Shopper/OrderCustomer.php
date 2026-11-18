<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use Closure;
use Illuminate\Contracts\View\View;
use Shopper\Core\Models\Order;
use Shopper\Livewire\Components\Orders\OrderCustomer as ShopperOrderCustomer;

/**
 * Shopper's order customer card. Shopper's shipping-address block stays,
 * except on orders where a provider panel already shows the destination
 * (registered by that provider's addon through hideShippingAddressWhen());
 * the billing address is then shown only when it differs.
 */
final class OrderCustomer extends ShopperOrderCustomer
{
    /** @var array<string, Closure(Order): bool> */
    private static array $shippingAddressShownElsewhere = [];

    /**
     * Keyed so re-registering on every panel resolve (Octane) stays one entry.
     *
     * @param  Closure(Order): bool  $callback
     */
    public static function hideShippingAddressWhen(string $key, Closure $callback): void
    {
        self::$shippingAddressShownElsewhere[$key] = $callback;
    }

    public static function flushShippingAddressCallbacks(): void
    {
        self::$shippingAddressShownElsewhere = [];
    }

    public function render(): View
    {
        if (! $this->shippingAddressShownElsewhere()) {
            return parent::render();
        }

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

    private function shippingAddressShownElsewhere(): bool
    {
        foreach (self::$shippingAddressShownElsewhere as $callback) {
            if ($callback($this->order)) {
                return true;
            }
        }

        return false;
    }
}
