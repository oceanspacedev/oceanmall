<?php

declare(strict_types=1);

namespace App\Addons\Store;

use Shopper\Core\Models\Order;

/**
 * The order of the cpanel order page being rendered (route parameter, path
 * or, for Livewire updates, the referer), for addon render hooks.
 */
final class OrderFromRequest
{
    public static function resolve(): ?Order
    {
        $routeOrder = request()->route('order') ?? request()->route('id');

        if (! $routeOrder && preg_match('#/orders/([^/]+)/detail#', request()->path(), $matches)) {
            $routeOrder = urldecode($matches[1]);
        }

        if (! $routeOrder && request()->header('referer')) {
            if (preg_match('#/orders/([^/]+)/detail#', (string) request()->header('referer'), $matches)) {
                $routeOrder = urldecode($matches[1]);
            }
        }

        return match (true) {
            $routeOrder instanceof Order => $routeOrder,
            is_numeric($routeOrder) => Order::query()->find($routeOrder),
            is_string($routeOrder) && $routeOrder !== '' => Order::query()->where('number', $routeOrder)->orWhere('id', $routeOrder)->first(),
            default => null,
        };
    }
}
