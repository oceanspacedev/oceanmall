<?php

namespace Tests;

use App\Integrations\Komerce\KomerceAddon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;
use Shopper\Core\Models\Setting;
use Shopper\Database\Seeders\PermissionRoleTableSeeder;
use Shopper\Database\Seeders\PermissionsTableSeeder;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Capability verdicts and request counters live in the cache; never let
        // one test's observed 401/429 leak into the next.
        Cache::flush();

        // Keep Feature tests hermetic: local UAT credentials in `.env` must not
        // enable a product-specific integration and change test behavior.
        config([
            'komerce.enabled' => null,
            'komerce.payment_api_key' => '',
            'komerce.shipping_cost_api_key' => '',
            'komerce.shipping_delivery_api_key' => '',
            'komerce.qrisly_api_key' => '',
            'komerce.qrisly_qris_id' => '',
            'komerce.webhook_secret' => '',
            'xendit.secret_key' => '',
            'xendit.callback_token' => '',
            'midtrans.server_key' => '',
            'midtrans.client_key' => '',
            'midtrans.is_production' => null,
            'biteship.api_key' => '',
            'biteship.webhook_secret' => '',
            'kiriminaja.api_key' => '',
            'kiriminaja.webhook_secret' => '',
        ]);
    }

    /**
     * Shopper registers addons when the panel resolves (once per booted app,
     * i.e. per request in production). Tests configure Komerce keys after
     * boot, so the 'komerce' addon, which only enables itself while a key is
     * configured, is registered here once the test has set its keys.
     */
    protected function registerKomerceAddon(): void
    {
        $panel = app('shopper');

        if (! $panel->hasAddon(KomerceAddon::ID)) {
            $panel->addon(new KomerceAddon);
        }
    }

    /**
     * Seed Shopper settings + permissions so /cpanel Dashboard middleware passes
     * for admins and fails cleanly (403) for non-admins.
     */
    protected function configureShopperCpanel(): void
    {
        Setting::query()->updateOrCreate(['key' => 'email'], ['value' => 'ops@oceanmall.test']);
        Setting::query()->updateOrCreate(['key' => 'street_address'], ['value' => 'Jl. Test 1']);
        Cache::forget('shopper-setting.email');
        Cache::forget('shopper-setting.street_address');

        Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.admin'),
            'guard_name' => 'web',
        ]);

        $this->seed(PermissionsTableSeeder::class);
        $this->seed(PermissionRoleTableSeeder::class);
    }

    /**
     * Fake Komerce Payment HTTP, always including GET /methods so checkout
     * tests do not leak a real request when the official catalog is consulted.
     *
     * @param  array<string, mixed>  $routes
     */
    protected function fakeKomercePaymentHttp(array $routes = []): void
    {
        Http::fake(array_merge([
            'https://payment.example.test/user/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'payment_type' => 'va',
                        'bank_code' => 'BCA',
                        'min_amount' => 10000,
                        'max_amount' => 999999999,
                        'logo_url' => 'https://example.test/bca.png',
                    ],
                    [
                        'payment_type' => 'qris',
                        'bank_code' => '',
                        'min_amount' => 10000,
                        'max_amount' => 10000000,
                    ],
                ],
            ]),
        ], $routes));
    }

    /**
     * Generic tests that exercise a provider module (app/Integrations/<Name>)
     * call this first: with the module removed (provider line and folder) they
     * are skipped instead of failing, so a pure Shopper store keeps a green
     * suite. Tests that only make sense with the module live in
     * tests/{Feature,Unit}/<Name> and are deleted together with it.
     */
    protected function requireModules(string ...$modules): void
    {
        foreach ($modules as $module) {
            $provider = "App\\Integrations\\{$module}\\{$module}ServiceProvider";

            // By name only: class_exists() would autoload, and an optimized
            // classmap still lists a deleted module until composer dump-autoload.
            if (! array_key_exists($provider, $this->app->getLoadedProviders())) {
                $this->markTestSkipped("Needs the {$module} provider module (app/Integrations/{$module}).");
            }
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
