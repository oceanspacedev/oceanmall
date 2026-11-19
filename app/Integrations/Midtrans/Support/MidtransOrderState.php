<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Support;

use App\Integrations\Midtrans\Midtrans;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Shopper\Core\Models\Order;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * Where the module keeps its per-order state and how a Shopper order maps to
 * Midtrans order_ids.
 *
 * Midtrans requires transaction_details.order_id to be unique per merchant
 * for every Snap transaction, so each payment attempt gets its own id:
 *
 *     attempt 1  ->  {order number}          e.g. ORD-20261119-0001
 *     attempt n  ->  {order number}~{n}      e.g. ORD-20261119-0001~2
 *
 * (Midtrans allows letters, digits and - _ ~ . ; anything else in the order
 * number becomes "-", and "~" is reserved for the attempt suffix.) Each
 * attempt is a Shopper PaymentTransaction (driver midtrans, reference = the
 * Midtrans order_id), which is how a notification is mapped back to its
 * order; the suffix rule is only a fallback.
 *
 * The current attempt's Snap token / redirect URL / expiry live in the order
 * metadata bucket `midtrans` (never the server key or raw card data).
 */
final class MidtransOrderState
{
    public const BUCKET = 'midtrans';

    /** Midtrans' documented max length of transaction_details.order_id. */
    private const MAX_ORDER_ID = 50;

    /**
     * @return array<string, mixed>
     */
    public static function read(Order $order): array
    {
        $bucket = self::metadata($order)[self::BUCKET] ?? null;

        return is_array($bucket) ? $bucket : [];
    }

    /**
     * Merge values into the order's `midtrans` bucket (re-read first, so a
     * concurrent write to another bucket is kept).
     *
     * @param  array<string, mixed>  $values
     */
    public static function write(Order $order, array $values): void
    {
        $order->refresh();
        $metadata = self::metadata($order);
        $bucket = is_array($metadata[self::BUCKET] ?? null) ? $metadata[self::BUCKET] : [];
        $metadata[self::BUCKET] = [...$bucket, ...$values];

        $order->forceFill(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)])->save();
    }

    public static function expiresAt(Order $order): ?CarbonInterface
    {
        $value = self::read($order)['expires_at'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Midtrans order_id of the current attempt, or null when none was
     * created.
     */
    public static function currentOrderId(Order $order): ?string
    {
        $fromMetadata = self::read($order)['order_id'] ?? null;

        if (is_string($fromMetadata) && trim($fromMetadata) !== '') {
            return trim($fromMetadata);
        }

        $reference = self::transactions($order)->first()?->reference;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    public static function orderIdFor(Order $order, int $attempt): string
    {
        $base = preg_replace('/[^A-Za-z0-9\-_.]/', '-', (string) $order->number) ?? '';
        $base = $base !== '' ? $base : 'order-'.$order->id;
        $suffix = $attempt > 1 ? '~'.$attempt : '';

        return substr($base, 0, self::MAX_ORDER_ID - strlen($suffix)).$suffix;
    }

    /**
     * Next attempt number: one past every attempt already recorded.
     */
    public static function nextAttempt(Order $order): int
    {
        $highest = (int) (self::read($order)['attempt'] ?? 0);

        foreach (self::transactions($order) as $transaction) {
            $highest = max($highest, self::attemptOf((string) $transaction->reference));
        }

        return $highest + 1;
    }

    public static function attemptOf(string $midtransOrderId): int
    {
        return preg_match('/~(\d+)$/', $midtransOrderId, $matches) === 1 ? (int) $matches[1] : 1;
    }

    /**
     * Order number a Midtrans order_id was derived from (fallback lookup).
     */
    public static function orderNumberOf(string $midtransOrderId): string
    {
        return (string) preg_replace('/~\d+$/', '', $midtransOrderId);
    }

    /**
     * The order's Midtrans attempts, newest first.
     *
     * @return Collection<int, PaymentTransaction>
     */
    public static function transactions(Order $order): Collection
    {
        return PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', Midtrans::CODE)
            ->whereNotNull('reference')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(Order $order): array
    {
        $metadata = $order->getAttribute('metadata');

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
