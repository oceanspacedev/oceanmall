<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komerce's official payment catalog (GET /api/v1/user/methods) identifies
 * BRI virtual accounts with bank_code "BRI". The seed used "BRIVA", so the
 * method never matched the catalog and was silently hidden at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = shopper_table('payment_methods');

        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)
            ->where('driver', 'komerce')
            ->where('metadata', 'like', '%BRIVA%')
            ->get(['id', 'metadata']);

        foreach ($rows as $row) {
            $meta = json_decode((string) $row->metadata, true);

            if (! is_array($meta) || strtoupper((string) ($meta['channel_code'] ?? '')) !== 'BRIVA') {
                continue;
            }

            $meta['channel_code'] = 'BRI';

            DB::table($table)->where('id', $row->id)->update(['metadata' => json_encode($meta)]);
        }
    }

    public function down(): void
    {
        // Data repair; nothing to restore.
    }
};
