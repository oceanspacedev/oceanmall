<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every warehouse shipment an indexed owner:
 *
 * - `driver`: the Shopper shipping driver code (Shipping::driver($code)) that
 *   QUOTED the selected rate ('komerce', 'rajaongkir', 'manual', ...). It is
 *   not rewritten when another driver registers the parcel: who fulfils it
 *   is derived (ShippingCapabilities::fulfillerFor(), e.g. rajaongkir
 *   DelegatesFulfillment to komerce), and `external_reference` being filled
 *   says that it was registered.
 * - `external_reference`: the provider's own order number for the shipment.
 *
 * Existing rows are backfilled from the Komerce metadata bucket. Legacy rows
 * whose quoting driver was never recorded get the best known value: the
 * fulfilling driver ('komerce') when registered, 'rajaongkir' for a provider
 * courier rate, 'manual' for a zone option (driverFor()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_shipments', 'driver')) {
                $table->string('driver', 64)->nullable()->after('inventory_id');
            }

            if (! Schema::hasColumn('order_shipments', 'external_reference')) {
                $table->string('external_reference')->nullable()->after('tracking_number');
            }
        });

        Schema::table('order_shipments', function (Blueprint $table): void {
            if (! Schema::hasIndex('order_shipments', ['driver'])) {
                $table->index('driver');
            }

            if (! Schema::hasIndex('order_shipments', ['driver', 'external_reference'])) {
                $table->index(['driver', 'external_reference']);
            }
        });

        DB::table('order_shipments')
            ->orderBy('id')
            ->select(['id', 'service_code', 'metadata', 'driver', 'external_reference'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : null;
                    $metadata = is_array($metadata) ? $metadata : [];

                    $orderNo = data_get($metadata, 'komerce.order_no');
                    $orderNo = is_scalar($orderNo) ? trim((string) $orderNo) : '';

                    // Only fill what is still empty: re-running never
                    // overwrites values written by the application.
                    $changes = [];

                    if (blank($row->driver) && ($driver = $this->driverFor($orderNo, $metadata, $row->service_code)) !== null) {
                        $changes['driver'] = $driver;
                    }

                    if (blank($row->external_reference) && $orderNo !== '') {
                        $changes['external_reference'] = $orderNo;
                    }

                    if ($changes !== []) {
                        DB::table('order_shipments')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table): void {
            $table->dropIndex(['driver', 'external_reference']);
            $table->dropIndex(['driver']);
        });

        Schema::table('order_shipments', function (Blueprint $table): void {
            $table->dropColumn(['driver', 'external_reference']);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function driverFor(string $orderNo, array $metadata, mixed $serviceCode): ?string
    {
        if ($orderNo !== '') {
            return 'komerce';
        }

        $provider = strtolower(trim((string) data_get($metadata, 'rate.provider', '')));

        if (in_array($provider, ['shipping_delivery', 'komship'], true)) {
            return 'komerce';
        }

        $serviceCode = is_scalar($serviceCode) ? trim((string) $serviceCode) : '';

        // Shopper zone rates use the CarrierOption id as the service code.
        if ($serviceCode !== '' && ctype_digit($serviceCode)) {
            return 'manual';
        }

        // Any other quoted rate came from RajaOngkir Shipping Cost.
        if (is_array($metadata['rate'] ?? null) && $metadata['rate'] !== []) {
            return 'rajaongkir';
        }

        return null;
    }
};
