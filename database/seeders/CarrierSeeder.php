<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Zone;

/**
 * Shopper carriers. The store itself only needs Shopper's manual carrier
 * (zone shipping options such as the flat "Reguler" rate). Shipping provider
 * modules contribute their own carriers with extend(), called from their
 * service provider, so removing a module removes its carriers from fresh
 * seeds (the Komerce module registers its couriers this way).
 */
final class CarrierSeeder extends Seeder
{
    /**
     * Seeders contributed by shipping provider modules.
     *
     * @var list<class-string<Seeder>>
     */
    private static array $providerSeeders = [];

    /**
     * @param  class-string<Seeder>  $seeder
     */
    public static function extend(string $seeder): void
    {
        if (! in_array($seeder, self::$providerSeeders, true)) {
            self::$providerSeeders[] = $seeder;
        }
    }

    /**
     * Forget the registered provider seeders (a fresh app instance without
     * a module, e.g. tests or long-running workers).
     */
    public static function flushState(): void
    {
        self::$providerSeeders = [];
    }

    public function run(): void
    {
        $this->command->warn(PHP_EOL.'Creating carriers...');

        Carrier::query()->updateOrCreate(
            ['slug' => 'manual'],
            [
                'name' => 'Kurir Toko / Pengiriman Lokal',
                'description' => 'Pengiriman manual oleh kurir toko',
                'is_enabled' => true,
                'driver' => 'manual',
            ],
        );

        foreach (self::$providerSeeders as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }

        // Attach enabled carriers to existing zones (ZoneSeeder does the same
        // for zones it creates later).
        $enabledIds = Carrier::query()->where('is_enabled', true)->pluck('id');
        foreach (Zone::all() as $zone) {
            $zone->carriers()->syncWithoutDetaching($enabledIds);
        }

        $this->command->info('Carriers created successfully.');
    }
}
