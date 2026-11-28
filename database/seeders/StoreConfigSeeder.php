<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Setting;
use Shopper\Shipping\Facades\Shipping;
use Spatie\Permission\Models\Role;

class StoreConfigSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            // Production: one administrator from the environment (never a known
            // password), the IDR currency and the default warehouse that the
            // catalog prices and stock need. Store details, payment methods,
            // couriers, zones and taxes are configured by hand in /cpanel.
            $this->seedProductionAdmin();
            $this->seedCurrency();
            $this->seedDefaultInventory();

            return;
        }

        $this->seedDemoAccounts();
        $this->seedStoreSettings();
    }

    private function seedProductionAdmin(): void
    {
        /** @var string $role */
        $role = config('shopper.admin.roles.admin');

        if (Role::query()->where('name', $role)->doesntExist()) {
            $this->command->warn(PHP_EOL.'No admin created: roles are missing — run `php artisan db:seed` (it seeds roles first).');

            return;
        }

        if (User::role($role)->exists()) {
            $this->command->info(PHP_EOL.'An administrator already exists — no admin account created.');

            return;
        }

        $email = (string) config('app.admin.email');
        $password = (string) config('app.admin.password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12) {
            $this->command->warn(PHP_EOL.'No admin created: set ADMIN_EMAIL and ADMIN_PASSWORD (12+ characters), or run `php artisan shopper:user`.');

            return;
        }

        /** @var User $admin */
        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => (string) config('app.admin.first_name', 'Admin'),
                'last_name' => (string) config('app.admin.last_name', 'OceanMall'),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );
        $admin->assignRole($role);

        $this->command->info(PHP_EOL."Administrator {$email} created. Remove ADMIN_PASSWORD from the environment.");
    }

    private function seedCurrency(): void
    {
        $idrId = Currency::query()->where('code', 'IDR')->value('id');

        if ($idrId === null) {
            return;
        }

        foreach (['default_currency_id' => $idrId, 'currencies' => [$idrId]] as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], [
                'value' => $value,
                'locked' => true,
                'display_name' => Setting::lockedAttributesDisplayName($key),
            ]);
        }
    }

    private function seedDemoAccounts(): void
    {
        $this->command->warn(PHP_EOL.'Creating admin user...');

        /** @var User $admin */
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@oceanmall.test'],
            [
                'first_name' => 'Admin',
                'last_name' => 'OceanMall',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ],
        );

        /** @var string $role */
        $role = config('shopper.admin.roles.admin');
        $admin->assignRole($role);

        $this->command->info('Admin user created (admin@oceanmall.test / password123).');

        $this->command->warn(PHP_EOL.'Creating customer user...');

        /** @var User $customer */
        $customer = User::query()->updateOrCreate(
            ['email' => 'customer@oceanmall.test'],
            [
                'first_name' => 'Budi',
                'last_name' => 'Santoso',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ],
        );

        /** @var string $userRole */
        $userRole = config('shopper.admin.roles.user');
        if (! $customer->hasRole($userRole)) {
            $customer->assignRole($userRole);
        }

        $this->command->info('Customer user created (customer@oceanmall.test / password123).');
    }

    private function seedStoreSettings(): void
    {
        $this->command->warn(PHP_EOL.'Configuring store settings...');

        $indonesiaId = Country::query()->where('cca2', 'ID')->value('id');
        $idrId = Currency::query()->where('code', 'IDR')->value('id');

        $settings = [
            'name' => 'OceanMall',
            'email' => 'info@oceanmall.test',
            'about' => 'OceanMall — Belanja gadget & lifestyle terpercaya. Produk original, pengiriman ke seluruh Indonesia, pembayaran aman.',
            'phone_number' => '0216296612',
            'street_address' => 'Jl. Tuparev No. 109F, Kertawinangun, Kedawung',
            'postal_code' => '45153',
            'state' => 'Jawa Barat',
            'city' => 'Cirebon',
            'country_id' => $indonesiaId,
            'default_currency_id' => $idrId,
            'currencies' => [$idrId],
            'facebook_link' => null,
            'instagram_link' => 'https://instagram.com/completeselular',
            'twitter_link' => null,
        ];

        foreach ($settings as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], [
                'value' => $value,
                'locked' => true,
                'display_name' => Setting::lockedAttributesDisplayName($key),
            ]);
        }

        $this->seedDefaultInventory();

        $this->command->info('Store settings configured.');
    }

    /**
     * The default warehouse that holds the catalog stock. In production an
     * existing warehouse (edited in /cpanel) is never overwritten.
     */
    private function seedDefaultInventory(): void
    {
        if (app()->isProduction() && Inventory::query()->where('is_default', true)->exists()) {
            return;
        }

        $indonesiaId = Country::query()->where('cca2', 'ID')->value('id');

        Inventory::query()->updateOrCreate(
            ['code' => 'oceanmall-cirebon'],
            [
                'name' => 'OceanMall Cirebon',
                'email' => 'info@oceanmall.test',
                'street_address' => 'Jl. Tuparev No. 109F, Kertawinangun, Kedawung',
                'postal_code' => '45153',
                'city' => 'Cirebon',
                'state' => 'Jawa Barat',
                'is_default' => true,
                'country_id' => $indonesiaId,
                // Warehouse origin per shipping driver, saved to
                // inventory_shipping_origins by InventoryObserver. Demo ids
                // are only written for drivers that are actually installed,
                // so a pure Shopper store gets no provider rows.
                // KERTAWINANGUN, KEDAWUNG, CIREBON — RajaOngkir domestic destination id
                'shipping_origins' => array_intersect_key(
                    ['rajaongkir' => '17248'],
                    array_flip(Shipping::availableDrivers()),
                ),
            ],
        );
    }
}
