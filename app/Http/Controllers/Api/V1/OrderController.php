<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\ConfirmOrderReceived;
use App\Actions\Checkout\ResolveKomercePaymentInstructions;
use App\Actions\Checkout\RetryKomercePayment;
use App\Actions\Checkout\SyncKomercePaymentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Http\Controllers\Controller;
use App\Models\OrderShipment;
use App\Models\User;
use App\Support\CustomerCatalogPresenter;
use App\Support\KomerceCourierAssets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Throwable;

final class OrderController extends Controller
{
    /** Marketplace-style order tabs: key => query constraint. */
    private const TABS = ['unpaid', 'processing', 'shipped', 'completed', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $tab = in_array($request->query('status'), self::TABS, true) ? (string) $request->query('status') : null;

        $orders = Order::query()
            ->where('customer_id', $user->id)
            ->when($tab !== null, fn (Builder $query) => $this->applyTab($query, (string) $tab))
            ->with(['items.product', 'paymentMethod'])
            ->latest()
            ->paginate(10);

        $counts = [];
        foreach (self::TABS as $key) {
            $counts[$key] = $this->applyTab(Order::query()->where('customer_id', $user->id), $key)->count();
        }

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order): array => $this->orderSummary($order))->values()->all(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
                'counts' => $counts,
            ],
        ]);
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyTab(Builder $query, string $tab): Builder
    {
        $closedPayment = [PaymentStatus::Voided, PaymentStatus::Refunded];

        return match ($tab) {
            'unpaid' => $query->whereIn('payment_status', [PaymentStatus::Pending, PaymentStatus::Authorized])
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Archived]),
            'processing' => $query->where('payment_status', PaymentStatus::Paid)
                ->whereIn('status', [OrderStatus::New, OrderStatus::Processing])
                ->where('shipping_status', ShippingStatus::Unfulfilled),
            'shipped' => $query->where('payment_status', PaymentStatus::Paid)
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Completed])
                ->whereIn('shipping_status', [ShippingStatus::Shipped, ShippingStatus::PartiallyShipped, ShippingStatus::PartiallyDelivered, ShippingStatus::Delivered]),
            'completed' => $query->where('status', OrderStatus::Completed),
            'cancelled' => $query->where(fn (Builder $q) => $q->where('status', OrderStatus::Cancelled)->orWhereIn('payment_status', $closedPayment)),
            default => $query,
        };
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);
        $order->load(['items.product', 'shippingAddress', 'paymentMethod']);

        $shipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(static function (OrderShipment $shipment): array {
                $history = data_get($shipment->metadata, 'komerce.tracking_history', []);

                return [
                    'id' => $shipment->id,
                    'status' => $shipment->status,
                    'awb' => $shipment->awb,
                    'tracking_number' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier_name ?? $shipment->carrier_code,
                    'service' => $shipment->service_name ?? $shipment->service_code,
                    'carrier_logo' => KomerceCourierAssets::logoUrl($shipment->carrier_code),
                    'cost' => $shipment->cost,
                    'tracking_history' => is_array($history) ? array_values($history) : [],
                ];
            })
            ->values()
            ->all();

        $resolve = resolve(ResolveKomercePaymentInstructions::class);
        $presenter = resolve(CustomerCatalogPresenter::class);
        $subtotal = (int) $order->items->sum(static fn ($item): int => (int) $item->unit_price_amount * (int) $item->quantity);
        $shippingTotal = (int) collect($shipments)->sum(static fn (array $shipment): int => (int) ($shipment['cost'] ?? 0));

        return response()->json([
            'data' => [
                ...$this->orderSummary($order),
                'items' => $order->items->map(static fn ($item): array => [
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (int) $item->unit_price_amount,
                    'thumbnail' => $presenter->purchasableThumbnail($item->product),
                    'slug' => $presenter->purchasableSlug($item->product),
                ])->values()->all(),
                'shipping_address' => $this->shippingAddressPayload($order),
                'totals' => [
                    'subtotal' => $subtotal,
                    'shipping' => $shippingTotal,
                    // Discounts/taxes are whatever remains between the lines and the charged amount.
                    'adjustment' => (int) $order->price_amount - $subtotal - $shippingTotal,
                    'total' => (int) $order->price_amount,
                ],
                'shipments' => $shipments,
                'payment' => $resolve->handle($order),
                'can_retry_payment' => $resolve->canRetry($order),
            ],
        ]);
    }

    public function retryPayment(Request $request, string $number): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);

        try {
            $instructions = resolve(RetryKomercePayment::class)->handle($order);
        } catch (Throwable $e) {
            report($e);

            // RetryKomercePayment messages are English and may carry gateway
            // details; show the customer something actionable instead.
            $message = match (true) {
                str_contains($e->getMessage(), 'already paid') => 'Pesanan ini sudah dibayar. Tarik untuk memperbarui status.',
                str_contains($e->getMessage(), 'not eligible'), str_contains($e->getMessage(), 'no Komerce payment') => 'Pembayaran pesanan ini tidak bisa diulang.',
                default => 'Belum bisa membuat ulang pembayaran. Coba lagi sebentar.',
            };

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['data' => ['payment' => $instructions]]);
    }

    public function syncPayment(Request $request, string $number): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);

        try {
            $result = resolve(SyncKomercePaymentStatus::class)->handle($order);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Belum bisa cek status pembayaran.'], 422);
        }

        $order->refresh();

        return response()->json([
            'data' => [
                'sync' => $result,
                'payment_status' => $order->payment_status->value,
                'payment' => resolve(ResolveKomercePaymentInstructions::class)->handle($order),
            ],
        ]);
    }

    public function track(Request $request, string $number, int $shipment): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);
        $row = OrderShipment::query()
            ->where('order_id', $order->id)
            ->where('id', $shipment)
            ->firstOrFail();

        try {
            resolve(RefreshShipmentTracking::class)->handle($row);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal memperbarui pelacakan.'], 422);
        }

        return $this->show($request, $number);
    }

    public function confirmReceived(Request $request, string $number): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);

        try {
            resolve(ConfirmOrderReceived::class)->handle($order);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Pesanan belum bisa ditandai diterima.',
                'errors' => $e->errors(),
            ], 422);
        }

        return $this->show($request, $number);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderSummary(Order $order): array
    {
        $first = $order->items->first();
        $presenter = resolve(CustomerCatalogPresenter::class);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'shipping_status' => $order->shipping_status->value,
            'amount' => (int) $order->price_amount,
            'currency' => $order->currency_code,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'item_count' => (int) $order->items->sum('quantity'),
            'line_count' => $order->items->count(),
            'first_item' => $first ? [
                'name' => $first->name,
                'quantity' => (int) $first->quantity,
                'thumbnail' => $presenter->purchasableThumbnail($first->product),
            ] : null,
            'payment_method' => $order->paymentMethod?->title,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shippingAddressPayload(Order $order): ?array
    {
        $address = $order->shippingAddress;
        if ($address !== null) {
            return [
                'name' => trim($address->first_name.' '.$address->last_name),
                'phone' => $address->phone,
                'street_address' => $address->street_address,
                'area' => implode(', ', array_filter([$address->city, $address->state, $address->postal_code])),
            ];
        }

        // Mobile checkout keeps the address on the order metadata.
        $metadata = $order->getAttribute('metadata');
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $stored = data_get($metadata, 'shipping_address');
        if (! is_array($stored)) {
            return null;
        }

        return [
            'name' => trim(($stored['first_name'] ?? '').' '.($stored['last_name'] ?? '')),
            'phone' => $stored['phone_number'] ?? null,
            'street_address' => $stored['street_address'] ?? '',
            'area' => $stored['rajaongkir_destination_label']
                ?? implode(', ', array_filter([$stored['city'] ?? null, $stored['state'] ?? null, $stored['postal_code'] ?? null])),
        ];
    }

    private function ownedOrder(Request $request, string $number): Order
    {
        return Order::query()
            ->where('customer_id', $this->customer($request)->id)
            ->where('number', $number)
            ->firstOrFail();
    }

    private function customer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
