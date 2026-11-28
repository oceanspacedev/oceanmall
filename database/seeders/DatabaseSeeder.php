<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Shopper\Core\Database\Seeders\ChannelSeeder;
use Shopper\Core\Database\Seeders\CountriesTableSeeder;
use Shopper\Core\Database\Seeders\CurrenciesTableSeeder;
use Shopper\Core\Database\Seeders\ShopperSeeder;
use Shopper\Core\Models\Country;
use Shopper\Database\Seeders\AuthTableSeeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * The Minimo, Rapa and Oraimo catalog, seeded everywhere.
     *
     * @var list<class-string<Seeder>>
     */
    private const array CATALOG = [
        BrandSeeder::class,
        CategorySeeder::class,
        CollectionSeeder::class,
        AttributeSeeder::class,
        ProductSeeder::class,
    ];

    public function run(): void
    {
        // Shopper's roles/permissions seeder is not idempotent either.
        if (Role::query()->where('name', config('shopper.admin.roles.admin'))->doesntExist()) {
            $this->call(AuthTableSeeder::class);
        }

        app()->isProduction() ? $this->seedProduction() : $this->seedDevelopment();
    }

    /**
     * Production: the catalog and one administrator only. Store details,
     * payment methods, couriers, zones, taxes and legal pages are set up by
     * hand in /cpanel; no demo customers, orders, coupons or reviews.
     */
    private function seedProduction(): void
    {
        // Countries, currencies (IDR) and the sales channel the catalog needs.
        // Shopper's bulk inserts are not idempotent, so they run once.
        if (Country::query()->doesntExist()) {
            Model::unguarded(fn () => $this->call([
                CountriesTableSeeder::class,
                CurrenciesTableSeeder::class,
                ChannelSeeder::class,
            ]));
        }

        $this->call([StoreConfigSeeder::class, ...self::CATALOG]);
    }

    private function seedDevelopment(): void
    {
        // ShopperSeeder bulk-inserts countries/currencies without upserting, so
        // re-running it duplicates every country. Only seed base data once.
        if (Country::query()->doesntExist()) {
            $this->call(ShopperSeeder::class);
        }

        $this->call([
            StoreConfigSeeder::class,
            ...self::CATALOG,
            PaymentMethodSeeder::class,
            CarrierSeeder::class,
            ZoneSeeder::class,
            TaxSeeder::class,
            // Demo data (DemoDataOnly: refused in production even via --class=).
            CustomerSeeder::class,
            DiscountSeeder::class,
            OrderSeeder::class,
        ]);
    }
}
