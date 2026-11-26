<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Dashboard;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Shopper\Core\Models\Order;

final class RecentOrders extends Component
{
    public function render(): View
    {
        $orders = Order::query()
            ->with('customer')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('livewire.shopper.dashboard.recent-orders', [
            'orders' => $orders,
        ]);
    }
}
