<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Database;

use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\Support\XenditChannels;
use App\Integrations\Xendit\Xendit;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\Seeder;

/**
 * Xendit payment methods, one per Invoice channel (QRIS, VA per bank,
 * e-wallets, card), each with `metadata.channel_code` = the Invoice
 * `payment_methods` value. Run by the store's PaymentMethodSeeder through
 * PaymentMethodSeeder::extend(), registered by XenditServiceProvider.
 *
 * Same rule as the Komerce seeder: a method created here is enabled only
 * while a usable Xendit key is configured (cards never: they need card
 * acceptance on the account), and an existing row keeps staff's enabled
 * flag (PaymentMethodSeeder::seedMethod).
 */
final class XenditPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $configured = Xendit::configured();

        foreach (XenditChannels::METHODS as $slug => $method) {
            PaymentMethodSeeder::seedMethod($slug, [
                'title' => $method['title'],
                'description' => $method['description'],
                'driver' => XenditDriver::CODE,
                'metadata' => json_encode([
                    'payment_type' => $method['payment_type'],
                    'channel_code' => $method['channel_code'],
                ], JSON_THROW_ON_ERROR),
            ], enabled: $configured && $method['default_enabled']);
        }
    }
}
