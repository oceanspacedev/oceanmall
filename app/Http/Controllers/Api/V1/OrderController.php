<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\CancelCustomerOrder;
use App\Actions\Account\ConfirmOrderReceived;
use App\Actions\Account\SubmitReturnRequest;
use App\Actions\Account\UploadPaymentProof;
use App\Actions\Orders\RetryOrderPayment;
use App\Actions\Orders\SyncOrderPayment;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Http\Controllers\Controller;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Payment\Exceptions\PaymentRetryRefused;
use App\Payment\PaymentActions;
use App\Shipping\AddressDestination;
use App\Shipping\ShipmentTimeline;
use App\Shipping\ShippingCapabilities;
use App\Support\CarrierLogos;
use App\Support\CustomerCatalogPresenter;
use App\Support\OrderPaymentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Shopper\Core\Enum\OrderRefundStatus;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;
use Shopper\Core\Models\Review;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
                ->where('shipping_status', ShippingStatus::Unfulfilled)
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Archived, OrderStatus::Completed])
                ->whereDoesntHave('paymentMethod', static fn (Builder $pm) => $pm->where('slug', 'cod')),
            'processing' => $query
                ->where(static function (Builder $q): void {
                    $q->where('payment_status', PaymentStatus::Paid)
                        ->orWhereHas('paymentMethod', static fn (Builder $pm) => $pm->where('slug', 'cod'));
                })
                ->whereIn('status', [OrderStatus::New, OrderStatus::Processing])
                ->where('shipping_status', ShippingStatus::Unfulfilled),
            'shipped' => $query->whereNotIn('payment_status', $closedPayment)
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Completed, OrderStatus::Archived])
                ->whereNotIn('shipping_status', [ShippingStatus::Unfulfilled, ShippingStatus::Returned, ShippingStatus::PartiallyReturned]),
            'completed' => $query->where('status', OrderStatus::Completed)
                ->whereNotIn('payment_status', $closedPayment),
            'cancelled' => $query->where(fn (Builder $q) => $q->where('status', OrderStatus::Cancelled)->orWhereIn('payment_status', $closedPayment)),
            default => $query,
        };
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);
        $user = $this->customer($request);
        $order->load(['items.product', 'shippingAddress', 'paymentMethod']);

        $orderShipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();
        $timelines = ShipmentTimeline::forShipments((int) $order->id, $orderShipments);
        $shippingCapabilities = resolve(ShippingCapabilities::class);

        $shipments = $orderShipments
            ->map(static function (OrderShipment $shipment) use ($timelines, $shippingCapabilities): array {
                $history = array_map(
                    static fn (array $event): array => [...$event, 'date' => $event['datetime']],
                    $timelines[(int) $shipment->id] ?? [],
                );

                return [
                    'id' => $shipment->id,
                    'status' => $shipment->status,
                    'awb' => $shipment->awb,
                    'tracking_number' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier_name ?? $shipment->carrier_code,
                    'service' => $shipment->service_name ?? $shipment->service_code,
                    'carrier_logo' => resolve(CarrierLogos::class)->forCode($shipment->carrier_code),
                    'cost' => $shipment->cost,
                    'can_track' => $shippingCapabilities->trackerFor($shipment) !== null,
                    'tracking_history' => $history,
                ];
            })
            ->values()
            ->all();

        $payments = resolve(PaymentActions::class);
        $paymentAction = $payments->forOrder($order);
        $presenter = resolve(CustomerCatalogPresenter::class);
        $subtotal = (int) $order->items->sum(static fn ($item): int => (int) $item->unit_price_amount * (int) $item->quantity);
        $discountTotal = (int) $order->items->sum(static fn ($item): int => (int) $item->discount_amount);
        $shippingTotal = (int) collect($shipments)->sum(static fn (array $shipment): int => (int) ($shipment['cost'] ?? 0));
        $taxTotal = (int) ($order->tax_amount ?? 0);
        $taxInclusive = ($subtotal - $discountTotal + $shippingTotal) === (int) $order->price_amount;

        // Server-verified "already reviewed" flag per purchased product (resolves variants to parent Product id).
        $productIds = $order->items->map(static function ($item): ?int {
            if ($item->product instanceof ProductVariant) {
                return (int) $item->product->product_id;
            }
            if ($item->product instanceof Product) {
                return (int) $item->product->id;
            }

            return $item->product_type === 'product' ? (int) $item->product_id : null;
        })->filter()->unique()->values()->all();

        $reviewedProductIds = $productIds !== []
            ? Review::query()
                ->where('author_type', $user->getMorphClass())
                ->where('author_id', $user->id)
                ->where('reviewrateable_type', (new Product)->getMorphClass())
                ->whereIn('reviewrateable_id', $productIds)
                ->pluck('reviewrateable_id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
            : [];

        $refundedTotal = (int) OrderRefund::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [OrderRefundStatus::Refunded->value, OrderRefundStatus::Partial_Refund->value])
            ->sum('amount');

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

        return response()->json([
            'data' => [
                ...$this->orderSummary($order),
                'server_time' => now()->toIso8601String(),
                'items' => $order->items->map(static function ($item) use ($presenter, $reviewedProductIds): array {
                    $parentId = $item->product instanceof ProductVariant
                        ? (int) $item->product->product_id
                        : (int) ($item->product?->id ?? ($item->product_type === 'product' ? $item->product_id : 0));

                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (int) $item->unit_price_amount,
                        'thumbnail' => $presenter->purchasableThumbnail($item->product),
                        'slug' => $presenter->purchasableSlug($item->product),
                        'reviewed' => $parentId > 0 && in_array($parentId, $reviewedProductIds, true),
                    ];
                })->values()->all(),
                'shipping_address' => $this->shippingAddressPayload($order),
                'totals' => [
                    'subtotal' => $subtotal,
                    'shipping' => $shippingTotal,
                    'adjustment' => (int) $order->price_amount - $subtotal - $shippingTotal,
                    'total' => (int) $order->price_amount,
                ],
                'breakdown' => [
                    'subtotal' => $subtotal,
                    'discount' => $discountTotal,
                    'tax' => $taxTotal,
                    'tax_inclusive' => $taxInclusive,
                    'shipping' => $shippingTotal,
                    'refunded' => $refundedTotal,
                    'total' => (int) $order->price_amount,
                ],
                'shipments' => $shipments,
                'payment_action' => $paymentAction,
                'payment' => PaymentActions::legacyInstructions($paymentAction),
                'can_retry_payment' => $payments->canRetry($order),
                'can_cancel' => resolve(CancelCustomerOrder::class)->canCancel($order),
                'can_request_return' => resolve(SubmitReturnRequest::class)->canRequest($order),
                'can_upload_payment_proof' => resolve(UploadPaymentProof::class)->canUpload($order),
                'is_cod' => OrderPaymentType::isCod($order),
                'payment_proofs' => $proofs,
                'return_requests' => $returns,
            ],
        ]);
    }

    public function retryPayment(Request $request, string $number): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);

        try {
            $action = resolve(RetryOrderPayment::class)->handle($order, [
                'return_url' => route('payment.return.mobile', ['order' => $order->number]),
            ]);
        } catch (Throwable $e) {
            report($e);

            // Driver messages are English and may carry gateway details; show
            // the customer something actionable instead.
            $message = match ($e instanceof PaymentRetryRefused ? $e->reason : null) {
                PaymentRetryRefused::ALREADY_PAID => 'Pesanan ini sudah dibayar. Tarik untuk memperbarui status.',
                PaymentRetryRefused::NOT_ELIGIBLE => 'Pembayaran pesanan ini tidak bisa diulang.',
                default => 'Belum bisa membuat ulang pembayaran. Coba lagi sebentar.',
            };

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['data' => [
            'payment_action' => $action,
            'payment' => PaymentActions::legacyInstructions($action),
        ]]);
    }

    public function syncPayment(Request $request, string $number): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);

        try {
            $result = resolve(SyncOrderPayment::class)->handle($order);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Belum bisa cek status pembayaran.'], 422);
        }

        $order->refresh();
        $action = resolve(PaymentActions::class)->forOrder($order);

        return response()->json([
            'data' => [
                'sync' => $result,
                'payment_status' => $order->payment_status->value,
                'payment_action' => $action,
                'payment' => PaymentActions::legacyInstructions($action),
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
            $asked = resolve(RefreshShipmentTracking::class)->handleForCustomer($row);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal memperbarui pelacakan.'], 422);
        }

        // tracking_refreshed false: the parcel is final, or was refreshed
        // minutes ago (the stored tracking is returned as it is).
        $response = $this->show($request, $number);

        return $response->setData([...(array) $response->getData(true), 'tracking_refreshed' => $asked]);
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

    public function cancel(Request $request, string $number, CancelCustomerOrder $action): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $action->handle($order, $this->customer($request), $data['reason'] ?? null);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Pesanan belum bisa dibatalkan.',
                'errors' => $e->errors(),
            ], 422);
        }

        return $this->show($request, $number);
    }

    public function storeReturn(Request $request, string $number, SubmitReturnRequest $action): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        try {
            $action->handle(
                order: $order,
                customer: $this->customer($request),
                reason: (string) $data['reason'],
                description: $data['description'] ?? null,
                requestedItems: $data['items'],
                evidenceFiles: $request->file('evidence', []),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Pengajuan retur tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        }

        return $this->show($request, $number)->setStatusCode(201);
    }

    public function uploadProof(Request $request, string $number, UploadPaymentProof $action): JsonResponse
    {
        $order = $this->ownedOrder($request, $number);
        $data = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_bank' => ['nullable', 'string', 'max:80'],
            'transfer_amount' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $action->handle(
                order: $order,
                customer: $this->customer($request),
                file: $request->file('proof'),
                senderName: $data['sender_name'] ?? null,
                senderBank: $data['sender_bank'] ?? null,
                transferAmount: isset($data['transfer_amount']) ? (int) $data['transfer_amount'] : null,
                notes: $data['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Gagal mengunggah bukti transfer.',
                'errors' => $e->errors(),
            ], 422);
        }

        return $this->show($request, $number)->setStatusCode(201);
    }

    public function showProof(Request $request, string $number, OrderPaymentProof $proof): StreamedResponse
    {
        $order = $this->ownedOrder($request, $number);
        abort_unless((int) $proof->order_id === (int) $order->id, 404);
        abort_unless(Storage::disk($proof->disk)->exists($proof->path), 404);

        return Storage::disk($proof->disk)->response($proof->path, $proof->original_name, [
            'Content-Type' => $proof->mime_type,
        ]);
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
            // A method without a driver is Shopper's manual one.
            'payment_driver' => filled($driver = $order->paymentMethod?->driver) ? trim((string) $driver) : 'manual',
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
            'area' => AddressDestination::label($stored)
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
