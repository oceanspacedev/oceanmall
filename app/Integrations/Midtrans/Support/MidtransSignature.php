<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Support;

/**
 * Midtrans HTTP notification signature:
 *
 *     signature_key = SHA512(order_id + status_code + gross_amount + ServerKey)
 *
 * gross_amount is the string exactly as sent (e.g. "150000.00"). Compared in
 * constant time.
 */
final class MidtransSignature
{
    public static function expected(string $orderId, string $statusCode, string $grossAmount, string $serverKey): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
    }

    public static function isValid(string $orderId, string $statusCode, string $grossAmount, string $serverKey, string $signature): bool
    {
        if ($serverKey === '' || $orderId === '' || $statusCode === '' || $grossAmount === '' || $signature === '') {
            return false;
        }

        return hash_equals(self::expected($orderId, $statusCode, $grossAmount, $serverKey), strtolower(trim($signature)));
    }
}
