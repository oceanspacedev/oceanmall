<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\OrderShipment;
use App\Payment\PaymentActions;
use App\Shipping\ShipmentTimeline;
use App\Shipping\ShippingCapabilities;
use App\Support\CarrierLogos;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;

final class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->string('tab', 'all');

        $query = Order::query()
            ->where('customer_id', auth()->id())
            ->with(['items.product.media', 'shippingAddress']);

        $query = match ($tab) {
            'not-shipped' => $query->where('shipping_status', ShippingStatus::Unfulfilled),
            'cancelled' => $query->where('status', OrderStatus::Cancelled),
            default => $query,
        };

        return Inertia::render('account/orders', [
            'orders' => $query->latest()->paginate(10)->withQueryString(),
            'filters' => ['tab' => $tab],
        ]);
    }

    public function show(Order $order): Response
    {
        abort_unless($order->customer_id === auth()->id(), 403);

        $order->load([
            'items.product.media',
            'shippingAddress',
            'billingAddress',
            'shippingOption.carrier',
        ]);

        $order->shippingAddress?->append('full_name');
        $order->billingAddress?->append('full_name');

        $orderShipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->with('inventory')
            ->orderBy('id')
            ->get();
        // Provider history plus the events staff record in Shopper's own
        // shipment log (manual AWB parcels).
        $timelines = ShipmentTimeline::forShipments((int) $order->id, $orderShipments);
        $shippingCapabilities = resolve(ShippingCapabilities::class);

        $shipments = $orderShipments
            ->map(static function (OrderShipment $shipment) use ($timelines, $shippingCapabilities): array {
                $history = $timelines[(int) $shipment->id] ?? [];

                return [
                    'id' => $shipment->id,
                    'inventory_name' => $shipment->inventory?->name,
                    'status' => $shipment->status,
                    'awb' => $shipment->awb,
                    'tracking_number' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier_name ?? $shipment->carrier_code,
                    'service' => $shipment->service_name ?? $shipment->service_code,
                    'carrier_logo' => resolve(CarrierLogos::class)->forCode($shipment->carrier_code),
                    'cost' => $shipment->cost,
                    'currency' => $shipment->currency_code,
                    'can_track' => $shippingCapabilities->trackerFor($shipment) !== null,
                    'tracking_history' => $history,
                ];
            });

        $payments = resolve(PaymentActions::class);

        return Inertia::render('account/order-show', [
            'order' => $order,
            'shipments' => $shipments,
            // Neutral action from the order's own payment driver (null when
            // nothing is pending or the payment setup failed).
            'paymentAction' => $payments->forOrder($order),
            'canRetryPayment' => $payments->canRetry($order),
        ]);
    }
}
