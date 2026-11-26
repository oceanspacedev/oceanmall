<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Order;

/**
 * Generates an atomic, collision-safe monthly sequential invoice number
 * (format: `{PREFIX}-{YYYYMM}-{00001}`) using `invoice_sequences`.
 */
final class GenerateInvoiceNumber
{
    public function handle(Order $order): string
    {
        if (is_string($order->invoice_number) && trim($order->invoice_number) !== '') {
            return trim($order->invoice_number);
        }

        $prefix = 'INV';

        try {
            $configured = shopper_setting('invoice_prefix', false);
            if (is_string($configured) && trim($configured) !== '') {
                $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9\-]/', '', trim($configured))) ?: 'INV';
            }
        } catch (\Throwable) {
            // Fall back to default prefix before settings exist.
        }

        $rawDate = $order->paid_at ?? $order->created_at;
        $periodKey = ($rawDate !== null ? Carbon::parse($rawDate) : now())->format('Ym');

        $next = DB::transaction(static function () use ($periodKey): int {
            $row = DB::table('invoice_sequences')
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('invoice_sequences')->insert([
                    'period_key' => $periodKey,
                    'last_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            $number = (int) $row->last_number + 1;
            DB::table('invoice_sequences')
                ->where('period_key', $periodKey)
                ->update([
                    'last_number' => $number,
                    'updated_at' => now(),
                ]);

            return $number;
        });

        return sprintf('%s-%s-%05d', $prefix, $periodKey, $next);
    }
}
