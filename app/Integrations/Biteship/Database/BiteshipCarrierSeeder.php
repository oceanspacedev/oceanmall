<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Database;

use App\Integrations\Biteship\Biteship;
use App\Integrations\Biteship\Support\BiteshipCouriers;
use App\Support\EnabledCarriers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Shopper\Core\Models\Carrier;

/**
 * A default set of Biteship couriers (BiteshipCouriers::DEFAULTS) as Shopper
 * carriers owned by the 'biteship' driver. Run by the store's CarrierSeeder
 * through CarrierSeeder::extend(), registered by BiteshipServiceProvider.
 *
 * Same rules as the Komerce seeder: idempotent, matched by (driver=biteship,
 * metadata.courier_code); an existing carrier keeps what staff set; a slug
 * another driver owns (e.g. Komerce's 'jne') is never taken over (the new
 * carrier gets 'biteship-jne'); new carriers start enabled only while
 * Biteship is configured. With a key, `shipping:sync-carriers --driver=biteship`
 * then aligns the list with what Biteship offers; keyless it does nothing.
 */
final class BiteshipCarrierSeeder extends Seeder
{
    public function run(): void
    {
        $configured = Biteship::configured();

        foreach (BiteshipCouriers::DEFAULTS as $code => $name) {
            $owned = Carrier::query()
                ->where('driver', Biteship::CODE)
                ->orderBy('id')
                ->get()
                ->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $code);

            if ($owned instanceof Carrier) {
                $metadata = is_array($owned->metadata) ? $owned->metadata : [];
                $metadata[EnabledCarriers::COURIER_CODE] ??= $code;
                $owned->forceFill(['metadata' => $metadata])->save();

                continue;
            }

            $slugTaken = Carrier::query()->where('slug', $code)->exists();

            Carrier::query()->create([
                'slug' => $slugTaken ? Biteship::CODE.'-'.$code : $code,
                'name' => $name,
                'description' => "Pengiriman via {$name}",
                'is_enabled' => $configured,
                'driver' => Biteship::CODE,
                'metadata' => [EnabledCarriers::COURIER_CODE => $code],
            ]);
        }

        if ($configured) {
            Artisan::call('shipping:sync-carriers', ['--driver' => Biteship::CODE]);
        }
    }
}
