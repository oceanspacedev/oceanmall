<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Shopper\Core\Models\Inventory;
use Tests\TestCase;

final class ProductionSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    public function test_production_seed_holds_only_the_catalog_and_one_administrator(): void
    {
        config()->set('app.admin.email', 'owner@example.com');
        config()->set('app.admin.password', 'a-long-secret-password');

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertSame('owner@example.com', $admin->email);
        $this->assertTrue($admin->hasRole(config('shopper.admin.roles.admin')));
        $this->assertTrue(Hash::check('a-long-secret-password', $admin->password));

        $seedCount = count(json_decode((string) file_get_contents(database_path('seeders/data/products.json')), true));
        $this->assertSame($seedCount, Product::query()->count());
        $this->assertSame('IDR', shopper_currency());
        $this->assertTrue(Inventory::query()->where('is_default', true)->exists());
        $this->assertGreaterThan(0, (int) DB::table(shopper_table('inventory_histories'))->sum('quantity'));

        // Nothing else: store config is set up by hand, no demo data.
        foreach (['payment_methods', 'carriers', 'zones', 'orders', 'discounts', 'reviews', 'legals'] as $table) {
            $this->assertSame(0, DB::table(shopper_table($table))->count(), "{$table} should be empty");
        }
    }

    public function test_reseeding_production_keeps_the_admin_password_and_adds_no_second_admin(): void
    {
        config()->set('app.admin.email', 'owner@example.com');
        config()->set('app.admin.password', 'a-long-secret-password');
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        User::query()->sole()->forceFill(['password' => Hash::make('changed-in-the-panel')])->save();
        config()->set('app.admin.email', 'someone-else@example.com');

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertSame('owner@example.com', $admin->email);
        $this->assertTrue(Hash::check('changed-in-the-panel', $admin->password));
    }

    public function test_no_admin_is_created_without_a_strong_password(): void
    {
        config()->set('app.admin.email', 'owner@example.com');
        config()->set('app.admin.password', 'short');

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertGreaterThan(0, Product::query()->count());
    }
}
