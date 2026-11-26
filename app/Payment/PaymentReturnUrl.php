<?php

declare(strict_types=1);

namespace App\Payment;

use Shopper\Core\Models\Order;

/**
 * Request-scoped in-memory store for custom payment return URLs (e.g. mobile
 * deep-link return trampoline). Never written onto Eloquent model attributes,
 * so Shopper / payment drivers saving `$order` never treat it as a database column.
 */
final class PaymentReturnUrl
{
    /** @var array<int|string, string> */
    private static array $urls = [];

    public static function remember(Order $order, ?string $url): void
    {
        $trimmed = is_string($url) ? trim($url) : '';
        $key = $order->getKey() ?? spl_object_id($order);

        if ($trimmed === '') {
            unset(self::$urls[$key]);

            return;
        }

        self::$urls[$key] = $trimmed;
    }

    public static function get(Order $order): ?string
    {
        $key = $order->getKey();
        if ($key !== null && isset(self::$urls[$key])) {
            return self::$urls[$key];
        }

        $objectKey = spl_object_id($order);

        return self::$urls[$objectKey] ?? null;
    }

    public static function forget(Order $order): void
    {
        $key = $order->getKey();
        if ($key !== null) {
            unset(self::$urls[$key]);
        }

        unset(self::$urls[spl_object_id($order)]);
    }
}
