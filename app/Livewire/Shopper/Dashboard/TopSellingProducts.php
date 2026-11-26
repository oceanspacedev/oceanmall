<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Dashboard;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;

final class TopSellingProducts extends Component
{
    public function render(): View
    {
        abort_unless(Gate::allows('finance-dashboard'), 403);

        $ordersTable = shopper_table('orders');
        $itemsTable = shopper_table('order_items');

        $rows = DB::table($itemsTable)
            ->join($ordersTable, "{$ordersTable}.id", '=', "{$itemsTable}.order_id")
            ->whereNull("{$ordersTable}.deleted_at")
            ->where("{$ordersTable}.payment_status", PaymentStatus::Paid->value)
            ->where("{$ordersTable}.status", '!=', OrderStatus::Cancelled->value)
            ->selectRaw("{$itemsTable}.name as name, {$itemsTable}.sku as sku, SUM({$itemsTable}.quantity) as total_qty, SUM({$itemsTable}.unit_price_amount * {$itemsTable}.quantity) as total_sales")
            ->groupBy("{$itemsTable}.name", "{$itemsTable}.sku")
            ->orderByDesc('total_sales')
            ->limit(6)
            ->get();

        return view('livewire.shopper.dashboard.top-selling-products', [
            'items' => $rows,
        ]);
    }
}
