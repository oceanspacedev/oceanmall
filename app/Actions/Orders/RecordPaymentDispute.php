<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\OrderNote;
use App\Models\PaymentDispute;
use Shopper\Core\Models\Order;
use Throwable;

/**
 * Idempotently logs a payment dispute (paid-after-cancel, double-payment,
 * amount mismatch) so /cpanel Finance & CS teams have an actionable queue.
 */
final class RecordPaymentDispute
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function handle(
        ?Order $order,
        string $driver,
        string $type,
        string $reference,
        ?int $amount = null,
        ?int $expectedAmount = null,
        ?string $currencyCode = null,
        ?string $reason = null,
        ?array $payload = null,
    ): ?PaymentDispute {
        $normalizedReference = trim($reference) !== ''
            ? trim($reference)
            : 'order-'.($order?->id ?? 'unknown').'-'.substr(sha1((string) json_encode($payload)), 0, 12);

        try {
            $dispute = PaymentDispute::query()->firstOrCreate(
                [
                    'driver' => $driver,
                    'reference' => $normalizedReference,
                    'type' => $type,
                ],
                [
                    'order_id' => $order?->id,
                    'status' => PaymentDispute::STATUS_OPEN,
                    'amount' => $amount,
                    'expected_amount' => $expectedAmount ?? ($order !== null ? (int) $order->price_amount : null),
                    'currency_code' => $currencyCode ?? ($order?->currency_code ?: 'IDR'),
                    'reason' => $reason,
                    'provider_payload' => $payload,
                ],
            );

            if ($dispute->wasRecentlyCreated && $order instanceof Order) {
                OrderNote::query()->create([
                    'order_id' => $order->id,
                    'type' => OrderNote::TYPE_AUDIT,
                    'body' => sprintf(
                        'Sengketa pembayaran terdeteksi [%s] (%s): ref %s.',
                        $type,
                        $driver,
                        $normalizedReference,
                    ),
                    'metadata' => [
                        'dispute_id' => $dispute->id,
                        'type' => $type,
                        'amount' => $amount,
                    ],
                ]);
            }

            return $dispute;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
