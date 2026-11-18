<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Database;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Support\EnabledCarriers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Shopper\Core\Models\Carrier;

/**
 * The couriers RajaOngkir Shipping Cost / Komerce Delivery quote, as Shopper
 * carriers owned by the 'rajaongkir' driver. Run by the store's
 * CarrierSeeder through CarrierSeeder::extend(), registered by
 * KomerceServiceProvider.
 *
 * Idempotent and polite to other drivers: carriers are matched by
 * (driver=rajaongkir, metadata.courier_code) like KomerceCarrierSync, an
 * existing one keeps what staff set, a slug owned by another driver (e.g. a
 * Biteship 'jne') is never taken over (the new carrier gets 'rajaongkir-jne'),
 * and new carriers start enabled only while a Komerce shipping product is
 * configured. The carrier sync then aligns enabled flags with what the
 * configured products really offer; without a Komerce key it is a no-op.
 */
final class KomerceCarrierSeeder extends Seeder
{
    /** @var array<string, string> slug => name */
    private const COURIERS = [
        'jnt' => 'J&T Express',
        'jne' => 'JNE Express',
        'sicepat' => 'SiCepat Ekspres',
        'ide' => 'IDexpress',
        'anteraja' => 'Anteraja',
        'pos' => 'POS Indonesia',
        'tiki' => 'TIKI',
        'lion' => 'Lion Parcel',
        'ninja' => 'Ninja Xpress',
        'wahana' => 'Wahana Express',
        'rpx' => 'RPX Holding',
        'ncs' => 'Nusantara Card Semesta',
        'sap' => 'SAP Express',
        'gosend' => 'GoSend',
    ];

    public function run(): void
    {
        // Enabled only when the driver can quote them: a keyless store keeps
        // them switched off (Shopper's carrier list, label slide-over).
        $quotable = Komerce::shippingEnabled();

        foreach (self::COURIERS as $code => $name) {
            $owned = Carrier::query()
                ->where('driver', RajaOngkirDriver::CODE)
                ->orderBy('id')
                ->get()
                ->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $code);

            // Existing carriers of this driver keep what staff set (enabled
            // flag, name): seeding again only fills what is missing.
            if ($owned instanceof Carrier) {
                $metadata = is_array($owned->metadata) ? $owned->metadata : [];
                $metadata[EnabledCarriers::COURIER_CODE] ??= $code;
                $owned->forceFill(['metadata' => $metadata])->save();

                continue;
            }

            // Never take over a carrier another driver owns under the same
            // slug (as KomerceCarrierSync::ownedCarrier).
            $slugTaken = Carrier::query()->where('slug', $code)->exists();

            Carrier::query()->create([
                'slug' => $slugTaken ? RajaOngkirDriver::CODE.'-'.$code : $code,
                'name' => $name,
                'description' => "Pengiriman via {$name}",
                'is_enabled' => $quotable,
                'driver' => RajaOngkirDriver::CODE,
                'metadata' => [EnabledCarriers::COURIER_CODE => $code],
            ]);
        }

        // Cleanup legacy generic carrier if present.
        Carrier::query()->where('slug', 'rajaongkir')->delete();

        // Courier codes and enabled flags from the configured products.
        Artisan::call('shipping:sync-carriers');
    }
}
