<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Support;

use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\Xendit;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * The order's current Xendit invoice, kept under `metadata.xendit` on the
 * order (Shopper's Order does not cast metadata, so it is stored as JSON like
 * the Komerce bucket):
 *
 *   invoice_id    current invoice (the one a webhook may settle or expire the order with)
 *   external_id   order number, plus "-{attempt}" for retries (Xendit's external_id)
 *   invoice_url   hosted checkout page
 *   expiry_date   ISO-8601 from Xendit
 *   status        last known Xendit status (PENDING / PAID / SETTLED / EXPIRED)
 *   channel_code  the Invoice payment_methods entry the page was restricted to
 *   attempt       1 for the first invoice, +1 per retry
 *   mode          test / live: the key mode the invoice was created with
 *
 * Every invoice is also a Shopper Initiate PaymentTransaction (driver
 * `xendit`, reference = invoice id), which is how webhooks find the order.
 */
final class XenditOrderPayment
{
    public const PAID_STATUSES = ['PAID', 'SETTLED'];

    /**
     * @return array<string, mixed>
     */
    public static function read(Order $order): array
    {
        $bucket = self::metadata($order)['xendit'] ?? null;

        return is_array($bucket) ? $bucket : [];
    }

    /**
     * Merge $values into metadata.xendit (re-reading the row first so other
     * writers' keys are kept).
     *
     * @param  array<string, mixed>  $values
     */
    public static function write(Order $order, array $values): void
    {
        $order->refresh();
        $metadata = self::metadata($order);
        $bucket = is_array($metadata['xendit'] ?? null) ? $metadata['xendit'] : [];
        $metadata['xendit'] = [...$bucket, ...$values];

        $order->forceFill(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)])->save();
    }

    public static function currentInvoiceId(Order $order): ?string
    {
        $id = self::read($order)['invoice_id'] ?? null;

        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }

        $reference = self::latestTransaction($order)?->reference;

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }

    /**
     * The mode (test / live) the order's current invoice was created in, or
     * null for invoices recorded before the mode was kept.
     */
    public static function mode(Order $order): ?string
    {
        $mode = self::read($order)['mode'] ?? null;

        return in_array($mode, [Xendit::MODE_TEST, Xendit::MODE_LIVE], true) ? $mode : null;
    }

    /**
     * Whether the configured key can see the order's current invoice. Test
     * and live invoices live in separate Xendit "accounts": after the key
     * was swapped (test -> live or back) the API answers 404 for the old
     * invoice, so it must not be looked up, expired or reopened.
     */
    public static function modeMatches(Order $order): bool
    {
        $mode = self::mode($order);

        return $mode === null || $mode === Xendit::mode();
    }

    public static function latestTransaction(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', XenditDriver::CODE)
            ->where('type', TransactionType::Initiate)
            ->whereNotNull('reference')
            ->latest('id')
            ->first();
    }

    public static function attempt(Order $order): int
    {
        $attempt = self::read($order)['attempt'] ?? null;

        return is_numeric($attempt) ? max(1, (int) $attempt) : 1;
    }

    /**
     * Xendit external_id: the order number for the first invoice, then
     * "{number}-{attempt}" so every retry is a distinct invoice.
     */
    public static function externalId(Order $order, int $attempt): string
    {
        return $attempt <= 1 ? (string) $order->number : $order->number.'-'.$attempt;
    }

    /**
     * Whether an invoice's external_id was issued for this order.
     */
    public static function belongsTo(Order $order, mixed $externalId): bool
    {
        if (! is_string($externalId) || $externalId === '') {
            return false;
        }

        $number = (string) $order->number;

        return $externalId === $number
            || preg_match('/^'.preg_quote($number, '/').'-\d+$/', $externalId) === 1;
    }

    public static function expiresAt(Order $order): ?CarbonInterface
    {
        $expiry = self::read($order)['expiry_date'] ?? null;

        if (! is_string($expiry) || trim($expiry) === '') {
            $expiry = data_get(self::latestTransaction($order)?->metadata, 'expiry_date');
        }

        return self::parseDate($expiry);
    }

    public static function parseDate(mixed $value): ?CarbonInterface
    {
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
     * @param  array<string, mixed>  $invoice
     */
    public static function status(array $invoice): string
    {
        return strtoupper(trim((string) ($invoice['status'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public static function isPaid(array $invoice): bool
    {
        return in_array(self::status($invoice), self::PAID_STATUSES, true);
    }

    /**
     * The amount Xendit reports as paid (paid_amount, else the invoice
     * amount), in integer rupiah. IDR is zero-decimal: never scaled.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function paidAmount(array $invoice): ?int
    {
        foreach (['paid_amount', 'amount'] as $key) {
            if (is_numeric($invoice[$key] ?? null)) {
                return (int) round((float) $invoice[$key]);
            }
        }

        return null;
    }

    /**
     * Shopper's neutral payment status for a Xendit invoice status.
     */
    public static function neutralStatus(string $xenditStatus): string
    {
        return match (strtoupper($xenditStatus)) {
            'PAID', 'SETTLED' => 'captured',
            'EXPIRED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(Order $order): array
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
