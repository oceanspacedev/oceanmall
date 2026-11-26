<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Actions\Payment;

use App\Payment\Exceptions\PaymentRetryRefused;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

final class RetryKomercePayment
{
    public function __construct(
        private readonly CreateKomercePayment $createPayment,
        private readonly ResolveKomercePaymentInstructions $resolveInstructions,
        private readonly SyncKomercePaymentStatus $syncPaymentStatus,
    ) {}

    /**
     * Recreate a Komerce VA/QRIS charge for an unpaid order.
     *
     * @return array<string, mixed>
     */
    public function handle(Order $order): array
    {
        $lock = Cache::lock('komerce:retry:'.$order->id, 15);

        if (! $lock->get()) {
            throw PaymentRetryRefused::notEligible('Permintaan pembayaran lain sedang diproses. Tunggu sebentar.');
        }

        try {
            $order->refresh();

            if (! $this->resolveInstructions->canRetry($order)) {
                throw PaymentRetryRefused::notEligible('Order is not eligible for Komerce payment retry.');
            }

            if ($order->payment_status === PaymentStatus::Paid) {
                throw PaymentRetryRefused::alreadyPaid();
            }

            $order->loadMissing('paymentMethod');
            $method = $order->paymentMethod;

            if (! $method instanceof PaymentMethod || ($method->driver ?? null) !== 'komerce') {
                throw PaymentRetryRefused::notEligible('Order has no Komerce payment method.');
            }

            $selected = $this->selectedMethodPayload($order, $method);

            try {
                $this->closePreviousCharge($order);

                if ($order->refresh()->payment_status === PaymentStatus::Paid) {
                    throw PaymentRetryRefused::alreadyPaid();
                }

                return $this->createPayment->handle($order, $selected);
            } catch (PaymentRetryRefused $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);

                throw new RuntimeException('Unable to recreate Komerce payment: '.$e->getMessage(), 0, $e);
            }
        } finally {
            $lock->release();
        }
    }

    private function closePreviousCharge(Order $order): void
    {
        $metadata = $this->decodeMetadata($order->getAttribute('metadata'));
        $provider = (string) data_get($metadata, 'komerce.provider', 'payment_api');

        if ($provider === 'qrisly') {
            $verdict = $this->syncPaymentStatus->reconcile($order);

            if (in_array($verdict, ['handled', 'already_processed'], true)) {
                throw PaymentRetryRefused::alreadyPaid('The previous Komerce payment is already paid; synchronize the order instead of creating another charge.');
            }

            if ($verdict === 'lookup_failed') {
                throw new RuntimeException('Could not verify previous QRISLY payment; try again later.');
            }

            return;
        }

        if ($provider !== 'payment_api') {
            return;
        }

        $transaction = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', 'komerce')
            ->where('status', TransactionStatus::Pending)
            ->latest('id')
            ->first();
        $paymentId = trim((string) (
            data_get($metadata, 'komerce.payment_ref')
            ?? $transaction?->reference
            ?? ''
        ));

        if ($paymentId === '') {
            return;
        }

        $remote = Payment::driver('komerce')->retrievePayment($paymentId);

        if ($remote->status === 'captured') {
            throw PaymentRetryRefused::alreadyPaid('The previous Komerce payment is already paid; synchronize the order instead of creating another charge.');
        }

        if ($remote->status === 'pending') {
            Payment::driver('komerce')->cancelPayment($paymentId);
        } elseif (! in_array($remote->status, ['failed', 'canceled'], true)) {
            throw new RuntimeException('The previous Komerce payment has an unknown status and cannot be replaced safely.');
        }

        if ($transaction instanceof PaymentTransaction) {
            $transaction->update(['status' => TransactionStatus::Failed]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedMethodPayload(Order $order, PaymentMethod $method): array
    {
        $orderMeta = $this->decodeMetadata($order->getAttribute('metadata'));
        $orderKomerce = is_array($orderMeta['komerce'] ?? null) ? $orderMeta['komerce'] : [];
        $orderPayment = is_array($orderMeta['payment'] ?? null) ? $orderMeta['payment'] : [];

        $meta = $method->metadata;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($meta)) {
            $meta = [];
        }

        $paymentType = $orderKomerce['payment_type']
            ?? $orderPayment['payment_type']
            ?? $meta['payment_type']
            ?? (str_contains(mb_strtolower((string) ($method->slug ?? '')), 'qris') ? 'qris' : 'bank_transfer');

        $channelCode = $orderKomerce['channel_code']
            ?? $orderPayment['channel_code']
            ?? $meta['channel_code']
            ?? null;

        if ($paymentType === 'bank_transfer' && ($channelCode === null || $channelCode === '')) {
            $slug = mb_strtolower((string) ($method->slug ?? ''));
            $title = mb_strtolower((string) ($method->title ?? ''));

            if (str_contains($slug, 'bri') || str_contains($title, 'bri')) {
                $channelCode = 'BRI';
            } elseif (str_contains($slug, 'bni') || str_contains($title, 'bni')) {
                $channelCode = 'BNI';
            } elseif (str_contains($slug, 'mandiri') || str_contains($title, 'mandiri')) {
                $channelCode = 'MANDIRI';
            } elseif (str_contains($slug, 'permata') || str_contains($title, 'permata')) {
                $channelCode = 'PERMATA';
            } else {
                $channelCode = 'BCA';
            }
        }

        return [
            'id' => $method->id,
            'driver' => 'komerce',
            'title' => $method->title,
            'channel_code' => $channelCode,
            'payment_type' => $paymentType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
