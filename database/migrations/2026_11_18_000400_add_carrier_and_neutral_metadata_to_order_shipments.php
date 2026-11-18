<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral shipment data generic code reads (App\Shipping\ShipmentMetadata):
 *
 * - `order_shipments.carrier_id`: the Shopper carrier of the selected rate,
 *   recorded when the shipment is created, so the Shopper OrderShipping row
 *   is linked to the right carrier instead of a name heuristic. Existing rows
 *   are backfilled from their courier code, preferring the carrier their own
 *   driver owns (left null when that is ambiguous or unknown).
 * - metadata.tracking_history / fulfillment_error / pickup_requested_at:
 *   copied from the Komerce bucket for rows written before the neutral keys
 *   (the Komerce module keeps them in sync from now on).
 * - carriers.metadata.logo_url values pointing at the Komerce CDN (written on
 *   every carrier by the old carrier sync) move to
 *   metadata.provider_logo_url_backup: the module serves those logos itself
 *   while it is configured, so a store without Komerce never loads them.
 *   down() puts them back.
 */
return new class extends Migration
{
    private const LOGO_BACKUP_KEY = 'provider_logo_url_backup';

    /** @var list<string> */
    private const PROVIDER_LOGO_PREFIXES = [
        'https://storage.googleapis.com/komship-bucket/',
        'https://storage.googleapis.com/komerce/',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('order_shipments', 'carrier_id')) {
            Schema::table('order_shipments', function (Blueprint $table): void {
                $table->unsignedBigInteger('carrier_id')->nullable()->after('driver')->index();
            });
        }

        $this->backfillCarrierIds();
        $this->copyShipmentMetadata();
        $this->dropProviderLogoUrls();
    }

    public function down(): void
    {
        $this->restoreProviderLogoUrls();

        if (Schema::hasColumn('order_shipments', 'carrier_id')) {
            Schema::table('order_shipments', function (Blueprint $table): void {
                $table->dropIndex(['carrier_id']);
            });

            Schema::table('order_shipments', function (Blueprint $table): void {
                $table->dropColumn('carrier_id');
            });
        }
    }

    private function copyShipmentMetadata(): void
    {
        DB::table('order_shipments')
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : null;
                    $bucket = is_array($metadata) ? ($metadata['komerce'] ?? null) : null;

                    if (! is_array($bucket)) {
                        continue;
                    }

                    $copy = $metadata;

                    if (empty($copy['tracking_history']) && is_array($bucket['tracking_history'] ?? null) && $bucket['tracking_history'] !== []) {
                        $copy['tracking_history'] = array_values($bucket['tracking_history']);
                    }

                    if (! isset($copy['fulfillment_error']) && is_string($bucket['fulfillment_error'] ?? null) && trim($bucket['fulfillment_error']) !== '') {
                        $copy['fulfillment_error'] = $bucket['fulfillment_error'];
                    }

                    if (empty($copy['pickup_requested_at']) && is_array($bucket['pickup_response'] ?? null)) {
                        $at = $bucket['pickup_requested_at'] ?? $bucket['pickup_at'] ?? null;
                        $copy['pickup_requested_at'] = is_string($at) && $at !== '' ? $at : now()->toIso8601String();
                    }

                    if ($copy !== $metadata) {
                        DB::table('order_shipments')->where('id', $row->id)->update([
                            'metadata' => json_encode($copy, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
    }

    /**
     * carrier_id of shipments written before the column: the carrier with the
     * shipment's courier code (metadata.courier_code, else slug), the one its
     * own driver owns when several match; null when still ambiguous.
     */
    private function backfillCarrierIds(): void
    {
        $carriers = DB::table(shopper_table('carriers'))->orderBy('id')->get(['id', 'slug', 'driver', 'metadata']);

        if ($carriers->isEmpty()) {
            return;
        }

        $byCode = [];

        foreach ($carriers as $carrier) {
            $metadata = is_string($carrier->metadata) ? json_decode($carrier->metadata, true) : null;
            $code = is_array($metadata) && is_scalar($metadata['courier_code'] ?? null) && trim((string) $metadata['courier_code']) !== ''
                ? (string) $metadata['courier_code']
                : (string) $carrier->slug;
            $code = strtolower(trim($code));

            if ($code !== '') {
                $byCode[$code][] = $carrier;
            }
        }

        DB::table('order_shipments')
            ->whereNull('carrier_id')
            ->select(['id', 'driver', 'carrier_code', 'carrier_name'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($byCode): void {
                foreach ($rows as $row) {
                    $code = strtolower(trim((string) ($row->carrier_code ?: $row->carrier_name)));
                    $matches = $byCode[$code] ?? [];

                    if ($matches === []) {
                        continue;
                    }

                    $owned = array_values(array_filter(
                        $matches,
                        static fn (object $carrier): bool => (string) $carrier->driver === (string) $row->driver,
                    ));
                    $pick = count($owned) === 1 ? $owned[0] : (count($matches) === 1 ? $matches[0] : null);

                    if ($pick !== null) {
                        DB::table('order_shipments')->where('id', $row->id)->update(['carrier_id' => $pick->id]);
                    }
                }
            });
    }

    private function dropProviderLogoUrls(): void
    {
        $carriers = shopper_table('carriers');

        DB::table($carriers)
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($carriers): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : null;
                    $url = is_array($metadata) ? ($metadata['logo_url'] ?? null) : null;

                    if (! is_string($url) || ! $this->isProviderLogo($url)) {
                        continue;
                    }

                    // Kept, not lost: down() restores it.
                    $metadata[self::LOGO_BACKUP_KEY] = $url;
                    unset($metadata['logo_url']);

                    DB::table($carriers)->where('id', $row->id)->update([
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    private function restoreProviderLogoUrls(): void
    {
        $carriers = shopper_table('carriers');

        DB::table($carriers)
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($carriers): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : null;
                    $backup = is_array($metadata) ? ($metadata[self::LOGO_BACKUP_KEY] ?? null) : null;

                    if (! is_string($backup) || $backup === '') {
                        continue;
                    }

                    unset($metadata[self::LOGO_BACKUP_KEY]);
                    $metadata['logo_url'] ??= $backup;

                    DB::table($carriers)->where('id', $row->id)->update([
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    private function isProviderLogo(string $url): bool
    {
        foreach (self::PROVIDER_LOGO_PREFIXES as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }
};
