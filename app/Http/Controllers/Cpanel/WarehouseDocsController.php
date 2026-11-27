<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cpanel;

use App\Http\Controllers\Controller;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Shopper\Core\Models\Order;

/**
 * Warehouse printable pick-list, packing slip, and bulk thermal labels under /cpanel.
 */
final class WarehouseDocsController extends Controller
{
    public function pickList(Request $request): View
    {
        Gate::authorize('print-shipment-label');

        $ids = $this->parseOrderIds($request);
        $orders = Order::query()
            ->with(['items', 'shippingAddress', 'customer'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $aggregatedItems = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $key = ($item->sku ?: $item->name).'|'.$item->product_type.'|'.$item->product_id;
                if (! isset($aggregatedItems[$key])) {
                    $aggregatedItems[$key] = [
                        'name' => $item->name,
                        'sku' => $item->sku ?: '-',
                        'qty' => 0,
                        'orders' => [],
                    ];
                }
                $aggregatedItems[$key]['qty'] += (int) $item->quantity;
                $aggregatedItems[$key]['orders'][] = $order->number;
            }
        }

        return view('shopper.shipping.pick-list', [
            'orders' => $orders,
            'items' => array_values($aggregatedItems),
            'generatedAt' => now(),
        ]);
    }

    public function packingSlip(Order $order, OrderShipmentOpsPresenter $presenter): View
    {
        Gate::authorize('print-shipment-label', $order);

        $order->loadMissing(['items', 'shippingAddress', 'customer']);

        return view('shopper.shipping.packing-slip', [
            'order' => $order,
            'shipments' => $presenter->shipments($order),
        ]);
    }

    public function bulkLabels(Request $request, OrderShipmentOpsPresenter $presenter): View|Response
    {
        Gate::authorize('print-shipment-label');

        $ids = $this->parseOrderIds($request);
        $orders = Order::query()
            ->with(['items', 'shippingAddress', 'customer'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $labels = [];
        $hasCancelledShipments = false;
        $hasActiveShipments = false;

        foreach ($orders as $order) {
            foreach ($presenter->shipments($order) as $shipment) {
                if (strtolower((string) ($shipment['status'] ?? '')) === 'cancelled') {
                    $hasCancelledShipments = true;

                    continue;
                }

                if ((bool) ($shipment['is_superseded'] ?? false)) {
                    continue;
                }

                $hasActiveShipments = true;

                if (($shipment['can_print_label'] ?? false) !== true) {
                    continue;
                }

                $awb = trim((string) ($shipment['awb'] ?: $shipment['tracking_number'] ?: ''));
                if ($awb === '') {
                    continue;
                }

                $labels[] = [
                    'order' => $order,
                    'shipment' => $shipment,
                ];
            }
        }

        if ($labels === []) {
            $primaryOrder = $orders->count() === 1 ? $orders->first() : null;
            $message = (! $hasActiveShipments && $hasCancelledShipments)
                ? 'Stiker resi tidak tersedia untuk pengiriman yang sudah dibatalkan.'
                : 'Belum ada paket dengan nomor resi (AWB) valid yang siap dicetak.';

            return response()->view('shopper.shipping.label-error', [
                'order' => $primaryOrder,
                'message' => $message,
                'orderUrl' => $primaryOrder !== null
                    ? route('shopper.orders.detail', $primaryOrder)
                    : route('shopper.orders.index'),
            ], 422);
        }

        return view('shopper.shipping.bulk-labels', [
            'labels' => $labels,
        ]);
    }

    /**
     * @return list<int>
     */
    private function parseOrderIds(Request $request): array
    {
        $raw = $request->input('orders', '');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(array_map('intval', $parts), static fn (int $id): bool => $id > 0)));
    }
}
