<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ordersTable = shopper_table('orders');
        $orderItemsTable = shopper_table('order_items');
        $usersTable = (new User)->getTable();

        Schema::table($ordersTable, static function (Blueprint $table) use ($ordersTable): void {
            if (! Schema::hasColumn($ordersTable, 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->index();
            }
            if (! Schema::hasColumn($ordersTable, 'invoice_number')) {
                $table->string('invoice_number', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn($ordersTable, 'gateway_fee')) {
                $table->unsignedInteger('gateway_fee')->default(0);
            }
            if (! Schema::hasColumn($ordersTable, 'net_settlement')) {
                $table->integer('net_settlement')->nullable();
            }
            if (! Schema::hasColumn($ordersTable, 'actual_shipping_cost')) {
                $table->unsignedInteger('actual_shipping_cost')->nullable();
            }
            if (! Schema::hasColumn($ordersTable, 'cod_remitted_at')) {
                $table->timestamp('cod_remitted_at')->nullable();
            }
            if (! Schema::hasColumn($ordersTable, 'cod_remitted_by')) {
                $table->unsignedBigInteger('cod_remitted_by')->nullable();
            }
        });

        DB::table($ordersTable)
            ->where('payment_status', 'paid')
            ->whereNull('paid_at')
            ->update(['paid_at' => DB::raw('COALESCE(updated_at, created_at)')]);

        Schema::table($orderItemsTable, static function (Blueprint $table) use ($orderItemsTable): void {
            if (! Schema::hasColumn($orderItemsTable, 'unit_cost_amount')) {
                $table->unsignedInteger('unit_cost_amount')->nullable()->after('unit_price_amount');
            }
        });

        Schema::table($usersTable, static function (Blueprint $table) use ($usersTable): void {
            if (! Schema::hasColumn($usersTable, 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable();
            }
        });

        if (! Schema::hasTable('invoice_sequences')) {
            Schema::create('invoice_sequences', static function (Blueprint $table): void {
                $table->string('period_key', 16)->primary();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('discount_redemptions')) {
            Schema::create('discount_redemptions', static function (Blueprint $table) use ($ordersTable): void {
                $table->id();
                $table->unsignedBigInteger('discount_id')->index();
                $table->foreignId('order_id')->nullable()->constrained($ordersTable)->nullOnDelete();
                $table->string('email_hash', 64)->index();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->index(['discount_id', 'email_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('invoice_sequences');

        $usersTable = (new User)->getTable();
        $orderItemsTable = shopper_table('order_items');
        $ordersTable = shopper_table('orders');

        if (Schema::hasColumn($usersTable, 'suspended_at')) {
            Schema::table($usersTable, static fn (Blueprint $table) => $table->dropColumn('suspended_at'));
        }

        if (Schema::hasColumn($orderItemsTable, 'unit_cost_amount')) {
            Schema::table($orderItemsTable, static fn (Blueprint $table) => $table->dropColumn('unit_cost_amount'));
        }

        Schema::table($ordersTable, static function (Blueprint $table) use ($ordersTable): void {
            foreach (['paid_at', 'invoice_number', 'gateway_fee', 'net_settlement', 'actual_shipping_cost', 'cod_remitted_at', 'cod_remitted_by'] as $col) {
                if (Schema::hasColumn($ordersTable, $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
