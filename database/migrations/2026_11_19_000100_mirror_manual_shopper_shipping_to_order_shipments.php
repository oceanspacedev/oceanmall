<?php

declare(strict_types=1);

use App\Actions\Shipping\MirrorManualShopperShipping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Shopper\Core\Models\OrderShipping;

/**
 * Data only (no schema change): labels and shipment events staff recorded in
 * cpanel with Shopper's own flow BEFORE App\Observers\ShopperOrderShippingObserver
 * existed never reached the warehouse shipment, so those customers still see
 * "Label menunggu" and cannot confirm receipt. Mirror them once with the same
 * rules as the observer (AWB only onto a shipment without one, status forward
 * only, provider-fulfilled shipments untouched). Re-running is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sh_order_shipping') || ! Schema::hasTable('order_shipments')) {
            return;
        }

        $mirror = resolve(MirrorManualShopperShipping::class);

        OrderShipping::query()
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->orderBy('id')
            ->each(static fn (OrderShipping $row) => $mirror->handle($row));
    }

    public function down(): void
    {
        // Nothing to undo: the copied AWB / status are what staff recorded.
    }
};
