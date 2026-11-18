<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Database;

use App\Integrations\Komerce\Komerce;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\Seeder;
use Shopper\Core\Models\PaymentMethod;

/**
 * Komerce payment methods (VA per bank + QRIS). Run by the store's
 * PaymentMethodSeeder through PaymentMethodSeeder::extend(), registered by
 * KomerceServiceProvider. A method created here is enabled only while the
 * Komerce Payment product (or QRISLY, for QRIS) is configured; an existing
 * method keeps staff's enabled flag (PaymentMethodSeeder::seedMethod), so a
 * re-seed during a transient plan-cache miss never switches checkout off.
 */
final class KomercePaymentMethodSeeder extends Seeder
{
    /** @var array<string, string> slug => bank channel_code (Komerce catalog bank_code) */
    private const VIRTUAL_ACCOUNTS = [
        'komerce-va-bca' => 'BCA',
        'komerce-va-bri' => 'BRI',
        'komerce-va-mandiri' => 'MANDIRI',
        'komerce-va-bni' => 'BNI',
        'komerce-va-permata' => 'PERMATA',
    ];

    private const BANK_TITLES = [
        'BCA' => 'BCA',
        'BRI' => 'BRI',
        'MANDIRI' => 'Mandiri',
        'BNI' => 'BNI',
        'PERMATA' => 'Permata',
    ];

    public function run(): void
    {
        $payment = Komerce::paymentEnabled();
        $qris = $payment || Komerce::qrislyEnabled();

        foreach (self::VIRTUAL_ACCOUNTS as $slug => $channel) {
            $bank = self::BANK_TITLES[$channel];

            PaymentMethodSeeder::seedMethod($slug, [
                'title' => "{$bank} Virtual Account",
                'description' => "Bayar via {$bank} Virtual Account (Komerce).",
                'driver' => 'komerce',
                'metadata' => json_encode([
                    'payment_type' => 'bank_transfer',
                    'channel_code' => $channel,
                ], JSON_THROW_ON_ERROR),
            ], enabled: $payment);
        }

        PaymentMethodSeeder::seedMethod('komerce-qris', [
            'title' => 'QRIS',
            'description' => 'Bayar via QRIS (GoPay, OVO, Dana, ShopeePay, m-banking).',
            'driver' => 'komerce',
            'metadata' => json_encode([
                'payment_type' => 'qris',
            ], JSON_THROW_ON_ERROR),
        ], enabled: $qris);

        // Legacy combined slug: keep row but disabled so ZoneSeeder upgrades cleanly.
        PaymentMethod::query()->updateOrCreate(
            ['slug' => 'komerce-va-qris'],
            [
                'title' => 'Virtual Account / QRIS (Komerce)',
                'description' => 'Legacy combined method — use komerce-va-* or komerce-qris.',
                'is_enabled' => false,
                'driver' => 'komerce',
                'metadata' => json_encode([
                    'payment_type' => 'bank_transfer',
                    'channel_code' => 'BCA',
                ], JSON_THROW_ON_ERROR),
            ],
        );
    }
}
