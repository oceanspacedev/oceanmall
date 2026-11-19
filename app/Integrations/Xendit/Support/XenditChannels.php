<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Support;

/**
 * The Xendit Invoice channels this store offers as separate Shopper payment
 * methods (one per channel, like the Komerce list). `channel_code` is the
 * value the Invoice API accepts in `payment_methods`, so the hosted page
 * opens on exactly the channel the customer picked at checkout.
 *
 * payment_type follows the store's neutral vocabulary (bank_transfer / qris /
 * ewallet / card); the logo key is the local file under
 * public/images/payments when one exists.
 */
final class XenditChannels
{
    /**
     * @var array<string, array{channel_code: string, payment_type: string, title: string, description: string, logo: string, default_enabled: bool}>
     */
    public const METHODS = [
        'xendit-qris' => [
            'channel_code' => 'QRIS',
            'payment_type' => 'qris',
            'title' => 'QRIS',
            'description' => 'Bayar via QRIS (GoPay, OVO, DANA, ShopeePay, m-banking) lewat Xendit.',
            'logo' => 'qris',
            'default_enabled' => true,
        ],
        'xendit-va-bca' => [
            'channel_code' => 'BCA',
            'payment_type' => 'bank_transfer',
            'title' => 'BCA Virtual Account',
            'description' => 'Bayar via BCA Virtual Account (Xendit).',
            'logo' => 'bca',
            'default_enabled' => true,
        ],
        'xendit-va-bni' => [
            'channel_code' => 'BNI',
            'payment_type' => 'bank_transfer',
            'title' => 'BNI Virtual Account',
            'description' => 'Bayar via BNI Virtual Account (Xendit).',
            'logo' => 'bni',
            'default_enabled' => true,
        ],
        'xendit-va-bri' => [
            'channel_code' => 'BRI',
            'payment_type' => 'bank_transfer',
            'title' => 'BRI Virtual Account',
            'description' => 'Bayar via BRI Virtual Account (Xendit).',
            'logo' => 'bri',
            'default_enabled' => true,
        ],
        'xendit-va-mandiri' => [
            'channel_code' => 'MANDIRI',
            'payment_type' => 'bank_transfer',
            'title' => 'Mandiri Virtual Account',
            'description' => 'Bayar via Mandiri Virtual Account (Xendit).',
            'logo' => 'mandiri',
            'default_enabled' => true,
        ],
        'xendit-va-permata' => [
            'channel_code' => 'PERMATA',
            'payment_type' => 'bank_transfer',
            'title' => 'Permata Virtual Account',
            'description' => 'Bayar via Permata Virtual Account (Xendit).',
            'logo' => 'permata',
            'default_enabled' => true,
        ],
        'xendit-ovo' => [
            'channel_code' => 'OVO',
            'payment_type' => 'ewallet',
            'title' => 'OVO',
            'description' => 'Bayar dengan OVO (Xendit).',
            'logo' => 'ovo',
            'default_enabled' => true,
        ],
        'xendit-dana' => [
            'channel_code' => 'DANA',
            'payment_type' => 'ewallet',
            'title' => 'DANA',
            'description' => 'Bayar dengan DANA (Xendit).',
            'logo' => 'dana',
            'default_enabled' => true,
        ],
        'xendit-shopeepay' => [
            'channel_code' => 'SHOPEEPAY',
            'payment_type' => 'ewallet',
            'title' => 'ShopeePay',
            'description' => 'Bayar dengan ShopeePay (Xendit).',
            'logo' => 'shopeepay',
            'default_enabled' => true,
        ],
        'xendit-linkaja' => [
            'channel_code' => 'LINKAJA',
            'payment_type' => 'ewallet',
            'title' => 'LinkAja',
            'description' => 'Bayar dengan LinkAja (Xendit).',
            'logo' => 'linkaja',
            'default_enabled' => true,
        ],
        // Cards need card acceptance activated on the Xendit account: off by default.
        'xendit-credit-card' => [
            'channel_code' => 'CREDIT_CARD',
            'payment_type' => 'card',
            'title' => 'Kartu Kredit / Debit',
            'description' => 'Bayar dengan kartu kredit/debit (Visa, Mastercard, JCB) lewat Xendit.',
            'logo' => 'credit-card',
            'default_enabled' => false,
        ],
    ];

    /**
     * The local logo key for a Xendit method slug (e.g. xendit-va-bca => bca).
     */
    public static function logoKey(string $slug): ?string
    {
        return self::METHODS[strtolower(trim($slug))]['logo'] ?? null;
    }

    /**
     * A channel code in the Invoice `payment_methods` vocabulary, or null.
     */
    public static function normalize(mixed $channelCode): ?string
    {
        if (! is_string($channelCode)) {
            return null;
        }

        $code = strtoupper(trim($channelCode));

        return preg_match('/^[A-Z0-9_]{2,40}$/', $code) === 1 ? $code : null;
    }
}
