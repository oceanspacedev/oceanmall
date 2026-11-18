<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse origin references move out of the vendor-named
 * `sh_inventories.rajaongkir_origin_id` column into a provider-keyed table:
 * one row per (inventory, shipping driver). A second provider (Biteship area
 * id, Shipper.id origin...) adds rows, not columns.
 *
 * Expand-only: the values are COPIED and the legacy column is kept, so an
 * instance of the previous release (or a job it queued) that still reads or
 * writes sh_inventories.rajaongkir_origin_id keeps working during a rolling /
 * blue-green deploy. The current code never reads the column; it only maps a
 * legacy `rajaongkir_origin_id` model attribute onto the new table
 * (InventoryObserver::LEGACY_ATTRIBUTES) and never writes the column. A later
 * release drops the column (contract step), once no previous-release instance
 * or queued job can be left (README "Upgrading to provider-neutral shipping").
 *
 * down() writes the table's values back into the column (the rollback source
 * of truth) and drops the table.
 */
return new class extends Migration
{
    private const LEGACY_COLUMN = 'rajaongkir_origin_id';

    private const LEGACY_DRIVER = 'rajaongkir';

    public function up(): void
    {
        if (! Schema::hasTable('inventory_shipping_origins')) {
            Schema::create('inventory_shipping_origins', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('inventory_id')->constrained(shopper_table('inventories'))->cascadeOnDelete();
                $table->string('driver', 64);
                $table->string('external_id');
                $table->string('label')->nullable();
                $table->timestamps();

                $table->unique(['inventory_id', 'driver']);
                $table->index('driver');
            });
        }

        $inventories = shopper_table('inventories');

        if (! Schema::hasColumn($inventories, self::LEGACY_COLUMN)) {
            return;
        }

        $now = now();

        DB::table($inventories)
            ->whereNotNull(self::LEGACY_COLUMN)
            ->where(self::LEGACY_COLUMN, '!=', '')
            ->orderBy('id')
            ->get(['id', self::LEGACY_COLUMN])
            ->each(function (object $row) use ($now): void {
                DB::table('inventory_shipping_origins')->updateOrInsert(
                    ['inventory_id' => $row->id, 'driver' => self::LEGACY_DRIVER],
                    [
                        'external_id' => trim((string) $row->{self::LEGACY_COLUMN}),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            });
    }

    public function down(): void
    {
        $inventories = shopper_table('inventories');

        if (! Schema::hasColumn($inventories, self::LEGACY_COLUMN)) {
            Schema::table($inventories, function (Blueprint $table): void {
                $table->string(self::LEGACY_COLUMN)->nullable()->after('is_default');
            });
        }

        if (Schema::hasTable('inventory_shipping_origins')) {
            // The table is what the current code maintained: an origin it
            // cleared must not come back from the column's stale copy.
            DB::table($inventories)->update([self::LEGACY_COLUMN => null]);

            DB::table('inventory_shipping_origins')
                ->where('driver', self::LEGACY_DRIVER)
                ->get(['inventory_id', 'external_id'])
                ->each(static function (object $row) use ($inventories): void {
                    DB::table($inventories)
                        ->where('id', $row->inventory_id)
                        ->update([self::LEGACY_COLUMN => $row->external_id]);
                });

            Schema::drop('inventory_shipping_origins');
        }
    }
};
