<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Tests\TestCase;

final class SyncKomerceCarriersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('komerce.sync.destination_id', 17601);

        Http::preventStrayRequests();

        Inventory::factory()->create([
            'is_default' => true,
            'rajaongkir_origin_id' => '17248',
            'latitude' => -6.7366,
            'longitude' => 108.5414,
        ]);
    }

    public function test_enables_only_couriers_both_providers_offer_and_disables_the_rest(): void
    {
        $this->fakeProviders();
        Carrier::query()->create(['slug' => 'manual', 'name' => 'Kurir Toko', 'driver' => 'manual', 'is_enabled' => true]);
        Carrier::query()->create(['slug' => 'gosend', 'name' => 'GoSend', 'driver' => 'rajaongkir', 'is_enabled' => true]);
        Carrier::query()->create(['slug' => 'sicepat', 'name' => 'SiCepat Ekspres', 'driver' => 'rajaongkir', 'is_enabled' => true]);

        $this->artisan('komerce:sync-carriers')->assertSuccessful();

        $enabled = Carrier::query()->where('is_enabled', true)->pluck('slug')->sort()->values()->all();

        // Enabled = offered by Komerce Delivery (jne/jnt/spx/ide). pos: quoted by Cost only.
        $this->assertSame(['ide', 'jne', 'jnt', 'manual', 'spx'], $enabled);

        $this->assertFalse((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));
        $this->assertSame(['IDFLAT'], data_get(Carrier::query()->where('slug', 'ide')->first()?->metadata, 'komerce.services'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'sicepat')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'gosend')->value('is_enabled'));

        $spx = Carrier::query()->where('slug', 'spx')->first();
        $this->assertSame('SPX', $spx?->name);
        $this->assertSame(['SPX Standard'], data_get($spx?->metadata, 'rajaongkir.services'));
        $this->assertSame(['SPXSTD'], data_get($spx?->metadata, 'komerce.services'));
        $this->assertSame('not a RajaOngkir/Komerce courier', data_get(Carrier::query()->where('slug', 'gosend')->first()?->metadata, 'sync_reason'));

        // Existing display names are kept; new rows get the official name.
        $this->assertSame('SiCepat Ekspres', Carrier::query()->where('slug', 'sicepat')->value('name'));
    }

    public function test_dry_run_does_not_touch_the_carriers_table(): void
    {
        $this->fakeProviders();
        Carrier::query()->create(['slug' => 'pos', 'name' => 'POS Indonesia', 'driver' => 'rajaongkir', 'is_enabled' => true]);

        $this->artisan('komerce:sync-carriers', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));
        $this->assertSame(1, Carrier::query()->count());
    }

    public function test_fails_cleanly_when_the_cost_api_is_down(): void
    {
        $this->fakeProviders(['https://cost.example.test/api/v1/calculate/domestic-cost' => Http::response(['meta' => ['code' => 500, 'status' => 'error']], 500)]);
        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE', 'driver' => 'rajaongkir', 'is_enabled' => true]);

        $this->artisan('komerce:sync-carriers')->assertFailed();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
    }

    public function test_fails_cleanly_when_the_provider_returns_an_error_envelope(): void
    {
        $this->fakeProviders([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response(['meta' => ['code' => 422, 'status' => 'error', 'message' => 'invalid pin point'], 'data' => null]),
        ]);
        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE', 'driver' => 'rajaongkir', 'is_enabled' => true]);

        $this->artisan('komerce:sync-carriers')->assertFailed();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
    }

    public function test_without_delivery_api_free_plan_enables_whatever_shipping_cost_quotes(): void
    {
        config()->set('komerce.shipping_delivery_api_key', '');
        $this->fakeProviders();
        Carrier::query()->create(['slug' => 'gosend', 'name' => 'GoSend', 'driver' => 'rajaongkir', 'is_enabled' => true]);

        $this->artisan('komerce:sync-carriers')->assertSuccessful();

        $enabled = Carrier::query()->where('is_enabled', true)->pluck('slug')->sort()->values()->all();

        // Cost quoted jne/jnt/pos/spx; no Delivery probe is made at all.
        $this->assertSame(['jne', 'jnt', 'pos', 'spx'], $enabled);
        $this->assertFalse((bool) Carrier::query()->where('slug', 'gosend')->value('is_enabled'));
        $this->assertStringContainsString('no Delivery API', (string) data_get(Carrier::query()->where('slug', 'jne')->first()?->metadata, 'sync_reason'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/tariff/api/v1/calculate'));
    }

    /**
     * @param  array<string, mixed>  $overrides  URL pattern => response (wins over defaults)
     */
    private function fakeProviders(array $overrides = []): void
    {
        Http::fake(array_merge([
            // RajaOngkir Shipping Cost: what can be quoted.
            'https://cost.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['code' => 'jne', 'name' => 'JNE', 'service' => 'REG', 'cost' => 12000],
                    ['code' => 'jnt', 'name' => 'J&T', 'service' => 'EZ', 'cost' => 11000],
                    ['code' => 'pos', 'name' => 'POS', 'service' => 'Pos Reguler', 'cost' => 12000],
                    ['code' => 'spx', 'name' => 'SPX', 'service' => 'SPX Standard', 'cost' => 11000],
                ],
            ]),
            // Komerce Delivery: what the account can ship (AWB).
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    'calculate_reguler' => [
                        ['shipping_name' => 'JNE', 'service_name' => 'JNEFlat', 'shipping_cost' => 12000],
                        ['shipping_name' => 'JNT', 'service_name' => 'EZ', 'shipping_cost' => 11000],
                        ['shipping_name' => 'SPX', 'service_name' => 'SPXSTD', 'shipping_cost' => 11000],
                        ['shipping_name' => 'IDEXPRESS', 'service_name' => 'IDFLAT', 'shipping_cost' => 10500],
                    ],
                    'calculate_cargo' => [],
                    'calculate_instant' => [],
                ],
            ]),
        ], $overrides));
    }
}
