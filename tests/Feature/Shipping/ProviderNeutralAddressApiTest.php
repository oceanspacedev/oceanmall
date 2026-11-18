<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

/**
 * Mobile API contract for provider-neutral destination fields: neutral keys
 * are accepted and emitted, and the legacy rajaongkir_* keys that app builds
 * already in the stores send/read keep working.
 */
final class ProviderNeutralAddressApiTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $this->country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($this->country->id);
    }

    public function test_keyless_checkout_reports_no_capabilities_and_accepts_an_address_without_destination(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_capabilities', [
                'destination_search' => false,
                'requires_destination' => false,
                'requires_coordinates' => false,
            ]);

        $this->postJson('/api/v1/checkout/shipping-address', $this->address())
            ->assertOk()
            ->assertJsonPath('data.shipping_address.city', 'Jakarta Selatan')
            ->assertJsonPath('data.shipping_address.destination_id', null);

        Http::assertNothingSent();
    }

    public function test_legacy_destination_keys_are_stored_neutral_and_echoed_in_both_spellings(): void
    {
        $this->enableCost();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_capabilities.destination_search', true)
            ->assertJsonPath('data.shipping_capabilities.requires_destination', true);

        $this->postJson('/api/v1/checkout/shipping-address', [
            ...$this->address(),
            'rajaongkir_destination_id' => 17547,
            'rajaongkir_destination_label' => 'MELAWAI, KEBAYORAN BARU',
            'rajaongkir_pin_point' => '-6.2380,106.7830',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17547')
            ->assertJsonPath('data.shipping_address.destination_driver', 'rajaongkir')
            ->assertJsonPath('data.shipping_address.pin_point', '-6.2380,106.7830')
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', '17547')
            ->assertJsonPath('data.saved_addresses.0.destination_label', 'MELAWAI, KEBAYORAN BARU')
            ->assertJsonPath('data.saved_addresses.0.rajaongkir_destination_label', 'MELAWAI, KEBAYORAN BARU');

        $metadata = $this->metadataOf(Address::query()->where('user_id', $user->id)->firstOrFail());
        $this->assertSame([
            'destination_id' => '17547',
            'destination_driver' => 'rajaongkir',
            'destination_label' => 'MELAWAI, KEBAYORAN BARU',
            'pin_point' => '-6.2380,106.7830',
        ], $metadata);
    }

    public function test_neutral_destination_keys_are_accepted(): void
    {
        $this->enableCost();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/checkout/shipping-address', [
            ...$this->address(),
            'destination_id' => '17547',
            'destination_label' => 'MELAWAI',
            'latitude' => -6.238,
            'longitude' => 106.783,
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17547')
            ->assertJsonPath('data.shipping_address.destination_driver', 'rajaongkir')
            ->assertJsonPath('data.shipping_address.pin_point', '-6.238,106.783');
    }

    public function test_missing_destination_error_is_mirrored_under_the_legacy_key(): void
    {
        $this->enableCost();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/checkout/shipping-address', $this->address())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_id', 'rajaongkir_destination_id']);
    }

    public function test_address_book_accepts_neutral_keys_and_keeps_them_on_partial_update(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/addresses', [
            ...$this->address(),
            'country_id' => $this->country->id,
            'type' => 'shipping',
            'destination_id' => 'AREA-9',
            'destination_label' => 'Area Sembilan',
            'destination_driver' => 'biteship',
        ])
            ->assertCreated()
            ->assertJsonPath('data.destination_id', 'AREA-9')
            ->assertJsonPath('data.destination_driver', 'biteship')
            ->assertJsonPath('data.rajaongkir_destination_id', 'AREA-9')
            ->json('data.id');

        $this->patchJson("/api/v1/addresses/{$id}", [
            ...$this->address(),
            'street_address' => 'Jl. Baru 2',
            'country_id' => $this->country->id,
            'type' => 'shipping',
        ])
            ->assertOk()
            ->assertJsonPath('data.street_address', 'Jl. Baru 2')
            ->assertJsonPath('data.destination_id', 'AREA-9')
            ->assertJsonPath('data.destination_label', 'Area Sembilan');

        $this->patchJson("/api/v1/addresses/{$id}", [
            ...$this->address(),
            'country_id' => $this->country->id,
            'type' => 'shipping',
            'rajaongkir_destination_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.destination_id', null)
            ->assertJsonPath('data.destination_driver', null);
    }

    public function test_an_unchanged_destination_posted_back_keeps_its_driver_after_a_provider_switch(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $address = Address::query()->create([
            ...$this->address(),
            'user_id' => $user->id,
            'country_id' => $this->country->id,
            'type' => 'shipping',
            'metadata' => json_encode(['destination_id' => 'AREA-9', 'destination_driver' => 'biteship'], JSON_THROW_ON_ERROR),
        ]);

        // RajaOngkir is today's destination driver. A client that echoes the
        // stored id (without its driver) must not relabel it as a RajaOngkir id.
        $this->enableCost();

        $this->patchJson("/api/v1/addresses/{$address->id}", [
            ...$this->address(),
            'country_id' => $this->country->id,
            'type' => 'shipping',
            'destination_id' => 'AREA-9',
        ])->assertOk();

        $this->assertSame('biteship', $this->metadataOf($address->refresh())['destination_driver']);

        // The checkout listing does not offer the foreign id at all.
        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.saved_addresses.0.destination_id', null)
            ->assertJsonPath('data.saved_addresses.0.rajaongkir_destination_id', null);

        // A newly picked id is today's driver's.
        $this->patchJson("/api/v1/addresses/{$address->id}", [
            ...$this->address(),
            'country_id' => $this->country->id,
            'type' => 'shipping',
            'destination_id' => '17547',
        ])->assertOk();

        $this->assertSame('rajaongkir', $this->metadataOf($address->refresh())['destination_driver']);
    }

    private function enableCost(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        Http::fake(['*' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => []])]);
    }

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai 1',
            'postal_code' => '12160',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataOf(Address $address): array
    {
        $raw = $address->getAttribute('metadata');

        return is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;
    }
}
