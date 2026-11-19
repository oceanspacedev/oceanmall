<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Support;

/**
 * A Midtrans transaction status (Core API GET /v2/{order_id}/status, or a
 * notification body) reduced to what the store acts on:
 *
 *   paid       settlement, or capture with fraud_status accept (or none)
 *   pending    pending / authorize / capture under fraud "challenge": wait
 *   failed     deny / failure / expire: this attempt can no longer be paid
 *   canceled   cancel
 *   refunded   refund / chargeback (after a payment; never re-applied)
 *   not_found  status_code 404: the customer has not picked a method in Snap yet
 *   unknown    anything else: treated like pending, never acted on
 */
final readonly class MidtransTransactionStatus
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';

    public const REFUNDED = 'refunded';

    public const NOT_FOUND = 'not_found';

    public const UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $state,
        public string $transactionStatus,
        public ?string $fraudStatus,
        public ?int $amount,
        public ?string $currency,
        public ?string $orderId,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        $statusCode = trim((string) ($body['status_code'] ?? ''));
        $transactionStatus = strtolower(trim((string) ($body['transaction_status'] ?? '')));
        $fraud = is_string($body['fraud_status'] ?? null) && trim($body['fraud_status']) !== ''
            ? strtolower(trim($body['fraud_status']))
            : null;

        $state = match (true) {
            $statusCode === '404' && $transactionStatus === '' => self::NOT_FOUND,
            $transactionStatus === 'settlement' => $fraud === 'deny' ? self::FAILED : self::PAID,
            $transactionStatus === 'capture' => match ($fraud) {
                null, 'accept' => self::PAID,
                'deny' => self::FAILED,
                default => self::PENDING,
            },
            in_array($transactionStatus, ['pending', 'authorize'], true) => self::PENDING,
            in_array($transactionStatus, ['deny', 'failure', 'expire'], true) => self::FAILED,
            $transactionStatus === 'cancel' => self::CANCELED,
            in_array($transactionStatus, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true) => self::REFUNDED,
            default => self::UNKNOWN,
        };

        $currency = is_string($body['currency'] ?? null) && trim($body['currency']) !== ''
            ? strtoupper(trim($body['currency']))
            : null;
        $orderId = is_string($body['order_id'] ?? null) ? trim($body['order_id']) : null;

        return new self($state, $transactionStatus, $fraud, self::amount($body['gross_amount'] ?? null), $currency, $orderId, $body);
    }

    public function isPaid(): bool
    {
        return $this->state === self::PAID;
    }

    /**
     * The attempt is over without money: the order may be retried / expired.
     */
    public function isClosedUnpaid(): bool
    {
        return in_array($this->state, [self::FAILED, self::CANCELED], true);
    }

    /**
     * gross_amount is a decimal string ("150000.00"). IDR is zero-decimal in
     * this store, so only whole rupiah are accepted; a fractional amount is
     * returned as -1 so it never matches an order total.
     */
    public static function amount(mixed $grossAmount): ?int
    {
        if (is_int($grossAmount)) {
            return $grossAmount;
        }

        if (is_float($grossAmount)) {
            $grossAmount = number_format($grossAmount, 2, '.', '');
        }

        if (! is_string($grossAmount) || preg_match('/^\s*(\d+)(?:\.(\d+))?\s*$/', $grossAmount, $matches) !== 1) {
            return null;
        }

        if (isset($matches[2]) && trim($matches[2], '0') !== '') {
            return -1;
        }

        return (int) $matches[1];
    }
}
