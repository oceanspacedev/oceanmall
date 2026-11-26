<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ordersTable = shopper_table('orders');
        $refundsTable = shopper_table('order_refunds');
        $inventoriesTable = shopper_table('inventories');
        $usersTable = (new User)->getTable();

        Schema::table($refundsTable, static function (Blueprint $table) use ($refundsTable): void {
            if (! Schema::hasColumn($refundsTable, 'payment_transaction_id')) {
                $table->unsignedBigInteger('payment_transaction_id')->nullable()->index();
            }
            if (! Schema::hasColumn($refundsTable, 'return_request_id')) {
                $table->unsignedBigInteger('return_request_id')->nullable()->index();
            }
            if (! Schema::hasColumn($refundsTable, 'payment_dispute_id')) {
                $table->unsignedBigInteger('payment_dispute_id')->nullable()->index();
            }
            if (! Schema::hasColumn($refundsTable, 'driver')) {
                $table->string('driver', 64)->nullable();
            }
            if (! Schema::hasColumn($refundsTable, 'method')) {
                $table->string('method', 32)->nullable();
            }
            if (! Schema::hasColumn($refundsTable, 'reference')) {
                $table->string('reference', 128)->nullable();
            }
            if (! Schema::hasColumn($refundsTable, 'bank_details')) {
                $table->json('bank_details')->nullable();
            }
            if (! Schema::hasColumn($refundsTable, 'refunded_by')) {
                $table->unsignedBigInteger('refunded_by')->nullable();
            }
            if (! Schema::hasColumn($refundsTable, 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
        });

        if (! Schema::hasTable('order_return_requests')) {
            Schema::create('order_return_requests', static function (Blueprint $table) use ($ordersTable, $inventoriesTable, $usersTable): void {
                $table->id();
                $table->foreignId('order_id')->constrained($ordersTable)->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained($usersTable)->nullOnDelete();
                $table->string('status', 32)->default('requested')->index();
                $table->string('reason', 64);
                $table->text('description')->nullable();
                $table->json('items');
                $table->json('evidence_paths')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained($usersTable)->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('restocked_at')->nullable();
                $table->foreignId('restock_inventory_id')->nullable()->constrained($inventoriesTable)->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_disputes')) {
            Schema::create('payment_disputes', static function (Blueprint $table) use ($ordersTable, $usersTable): void {
                $table->id();
                $table->foreignId('order_id')->nullable()->constrained($ordersTable)->nullOnDelete();
                $table->string('driver', 64);
                $table->string('type', 48);
                $table->string('status', 32)->default('open')->index();
                $table->string('reference', 128);
                $table->integer('amount')->nullable();
                $table->integer('expected_amount')->nullable();
                $table->string('currency_code', 8)->default('IDR');
                $table->text('reason')->nullable();
                $table->json('provider_payload')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained($usersTable)->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->unsignedBigInteger('order_refund_id')->nullable();
                $table->timestamps();

                $table->unique(['driver', 'reference', 'type']);
            });
        }

        if (! Schema::hasTable('order_payment_proofs')) {
            Schema::create('order_payment_proofs', static function (Blueprint $table) use ($ordersTable, $usersTable): void {
                $table->id();
                $table->foreignId('order_id')->constrained($ordersTable)->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained($usersTable)->nullOnDelete();
                $table->string('disk', 32)->default('local');
                $table->string('path', 255);
                $table->string('original_name', 255);
                $table->string('mime_type', 100);
                $table->unsignedInteger('size');
                $table->string('sender_name', 120)->nullable();
                $table->string('sender_bank', 80)->nullable();
                $table->unsignedInteger('transfer_amount')->nullable();
                $table->timestamp('transferred_at')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->foreignId('verified_by')->nullable()->constrained($usersTable)->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->text('verification_notes')->nullable();
                $table->unsignedBigInteger('payment_transaction_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_notes')) {
            Schema::create('order_notes', static function (Blueprint $table) use ($ordersTable, $usersTable): void {
                $table->id();
                $table->foreignId('order_id')->constrained($ordersTable)->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained($usersTable)->nullOnDelete();
                $table->string('type', 24)->default('internal');
                $table->text('body');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notes');
        Schema::dropIfExists('order_payment_proofs');
        Schema::dropIfExists('payment_disputes');
        Schema::dropIfExists('order_return_requests');

        $refundsTable = shopper_table('order_refunds');
        Schema::table($refundsTable, static function (Blueprint $table) use ($refundsTable): void {
            foreach (['payment_transaction_id', 'return_request_id', 'payment_dispute_id', 'driver', 'method', 'reference', 'bank_details', 'refunded_by', 'processed_at'] as $col) {
                if (Schema::hasColumn($refundsTable, $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
