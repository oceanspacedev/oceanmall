<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use App\Livewire\Shopper\InventoryForm;
use App\Models\User;
use App\Shipping\InventoryOrigins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Inventory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InventoryRajaOngkirOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopper_location_form_exposes_and_saves_rajaongkir_origin_id(): void
    {
        $admin = $this->admin();
        $inventory = $this->inventory();

        Livewire::actingAs($admin)
            ->test(InventoryForm::class, ['inventory' => $inventory])
            ->set('data.priority', 0)
            ->set('data.shipping_origins.rajaongkir', '17248')
            ->call('store')
            ->assertHasNoErrors();

        $this->assertSame('17248', resolve(InventoryOrigins::class)->get($inventory, 'rajaongkir'));
        $this->assertDatabaseHas('inventory_shipping_origins', [
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'external_id' => '17248',
        ]);
    }

    public function test_location_form_loads_and_clears_the_stored_origin(): void
    {
        $admin = $this->admin();
        $inventory = $this->inventory();
        resolve(InventoryOrigins::class)->put($inventory, 'rajaongkir', '501');

        Livewire::actingAs($admin)
            ->test(InventoryForm::class, ['inventory' => $inventory])
            ->assertSet('data.shipping_origins.rajaongkir', '501')
            ->set('data.priority', 0)
            ->set('data.shipping_origins.rajaongkir', null)
            ->call('store')
            ->assertHasNoErrors();

        $this->assertNull(resolve(InventoryOrigins::class)->get($inventory, 'rajaongkir'));
    }

    private function admin(): User
    {
        // Origin IDs are offered for configured drivers that quote from provider areas.
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        // Address auto-resolution must not reach the real API (a 401 there
        // would mark the product as not on the plan).
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->configureShopperCpanel();

        $admin = User::factory()->create();
        Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.admin'),
            'guard_name' => 'web',
        ]);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        return $admin;
    }

    private function inventory(): Inventory
    {
        $country = Country::factory()->create(['cca2' => 'ID']);

        return Inventory::factory()->create([
            'country_id' => $country->id,
            'name' => 'Gudang Jakarta',
            // Fixed: Faker's en_US phoneNumber() sometimes yields formats like
            // "+1 (480) 844-2504" that Filament's tel() regex rejects, which made
            // this test fail at random.
            'phone_number' => '081234567890',
        ]);
    }
}
