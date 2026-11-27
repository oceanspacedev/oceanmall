<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Shipping\InventoryOrigins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Inventory;
use Tests\TestCase;

final class InventoryObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_observer_automatically_resolves_rajaongkir_origin_id(): void
    {
        $this->requireModules('Komerce');

        $this->enableCost();
        $this->fakeDestinations([
            [
                'id' => '17248',
                'label' => 'KERTAWINANGUN, KEDAWUNG, CIREBON, JAWA BARAT, 45153',
                'province_name' => 'JAWA BARAT',
                'city_name' => 'CIREBON',
                'district_name' => 'KEDAWUNG',
                'subdistrict_name' => 'KERTAWINANGUN',
                'zip_code' => '45153',
            ],
        ]);

        $inventory = Inventory::factory()->create([
            'name' => 'Test Gudang',
            'street_address' => 'Jl. Tuparev No. 109F, Kertawinangun, Kedawung',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'postal_code' => '45153',
        ]);

        $this->assertSame('17248', $this->origin($inventory));
        $this->assertDatabaseHas('inventory_shipping_origins', [
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'label' => 'KERTAWINANGUN, KEDAWUNG, CIREBON, JAWA BARAT, 45153',
        ]);
    }

    public function test_inventory_observer_does_not_guess_between_ambiguous_results(): void
    {
        $this->enableCost();
        $this->fakeDestinations([
            [
                'id' => '17248',
                'label' => 'KELURAHAN SATU, CIREBON, 45153',
                'province_name' => 'JAWA BARAT',
                'city_name' => 'CIREBON',
                'district_name' => 'KECAMATAN SATU',
                'subdistrict_name' => 'KELURAHAN SATU',
                'zip_code' => '45153',
            ],
            [
                'id' => '17249',
                'label' => 'KELURAHAN DUA, CIREBON, 45153',
                'province_name' => 'JAWA BARAT',
                'city_name' => 'CIREBON',
                'district_name' => 'KECAMATAN DUA',
                'subdistrict_name' => 'KELURAHAN DUA',
                'zip_code' => '45153',
            ],
        ]);

        $inventory = Inventory::factory()->create([
            'street_address' => 'Jl. Tanpa Kelurahan',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'postal_code' => '45153',
        ]);

        $this->assertNull($this->origin($inventory));
    }

    public function test_delivery_key_resolves_komerce_origin_via_delivery_destination_search(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'id' => 17248,
                        'label' => 'KERTAWINANGUN, KEDAWUNG, CIREBON, 45153',
                        'city_name' => 'CIREBON',
                        'district_name' => 'KEDAWUNG',
                        'subdistrict_name' => 'KERTAWINANGUN',
                        'zip_code' => '45153',
                    ],
                ],
            ]),
        ]);

        $inventory = Inventory::factory()->create([
            'street_address' => 'Jl. Tuparev, Kertawinangun',
            'city' => 'Cirebon',
            'postal_code' => '45153',
        ]);

        $this->assertSame('17248', resolve(InventoryOrigins::class)->get($inventory, 'komerce'));
        $this->assertNull($this->origin($inventory));
        Http::assertSentCount(1);
    }

    public function test_origin_matcher_skips_http_when_shipping_cost_quota_is_exhausted(): void
    {
        $this->requireModules('Komerce');

        $this->enableCost();
        resolve(KomerceCapabilities::class)->observe('shipping_cost', 429, json_encode(['meta' => ['message' => 'Daily limit exceeded']]));
        Http::fake();

        $inventory = Inventory::factory()->create([
            'street_address' => 'Jl. Tuparev',
            'city' => 'Cirebon',
            'postal_code' => '45153',
        ]);

        $this->assertNull($this->origin($inventory));
        Http::assertNothingSent();
    }

    public function test_origin_matcher_stops_after_first_429_without_sending_fallback_queries(): void
    {
        $this->requireModules('Komerce');

        $this->enableCost();
        Http::fake([
            'https://shipping.example.test/api/v1/destination/domestic-destination*' => Http::response(
                ['meta' => ['code' => 429, 'message' => 'Daily request limit exceeded']],
                429,
            ),
        ]);

        $inventory = Inventory::factory()->create([
            'street_address' => 'Jl. Tuparev No. 10',
            'city' => 'Cirebon',
            'postal_code' => '45153',
        ]);

        $this->assertNull($this->origin($inventory));
        $this->assertTrue(resolve(KomerceCapabilities::class)->quotaExhausted('shipping_cost'));
        Http::assertSentCount(1);
    }

    public function test_without_shipping_providers_no_lookup_is_made(): void
    {
        Http::fake();

        $inventory = Inventory::factory()->create([
            'street_address' => 'Jl. Tuparev',
            'city' => 'Cirebon',
            'postal_code' => '45153',
        ]);

        $this->assertNull($this->origin($inventory));
        Http::assertNothingSent();
    }

    public function test_inventory_observer_rejects_a_unique_postcode_result_that_conflicts_with_city(): void
    {
        $this->enableCost();
        $this->fakeDestinations([[
            'id' => '17248',
            'label' => 'KERTAWINANGUN, CIREBON, 45153',
            'province_name' => 'JAWA BARAT',
            'city_name' => 'CIREBON',
            'district_name' => 'KEDAWUNG',
            'subdistrict_name' => 'KERTAWINANGUN',
            'zip_code' => '45153',
        ]]);

        $inventory = Inventory::factory()->create([
            'street_address' => 'Jl. Merdeka',
            'city' => 'Bandung',
            'state' => 'Jawa Barat',
            'postal_code' => '45153',
        ]);

        $this->assertNull($this->origin($inventory));
    }

    public function test_explicit_shipping_origins_attribute_is_stored_without_a_lookup(): void
    {
        $this->enableCost();
        Http::fake();

        $inventory = Inventory::factory()->create([
            'city' => 'Cirebon',
            'postal_code' => '45153',
            'shipping_origins' => ['rajaongkir' => '501', 'biteship' => 'IDNP6'],
        ]);

        $this->assertSame('501', $this->origin($inventory));
        $this->assertSame('IDNP6', resolve(InventoryOrigins::class)->get($inventory, 'biteship'));
        Http::assertNothingSent();
    }

    public function test_legacy_rajaongkir_origin_id_attribute_is_still_accepted(): void
    {
        $inventory = Inventory::factory()->create(['rajaongkir_origin_id' => '17248']);

        // Mapped onto the table; the legacy column (kept for the previous
        // release during a rolling deploy) is never written.
        $this->assertNull(DB::table(shopper_table('inventories'))->where('id', $inventory->id)->value('rajaongkir_origin_id'));
        $this->assertSame('17248', $this->origin($inventory));

        $inventory->refresh()->update(['rajaongkir_origin_id' => '114']);
        $this->assertSame('114', $this->origin($inventory));

        // The value the column still holds on a loaded model is not a request:
        // saving it unchanged neither reverts nor clears the origin.
        DB::table(shopper_table('inventories'))->where('id', $inventory->id)->update(['rajaongkir_origin_id' => '999']);
        $inventory->refresh()->update(['name' => 'Renamed']);
        $this->assertSame('114', $this->origin($inventory));
        $this->assertSame('999', DB::table(shopper_table('inventories'))->where('id', $inventory->id)->value('rajaongkir_origin_id'));

        $inventory->refresh()->update(['rajaongkir_origin_id' => '']);
        $this->assertNull($this->origin($inventory));
    }

    private function enableCost(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-komerce-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function fakeDestinations(array $rows): void
    {
        Http::fake([
            'https://shipping.example.test/api/v1/destination/domestic-destination*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => $rows,
            ]),
        ]);
    }

    private function origin(Inventory $inventory): ?string
    {
        return resolve(InventoryOrigins::class)->get($inventory, 'rajaongkir');
    }
}
