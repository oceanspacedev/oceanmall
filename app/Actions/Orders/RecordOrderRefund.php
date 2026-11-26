<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\OrderNote;
use App\Models\PaymentDispute;
use App\Models\User;
use App\Payment\Drivers\ConfirmFirstStripeDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Shopper\Core\Enum\OrderRefundStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;

final class RecordOrderRefund
{
    public function handle(
        Order $order,
        int $amount,
        string $reason,
        User $actor,
        string $method = 'manual_bank_transfer',
        ?string $reference = null,
        ?int $returnRequestId = null,
        ?PaymentDispute $dispute = null,
        ?array $bankDetails = null,
    ): OrderRefund {
        if ($amount <= 0) {
            throw new RuntimeException('Nominal refund harus lebih besar dari 0.');
        }

        $cleanReason = trim($reason) !== '' ? trim($reason) : 'Refund pesanan';
        $driverCode = (string) ($order->paymentMethod?->driver ?: 'manual');

        return DB::transaction(static function () use (
            $order,
            $amount,
            $cleanReason,
            $actor,
            $method,
            $reference,
            $returnRequestId,
            $dispute,
            $bankDetails,
            $driverCode,
        ): OrderRefund {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            $alreadyRefunded = (int) OrderRefund::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', [OrderRefundStatus::Refunded->value, OrderRefundStatus::Partial_Refund->value])
                ->sum('amount');

            if ($dispute === null && ($alreadyRefunded + $amount) > (int) $lockedOrder->price_amount) {
                throw new RuntimeException(sprintf(
                    'Total akumulasi refund (%s) melebihi nilai pesanan (%s).',
                    shopper_money_format($alreadyRefunded + $amount, (string) $lockedOrder->currency_code),
                    shopper_money_format((int) $lockedOrder->price_amount, (string) $lockedOrder->currency_code),
                ));
            }

            $txReference = $reference ? trim($reference) : ('refund-'.$lockedOrder->number.'-'.now()->timestamp);
            $gatewayTx = null;

            if ($method === 'gateway') {
                $driver = Payment::driver($driverCode);

                if (! $driver->supportsRefunds()) {
                    throw new RuntimeException("Gateway {$driverCode} tidak mendukung API refund otomatis; gunakan refund transfer bank manual.");
                }

                $capture = PaymentTransaction::query()
                    ->where('order_id', $lockedOrder->id)
                    ->where('driver', $driverCode)
                    ->where('type', TransactionType::Capture)
                    ->where('status', TransactionStatus::Success)
                    ->latest('id')
                    ->first();

                if (! $capture instanceof PaymentTransaction || blank($capture->reference)) {
                    throw new RuntimeException('Referensi capture pembayaran tidak ditemukan untuk refund gateway.');
                }

                $gatewayAmount = $driver instanceof ConfirmFirstStripeDriver
                    ? ConfirmFirstStripeDriver::toStripeAmount($amount, (string) $lockedOrder->currency_code)
                    : $amount;

                $result = $driver->refundPayment((string) $capture->reference, $gatewayAmount, $cleanReason);

                if (! $result->success) {
                    throw new RuntimeException($result->message ?? 'Gateway menolak permintaan refund.');
                }

                $txReference = $result->reference ?: $txReference;
                $gatewayTx = $result->data;
            }

            $tx = PaymentTransaction::query()->create([
                'order_id' => $lockedOrder->id,
                'payment_method_id' => $lockedOrder->payment_method_id,
                'driver' => $driverCode,
                'type' => TransactionType::Refund,
                'status' => TransactionStatus::Success,
                'amount' => $amount,
                'currency_code' => $lockedOrder->currency_code,
                'reference' => $txReference,
                'notes' => $cleanReason,
                'metadata' => array_filter([
                    'refunded_by' => $actor->id,
                    'method' => $method,
                    'return_request_id' => $returnRequestId,
                    'dispute_id' => $dispute?->id,
                    'gateway_data' => $gatewayTx,
                ], static fn (mixed $v): bool => $v !== null),
            ]);

            $refund = OrderRefund::query()->create([
                'order_id' => $lockedOrder->id,
                'user_id' => $lockedOrder->customer_id,
                'reason' => $cleanReason,
                'amount' => $amount,
                'currency' => $lockedOrder->currency_code,
                'status' => OrderRefundStatus::Refunded,
                'notes' => $cleanReason,
                'payment_transaction_id' => $tx->id,
                'return_request_id' => $returnRequestId,
                'payment_dispute_id' => $dispute?->id,
                'driver' => $driverCode,
                'method' => $method,
                'reference' => $txReference,
                'bank_details' => $bankDetails,
                'refunded_by' => $actor->id,
                'processed_at' => now(),
                'metadata' => json_encode(['refunded_by' => $actor->id], JSON_THROW_ON_ERROR),
            ]);

            if ($dispute === null || in_array($lockedOrder->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                $newTotal = $alreadyRefunded + $amount;
                $newPaymentStatus = $newTotal >= (int) $lockedOrder->price_amount
                    ? PaymentStatus::Refunded
                    : PaymentStatus::PartiallyRefunded;

                $lockedOrder->forceFill(['payment_status' => $newPaymentStatus])->save();
            }

            OrderNote::query()->create([
                'order_id' => $lockedOrder->id,
                'user_id' => $actor->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => sprintf(
                    'Refund %s dicatat via %s (Ref: %s): %s',
                    shopper_money_format($amount, (string) $lockedOrder->currency_code),
                    $method,
                    $txReference,
                    $cleanReason,
                ),
                'metadata' => [
                    'refund_id' => $refund->id,
                    'transaction_id' => $tx->id,
                    'amount' => $amount,
                ],
            ]);

            return $refund;
        });
    }
}
