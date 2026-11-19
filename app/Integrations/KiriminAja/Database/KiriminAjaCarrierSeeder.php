<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Database;

use App\Integrations\KiriminAja\KiriminAja;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Integrations\KiriminAja\Support\KiriminAjaCouriers;
use App\Support\EnabledCarriers;
use Illuminate\Database\Seeder;
use Shopper\Core\Models\Carrier;

/**
 * The default KiriminAja couriers as Shopper carriers owned by the
 * `kiriminaja` driver, contributed to the store's CarrierSeeder through
 * CarrierSeeder::extend() (KiriminAjaServiceProvider).
 *
 * Idempotent and polite to other drivers: matched by (driver=kiriminaja,
 * metadata.courier_code); an existing carrier keeps what staff set; a slug
 * owned by another driver (e.g. RajaOngkir's "jne") is never taken over (the
 * new carrier gets "kiriminaja-jne"). New carriers start enabled only while
 * KiriminAja is configured, so a keyless store never offers them.
 * `shipping:sync-carriers` then aligns the list with the account.
 */
final class KiriminAjaCarrierSeeder extends Seeder
{
    public function run(): void
    {
        $enabled = KiriminAja::configured();

        foreach (KiriminAjaCouriers::DEFAULTS as $code => $name) {
            $owned = Carrier::query()
                ->where('driver', KiriminAjaDriver::CODE)
                ->orderBy('id')
                ->get()
                ->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $code);

            if ($owned instanceof Carrier) {
                $metadata = is_array($owned->metadata) ? $owned->metadata : [];
                $metadata[EnabledCarriers::COURIER_CODE] ??= $code;
                $owned->forceFill(['metadata' => $metadata])->save();

                continue;
            }

            Carrier::query()->create([
                'slug' => Carrier::query()->where('slug', $code)->exists() ? KiriminAjaDriver::CODE.'-'.$code : $code,
                'name' => $name,
                'description' => "Pengiriman {$name} via KiriminAja",
                'is_enabled' => $enabled,
                'driver' => KiriminAjaDriver::CODE,
                'metadata' => [EnabledCarriers::COURIER_CODE => $code],
            ]);
        }
    }
}
