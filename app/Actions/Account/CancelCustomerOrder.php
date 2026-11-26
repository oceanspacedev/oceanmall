<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Throwable;

final readonly class CancelCustomerOrder
{
    public function __construct(private CancelOrderWithProviders $cancelOrder) {}

    public function canCancel(Order $order): bool
    {
        if (! in_array($order->status, [OrderStatus::New, OrderStatus::Processing], true)) {
            return false;
        }

        if ($order->payment_status !== PaymentStatus::Pending) {
            return false;
        }

        $shippingStatus = $order->shipping_status instanceof ShippingStatus
            ? $order->shipping_status
            : ShippingStatus::tryFrom((string) $order->shipping_status);

        if ($shippingStatus !== null && $shippingStatus !== ShippingStatus::Unfulfilled) {
            return false;
        }

        return ! $this->cancelOrder->hasShipped($order);
    }

    public function handle(Order $order, User $customer, ?string $reason = null): Order
    {
        if ((int) $order->customer_id !== (int) $customer->id) {
            abort(403);
        }

        if (! $this->canCancel($order)) {
            throw ValidationException::withMessages([
                'cancel' => __('Pesanan ini sudah diproses atau tidak dapat dibatalkan secara mandiri.'),
            ]);
        }

        $cleanReason = trim((string) $reason) !== ''
            ? trim((string) $reason)
            : 'Dibatalkan oleh pelanggan';

        try {
            $result = $this->cancelOrder->handle(
                order: $order,
                throughPaymentProvider: true,
                reason: $cleanReason,
                actor: $customer,
            );
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'cancel' => __('Belum bisa membatalkan pesanan karena verifikasi ke penyedia pembayaran gagal. Coba lagi sebentar.'),
            ]);
        }

        if ($result->payment_status === PaymentStatus::Paid) {
            throw ValidationException::withMessages([
                'cancel' => __('Pembayaran sudah diterima oleh penyedia sehingga pesanan tidak dapat dibatalkan.'),
            ]);
        }

        OrderPaymentProof::query()
            ->where('order_id', $result->id)
            ->where('status', OrderPaymentProof::STATUS_PENDING)
            ->update([
                'status' => OrderPaymentProof::STATUS_REJECTED,
                'verification_notes' => 'Pesanan dibatalkan oleh pelanggan.',
                'verified_at' => now(),
            ]);

        return $result->refresh();
    }
}
