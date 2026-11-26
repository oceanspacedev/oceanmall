<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Actions\Payment;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Stock\ReleaseOrderShipmentStock;
use App\Enums\OrderNotificationType;
use App\Integrations\Komerce\Komerce;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

final readonly class CancelUnpaidKomerceOrder
{
    public function __construct(
        private ReleaseOrderShipmentStock $releaseStock,
        private NotifyOrderCustomer $notifyOrderCustomer,
        private SyncKomercePaymentStatus $syncPaymentStatus,
        private CancelOrderWithProviders $cancelWithProviders,
    ) {}

    /**
     * @throws RuntimeException when Komerce cannot confirm the payment is still unpaid;
     *                          the order is left untouched so the next run retries.
     */
    public function handle(Order $order, string $reason = 'Payment expired'): Order
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            return $order;
        }

        if ($order->status === OrderStatus::Cancelled) {
            return $order;
        }

        // A VA/QRIS transfer can land minutes before expiry while its webhook
        // is still in flight: settle it instead of cancelling a paid order.
        if (Komerce::paymentEnabled() || Komerce::qrislyEnabled()) {
            $verdict = $this->syncPaymentStatus->reconcile($order);

            if ($verdict === 'lookup_failed') {
                throw new RuntimeException("Could not verify Komerce payment for order {$order->number}; not cancelling.");
            }

            // Paid (now settled) or paid with the wrong amount (already reported): a human decides.
            if (in_array($verdict, ['handled', 'already_processed', 'amount_mismatch'], true)) {
                return $order->refresh();
            }
        }

        $this->cancelRemotePayment($order, $reason);

        $cancelled = DB::transaction(function () use ($order): ?Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->payment_status === PaymentStatus::Paid || $locked->status === OrderStatus::Cancelled) {
                return null;
            }

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'payment_status' => PaymentStatus::Voided,
            ])->save();

            PaymentTransaction::query()
                ->where('order_id', $locked->id)
                ->where('driver', 'komerce')
                ->where('status', TransactionStatus::Pending)
                ->update(['status' => TransactionStatus::Failed]);

            $refreshed = $locked->refresh();

            if (! $this->cancelWithProviders->hasShipped($refreshed)) {
                $this->releaseStock->handle($refreshed);
            }

            return $refreshed;
        });

        if (! $cancelled instanceof Order) {
            return $order->refresh();
        }

        $this->notifyOrderCustomer->handle($cancelled, OrderNotificationType::Cancelled);
        event(new OrderCancelled($cancelled));

        return $cancelled;
    }

    private function cancelRemotePayment(Order $order, string $reason): void
    {
        $metadata = $this->decodeMetadata($order->getAttribute('metadata'));
        $provider = data_get($metadata, 'komerce.provider', 'payment_api');

        // QRISLY has no documented cancel endpoint — expire locally only.
        if ($provider === 'qrisly') {
            return;
        }

        $paymentId = data_get($metadata, 'komerce.payment_ref')
            ?? PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->where('driver', 'komerce')
                ->latest('id')
                ->value('reference');

        if (! is_string($paymentId) || trim($paymentId) === '') {
            return;
        }

        if (! Komerce::paymentEnabled()) {
            return;
        }

        try {
            Payment::driver('komerce')->cancelPayment(trim($paymentId));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
}
