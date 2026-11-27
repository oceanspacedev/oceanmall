<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Actions\Payment;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Stock\ReleaseOrderShipmentStock;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Support\KomercePaymentLookupContext;
use App\Integrations\Komerce\Support\KomercePaymentProvider;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
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
        if ($this->subProviderEnabled($order)) {
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

        $order->refresh();

        if ($order->payment_status === PaymentStatus::Paid || $order->status === OrderStatus::Cancelled) {
            return $order;
        }

        $cancelled = $this->cancelWithProviders->handle($order, throughPaymentProvider: false, reason: $reason);

        if ($cancelled->status === OrderStatus::Cancelled) {
            PaymentTransaction::query()
                ->where('order_id', $cancelled->id)
                ->where('driver', 'komerce')
                ->where('status', TransactionStatus::Pending)
                ->update(['status' => TransactionStatus::Failed]);
        }

        return $cancelled->refresh();
    }

    private function subProviderEnabled(Order $order): bool
    {
        return $this->resolveProvider($order) === 'qrisly'
            ? Komerce::qrislyEnabled()
            : Komerce::paymentEnabled();
    }

    private function resolveProvider(Order $order): string
    {
        return KomercePaymentProvider::resolve($order);
    }

    private function cancelRemotePayment(Order $order, string $reason): void
    {
        $metadata = $this->decodeMetadata($order->getAttribute('metadata'));
        $provider = $this->resolveProvider($order);

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

        $lookup = resolve(KomercePaymentLookupContext::class);
        $lookup->setProvider($provider);

        try {
            Payment::driver('komerce')->cancelPayment(trim($paymentId));
        } catch (Throwable $e) {
            report($e);
        } finally {
            $lookup->clear();
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
