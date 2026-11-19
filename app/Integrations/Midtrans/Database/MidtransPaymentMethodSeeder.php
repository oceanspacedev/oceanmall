<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Database;

use App\Integrations\Midtrans\Midtrans;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\Seeder;

/**
 * Midtrans payment methods, one per Snap channel. Run by the store's
 * PaymentMethodSeeder through PaymentMethodSeeder::extend(), registered by
 * MidtransServiceProvider.
 *
 * metadata.channel_code is the Snap `enabled_payments` code, so Snap opens
 * directly on the method the customer picked at checkout. A method created
 * here is enabled only while Midtrans is configured (server key + consistent
 * host); cards stay off until staff enable them (3DS / fraud review are
 * account-level settings). An existing row keeps staff's enabled flag
 * (PaymentMethodSeeder::seedMethod).
 */
final class MidtransPaymentMethodSeeder extends Seeder
{
    /**
     * slug => [title, description, payment_type, Snap enabled_payments code, enabled with a key]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     */
    public const METHODS = [
        'midtrans-qris' => ['QRIS', 'Bayar via QRIS (semua e-wallet & m-banking) melalui Midtrans.', 'qris', 'other_qris', true],
        'midtrans-gopay' => ['GoPay', 'Bayar via GoPay melalui Midtrans.', 'ewallet', 'gopay', true],
        'midtrans-shopeepay' => ['ShopeePay', 'Bayar via ShopeePay melalui Midtrans.', 'ewallet', 'shopeepay', true],
        'midtrans-bca-va' => ['BCA Virtual Account', 'Bayar via BCA Virtual Account melalui Midtrans.', 'bank_transfer', 'bca_va', true],
        'midtrans-bni-va' => ['BNI Virtual Account', 'Bayar via BNI Virtual Account melalui Midtrans.', 'bank_transfer', 'bni_va', true],
        'midtrans-bri-va' => ['BRI Virtual Account', 'Bayar via BRI Virtual Account melalui Midtrans.', 'bank_transfer', 'bri_va', true],
        'midtrans-mandiri-bill' => ['Mandiri Bill Payment', 'Bayar via Mandiri Bill Payment melalui Midtrans.', 'bank_transfer', 'echannel', true],
        'midtrans-permata-va' => ['Permata Virtual Account', 'Bayar via Permata Virtual Account melalui Midtrans.', 'bank_transfer', 'permata_va', true],
        'midtrans-other-va' => ['Virtual Account Bank Lain', 'Bayar via Virtual Account bank lain (ATM Bersama / Prima / Alto) melalui Midtrans.', 'bank_transfer', 'other_va', true],
        'midtrans-credit-card' => ['Kartu Kredit / Debit', 'Bayar dengan kartu kredit/debit (3DS) melalui Midtrans.', 'credit_card', 'credit_card', false],
    ];

    public function run(): void
    {
        $configured = Midtrans::configured();

        foreach (self::METHODS as $slug => [$title, $description, $paymentType, $channel, $enabledWithKey]) {
            PaymentMethodSeeder::seedMethod($slug, [
                'title' => $title,
                'description' => $description,
                'driver' => Midtrans::CODE,
                'metadata' => json_encode([
                    'payment_type' => $paymentType,
                    'channel_code' => $channel,
                ], JSON_THROW_ON_ERROR),
            ], enabled: $configured && $enabledWithKey);
        }
    }
}
