<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\CancelCustomerOrder;
use App\Actions\Account\SubmitReturnRequest;
use App\Actions\Account\UploadPaymentProof;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Payment\PaymentActions;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShipmentTimeline;
use App\Shipping\ShippingCapabilities;
use App\Support\CarrierLogos;
use App\Support\CustomerCatalogPresenter;
use App\Support\OrderPaymentType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Shopper\Core\Enum\OrderRefundStatus;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;

final class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->string('tab', 'all');

        $query = Order::query()
            ->where('customer_id', auth()->id())
            ->with(['items.product', 'shippingAddress']);

        $query = match ($tab) {
            'unpaid', 'not-shipped' => $query
                ->whereIn('payment_status', [PaymentStatus::Pending, PaymentStatus::Authorized])
                ->where('shipping_status', ShippingStatus::Unfulfilled)
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Archived, OrderStatus::Completed])
                ->whereDoesntHave('paymentMethod', static fn ($pm) => $pm->where('slug', 'cod')),
            'processing' => $query
                ->where(static function ($q): void {
                    $q->where('payment_status', PaymentStatus::Paid)
                        ->orWhereHas('paymentMethod', static fn ($pm) => $pm->where('slug', 'cod'));
                })
                ->whereIn('status', [OrderStatus::New, OrderStatus::Processing])
                ->where('shipping_status', ShippingStatus::Unfulfilled),
            'shipped' => $query
                ->whereNotIn('payment_status', [PaymentStatus::Voided, PaymentStatus::Refunded])
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Completed, OrderStatus::Archived])
                ->whereNotIn('shipping_status', [ShippingStatus::Unfulfilled, ShippingStatus::Returned, ShippingStatus::PartiallyReturned]),
            'completed' => $query->where('status', OrderStatus::Completed)
                ->whereNotIn('payment_status', [PaymentStatus::Voided, PaymentStatus::Refunded]),
            'cancelled' => $query->where(static fn ($q) => $q
                ->where('status', OrderStatus::Cancelled)
                ->orWhereIn('payment_status', [PaymentStatus::Voided, PaymentStatus::Refunded])),
            default => $query,
        };

        $presenter = resolve(CustomerCatalogPresenter::class);

        $orders = $query
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(static function (Order $order) use ($presenter): Order {
                $order->items->loadMorph('product', [
                    ProductVariant::class => ['media', 'product.media'],
                    Product::class => ['media'],
                ]);
                foreach ($order->items as $item) {
                    $thumb = $presenter->purchasableThumbnail($item->product);
                    if (! is_string($thumb) || $thumb === '') {
                        $thumb = $item->product?->images[0]['url'] ?? $item->product?->product?->images[0]['url'] ?? null;
                    }
                    $item->setAttribute('thumbnail_url', is_string($thumb) && $thumb !== '' ? $thumb : null);
                    $item->setAttribute('product_slug', $presenter->purchasableSlug($item->product));
                }

                return $order->makeHidden(['metadata', 'notes']);
            });

        return Inertia::render('account/orders', [
            'orders' => $orders,
            'filters' => ['tab' => $tab],
        ]);
    }

    public function show(Order $order): Response
    {
        abort_unless($order->customer_id === auth()->id(), 403);

        $order->load([
            'items.product',
            'items.taxLines',
            'shippingAddress',
            'billingAddress',
            'shippingOption.carrier',
            'paymentMethod',
        ]);

        $order->items->loadMorph('product', [
            ProductVariant::class => ['media', 'product.media'],
            Product::class => ['media'],
        ]);
        $presenter = resolve(CustomerCatalogPresenter::class);
        foreach ($order->items as $item) {
            $thumb = $presenter->purchasableThumbnail($item->product);
            if (! is_string($thumb) || $thumb === '') {
                $thumb = $item->product?->images[0]['url'] ?? $item->product?->product?->images[0]['url'] ?? null;
            }
            $item->setAttribute('thumbnail_url', is_string($thumb) && $thumb !== '' ? $thumb : null);
            $item->setAttribute('product_slug', $presenter->purchasableSlug($item->product));
        }

        $order->shippingAddress?->append('full_name');
        $order->billingAddress?->append('full_name');

        $orderShipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->with('inventory')
            ->orderBy('id')
            ->get();
        $timelines = ShipmentTimeline::forShipments((int) $order->id, $orderShipments);
        $shippingCapabilities = resolve(ShippingCapabilities::class);

        $shipments = $orderShipments
            ->map(static function (OrderShipment $shipment) use ($orderShipments, $timelines, $shippingCapabilities): array {
                $history = $timelines[(int) $shipment->id] ?? [];
                $hasAwb = filled($shipment->awb)
                    || filled($shipment->tracking_number)
                    || collect((array) $shipment->metadata)->contains(
                        static fn (mixed $bucket): bool => is_array($bucket) && filled($bucket['awb'] ?? null),
                    );
                $isCancelled = strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED;
                $isSuperseded = ShipmentMetadata::isSupersededByReship($shipment, $orderShipments);

                return [
                    'id' => $shipment->id,
                    'inventory_name' => $shipment->inventory?->name,
                    'status' => $shipment->status,
                    'is_superseded' => $isSuperseded,
                    'awb' => $shipment->awb,
                    'tracking_number' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier_name ?? $shipment->carrier_code,
                    'service' => $shipment->service_name ?? $shipment->service_code,
                    'carrier_logo' => resolve(CarrierLogos::class)->forCode($shipment->carrier_code),
                    'cost' => $shipment->cost,
                    'currency' => $shipment->currency_code,
                    'can_track' => ! $isCancelled && $hasAwb && $shippingCapabilities->trackerFor($shipment) !== null,
                    'tracking_history' => $history,
                ];
            });

        $grossSubtotal = (int) $order->items->sum(
            static fn ($item): int => (int) $item->unit_price_amount * (int) $item->quantity,
        );
        $discountTotal = (int) $order->items->sum(static fn ($item): int => (int) $item->discount_amount);
        $liveShipments = $orderShipments->reject(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED,
        );
        $activeCost = (int) $liveShipments->sum('cost');
        $shippingTotal = ($liveShipments->isNotEmpty() && $activeCost > 0)
            ? $activeCost
            : ($orderShipments->isNotEmpty() ? (int) $orderShipments->sum('cost') : (int) ($order->shippingOption?->price ?? 0));
        $taxTotal = (int) ($order->tax_amount ?? 0);
        $taxInclusive = ($grossSubtotal - $discountTotal + $shippingTotal) === (int) $order->price_amount;

        $payments = resolve(PaymentActions::class);
        $proofs = OrderPaymentProof::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->get()
            ->map(static fn (OrderPaymentProof $proof): array => [
                'id' => $proof->id,
                'status' => $proof->status,
                'original_name' => $proof->original_name,
                'sender_name' => $proof->sender_name,
                'sender_bank' => $proof->sender_bank,
                'transfer_amount' => $proof->transfer_amount,
                'verification_notes' => $proof->verification_notes,
                'created_at' => optional($proof->created_at)?->toIso8601String(),
                'file_url' => route('account.orders.payment-proofs.show', ['order' => $order->id, 'proof' => $proof->id]),
            ])
            ->values()
            ->all();

        $returns = OrderReturnRequest::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->get()
            ->map(static fn (OrderReturnRequest $r): array => [
                'id' => $r->id,
                'status' => $r->status,
                'reason' => $r->reason,
                'description' => $r->description,
                'items' => $r->items,
                'resolution_notes' => $r->resolution_notes,
                'created_at' => optional($r->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        $refundedTotal = (int) OrderRefund::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [OrderRefundStatus::Refunded->value, OrderRefundStatus::Partial_Refund->value])
            ->sum('amount');

        $order->makeHidden(['metadata', 'notes']);

        return Inertia::render('account/order-show', [
            'order' => $order,
            'shipments' => $shipments,
            'paymentAction' => $payments->forOrder($order),
            'canRetryPayment' => $payments->canRetry($order),
            'canCancel' => resolve(CancelCustomerOrder::class)->canCancel($order),
            'canRequestReturn' => resolve(SubmitReturnRequest::class)->canRequest($order),
            'canUploadPaymentProof' => resolve(UploadPaymentProof::class)->canUpload($order),
            'isCod' => OrderPaymentType::isCod($order),
            'paymentProofs' => $proofs,
            'returnRequests' => $returns,
            'refundedTotal' => $refundedTotal,
            'summary' => [
                'subtotal' => $grossSubtotal,
                'discount' => $discountTotal,
                'tax' => $taxTotal,
                'tax_inclusive' => $taxInclusive,
                'shipping' => $shippingTotal,
                'total' => (int) $order->price_amount,
            ],
        ]);
    }
}
