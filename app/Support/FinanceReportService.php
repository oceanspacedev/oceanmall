<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OrderShipment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Enum\OrderRefundStatus;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;

/**
 * Shared portable finance, COGS, PPN 11%, promo ROI, and settlement calculator
 * backing both `/cpanel/reports/finance` and CSV/XLSX exports.
 */
final class FinanceReportService
{
    /**
     * @return array{
     *   summary: array{orders: int, gross: int, discount: int, shipping: int, cogs: int, refunds: int, gateway_fee: int, net_revenue: int, gross_profit: int, margin_pct: float},
     *   rows: list<array<string, mixed>>
     * }
     */
    public function profitAndMargin(CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = $this->paidOrdersInRange($from, $to);
        $refundsByOrder = $this->refundsByOrder($orders->pluck('id')->all());
        $shippingByOrder = $this->shippingByOrder($orders->pluck('id')->all());

        $summary = [
            'orders' => 0,
            'gross' => 0,
            'discount' => 0,
            'shipping' => 0,
            'cogs' => 0,
            'refunds' => 0,
            'gateway_fee' => 0,
            'net_revenue' => 0,
            'gross_profit' => 0,
            'margin_pct' => 0.0,
        ];

        $rows = [];

        foreach ($orders as $order) {
            $gross = (int) $order->price_amount;
            $discount = (int) $order->items->sum(static fn ($item): int => (int) ($item->discount_amount ?? 0));
            $itemsTotal = (int) $order->items->sum(static fn ($item): int => (int) $item->unit_price_amount * (int) $item->quantity);
            $shipping = (int) ($order->shippingOption?->price ?? ($shippingByOrder[$order->id] ?? max(0, $gross - $itemsTotal + $discount)));
            $cogs = (int) $order->items->sum(static fn ($item): int => (int) ($item->unit_cost_amount ?? 0) * (int) $item->quantity);
            $refunds = (int) ($refundsByOrder[$order->id] ?? 0);
            $gatewayFee = (int) ($order->gateway_fee ?? 0);
            $netRevenue = max(0, $gross - $refunds);
            $grossProfit = $netRevenue - $cogs - $gatewayFee;
            $marginPct = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 1) : 0.0;

            $summary['orders']++;
            $summary['gross'] += $gross;
            $summary['discount'] += $discount;
            $summary['shipping'] += $shipping;
            $summary['cogs'] += $cogs;
            $summary['refunds'] += $refunds;
            $summary['gateway_fee'] += $gatewayFee;
            $summary['net_revenue'] += $netRevenue;
            $summary['gross_profit'] += $grossProfit;

            $rows[] = [
                'order_id' => (int) $order->id,
                'invoice_number' => (string) ($order->invoice_number ?: '-'),
                'order_number' => (string) $order->number,
                'paid_at' => ($order->paid_at ?? $order->created_at) !== null
                    ? Carbon::parse($order->paid_at ?? $order->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i')
                    : '',
                'customer' => (string) ($order->customer?->full_name ?: $order->shippingAddress?->full_name ?: 'Tamu'),
                'gross' => $gross,
                'discount' => $discount,
                'shipping' => $shipping,
                'cogs' => $cogs,
                'refunds' => $refunds,
                'gateway_fee' => $gatewayFee,
                'net_revenue' => $netRevenue,
                'gross_profit' => $grossProfit,
                'margin_pct' => $marginPct,
            ];
        }

        $summary['margin_pct'] = $summary['net_revenue'] > 0
            ? round(($summary['gross_profit'] / $summary['net_revenue']) * 100, 1)
            : 0.0;

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @return array{summary: array{orders: int, taxable_total: int, dpp: int, ppn: int}, rows: list<array<string, mixed>>}
     */
    public function ppnReport(CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = $this->paidOrdersInRange($from, $to);
        $refundsByOrder = $this->refundsByOrder($orders->pluck('id')->all());

        $summary = ['orders' => 0, 'taxable_total' => 0, 'dpp' => 0, 'ppn' => 0];
        $rows = [];

        foreach ($orders as $order) {
            $refunds = (int) ($refundsByOrder[$order->id] ?? 0);
            $taxable = max(0, (int) $order->price_amount - $refunds);
            $dpp = (int) round($taxable / 1.11);
            $ppn = max(0, $taxable - $dpp);

            $summary['orders']++;
            $summary['taxable_total'] += $taxable;
            $summary['dpp'] += $dpp;
            $summary['ppn'] += $ppn;

            $rows[] = [
                'invoice_number' => (string) ($order->invoice_number ?: '-'),
                'order_number' => (string) $order->number,
                'paid_at' => ($order->paid_at ?? $order->created_at) !== null
                    ? Carbon::parse($order->paid_at ?? $order->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i')
                    : '',
                'customer' => (string) ($order->customer?->full_name ?: $order->shippingAddress?->full_name ?: 'Tamu'),
                'dpp' => $dpp,
                'ppn' => $ppn,
                'total' => $taxable,
            ];
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @return array{summary: array{promos_used: int, orders: int, total_discount: int, attributed_gmv: int, net_margin: int}, rows: list<array<string, mixed>>}
     */
    public function promoRoi(CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = $this->paidOrdersInRange($from, $to)->filter(static fn (Order $o): bool => $o->discount_id !== null);
        $refundsByOrder = $this->refundsByOrder($orders->pluck('id')->all());
        $discounts = Discount::query()
            ->whereIn('id', $orders->pluck('discount_id')->filter()->unique()->all())
            ->pluck('code', 'id');

        $grouped = [];
        $summary = ['promos_used' => 0, 'orders' => 0, 'total_discount' => 0, 'attributed_gmv' => 0, 'net_margin' => 0];

        foreach ($orders as $order) {
            $discountId = (int) $order->discount_id;
            $code = (string) ($discounts[$discountId] ?? ('PROMO-#'.$discountId));
            $refunds = (int) ($refundsByOrder[$order->id] ?? 0);
            $netRevenue = max(0, (int) $order->price_amount - $refunds);
            $discount = (int) $order->items->sum(static fn ($item): int => (int) ($item->discount_amount ?? 0));
            $cogs = (int) $order->items->sum(static fn ($item): int => (int) ($item->unit_cost_amount ?? 0) * (int) $item->quantity);
            $netMargin = $netRevenue - $cogs - (int) ($order->gateway_fee ?? 0);

            if (! isset($grouped[$code])) {
                $grouped[$code] = [
                    'code' => $code,
                    'orders' => 0,
                    'total_discount' => 0,
                    'attributed_gmv' => 0,
                    'net_margin' => 0,
                ];
            }

            $grouped[$code]['orders']++;
            $grouped[$code]['total_discount'] += $discount;
            $grouped[$code]['attributed_gmv'] += $netRevenue;
            $grouped[$code]['net_margin'] += $netMargin;

            $summary['orders']++;
            $summary['total_discount'] += $discount;
            $summary['attributed_gmv'] += $netRevenue;
            $summary['net_margin'] += $netMargin;
        }

        $rows = array_values($grouped);
        $summary['promos_used'] = count($rows);

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @return array{
     *   summary: array{orders: int, gross: int, gateway_fee: int, net_settlement: int, cod_unremitted: int},
     *   channels: list<array<string, mixed>>,
     *   cod_orders: list<array<string, mixed>>
     * }
     */
    public function settlementAndCod(CarbonInterface $from, CarbonInterface $to): array
    {
        $paidOrders = $this->paidOrdersInRange($from, $to);
        $channels = [];
        $summary = ['orders' => 0, 'gross' => 0, 'gateway_fee' => 0, 'net_settlement' => 0, 'cod_unremitted' => 0];

        foreach ($paidOrders as $order) {
            $driver = $order->paymentMethod?->driver ?: 'manual';
            $title = $order->paymentMethod?->title ?: 'Manual';
            $key = $driver.'|'.$title;
            $gross = (int) $order->price_amount;
            $fee = (int) ($order->gateway_fee ?? 0);
            $net = $order->net_settlement !== null ? (int) $order->net_settlement : max(0, $gross - $fee);

            if (! isset($channels[$key])) {
                $channels[$key] = [
                    'driver' => $driver,
                    'method' => $title,
                    'orders' => 0,
                    'gross' => 0,
                    'gateway_fee' => 0,
                    'net_settlement' => 0,
                ];
            }

            $channels[$key]['orders']++;
            $channels[$key]['gross'] += $gross;
            $channels[$key]['gateway_fee'] += $fee;
            $channels[$key]['net_settlement'] += $net;

            $summary['orders']++;
            $summary['gross'] += $gross;
            $summary['gateway_fee'] += $fee;
            $summary['net_settlement'] += $net;
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $allPeriodOrders = Order::query()
            ->with(['paymentMethod', 'customer'])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('id')
            ->get();

        $codOrders = [];
        foreach ($allPeriodOrders as $order) {
            if (! OrderPaymentType::isCod($order)) {
                continue;
            }

            $canRemit = $order->payment_status !== PaymentStatus::Paid;
            if ($canRemit) {
                $summary['cod_unremitted'] += (int) $order->price_amount;
            }

            $codOrders[] = [
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->number,
                'customer' => (string) ($order->customer?->full_name ?: 'Tamu'),
                'price_amount' => (int) $order->price_amount,
                'payment_status' => (string) $order->payment_status->value,
                'cod_remitted_at' => $order->cod_remitted_at !== null
                    ? Carbon::parse($order->cod_remitted_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i')
                    : null,
                'can_remit' => $canRemit,
            ];
        }

        return [
            'summary' => $summary,
            'channels' => array_values($channels),
            'cod_orders' => $codOrders,
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    private function paidOrdersInRange(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        return Order::query()
            ->with(['items', 'customer', 'paymentMethod', 'shippingOption', 'shippingAddress'])
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where(static function ($query) use ($start, $end): void {
                $query->whereBetween('paid_at', [$start, $end])
                    ->orWhere(static function ($fallback) use ($start, $end): void {
                        $fallback->whereNull('paid_at')->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    private function refundsByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return OrderRefund::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', '!=', OrderRefundStatus::Rejected->value)
            ->where('status', '!=', OrderRefundStatus::Cancelled->value)
            ->selectRaw('order_id, SUM(amount) as total_refund')
            ->groupBy('order_id')
            ->pluck('total_refund', 'order_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    private function shippingByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table((new OrderShipment)->getTable())
            ->whereIn('order_id', $orderIds)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('order_id, SUM(cost) as total_cost')
            ->groupBy('order_id')
            ->pluck('total_cost', 'order_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }
}
