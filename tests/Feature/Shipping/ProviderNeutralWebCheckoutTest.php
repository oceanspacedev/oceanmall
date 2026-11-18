<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\CheckoutSession;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

final class ProviderNeutralWebCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manifest = public_path('build/manifest.json');
        $this->withHeader(
            'X-Inertia-Version',
            file_exists($manifest) ? hash_file('xxh128', $manifest) : '',
        );
    }

    public function test_keyless_checkout_exposes_neutral_capabilities_and_accepts_an_address_without_destination(): void
    {
        Http::fake();
        [$user, , $cart] = $this->customerWithCart();

        $this->actingAs($user)
            ->withSession([
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'zone_country_code' => 'ID',
            ])
            ->post(route('shop.checkout.shipping-address'), $this->address())
            ->assertRedirect(route('shop.checkout.index'))
            ->assertSessionHasNoErrors();

        $session = session(CheckoutSession::SHIPPING_ADDRESS);
        $this->assertNull($session['destination_id'] ?? null);
        $this->assertArrayNotHasKey('rajaongkir_destination_id', $session);

        $this->get(route('shop.checkout.index'), ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('props.shippingCapabilities', [
                'destinationSearch' => false,
                'requiresDestination' => false,
                'requiresCoordinates' => false,
            ])
            ->assertJsonMissingPath('props.komerceEnabled');

        Http::assertNothingSent();
    }

    public function test_cost_key_turns_on_destination_search_and_requirement(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        Http::fake(['*' => Http::response(['meta' => ['code' => 200], 'data' => []])]);
        [$user, , $cart] = $this->customerWithCart();

        $this->actingAs($user)
            ->withSession([
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'zone_country_code' => 'ID',
            ])
            ->get(route('shop.checkout.index'), ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('props.shippingCapabilities.destinationSearch', true)
            ->assertJsonPath('props.shippingCapabilities.requiresDestination', true);
    }

    public function test_saved_address_from_another_provider_is_not_applied_as_a_complete_destination(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        Http::fake(['*' => Http::response(['meta' => ['code' => 200], 'data' => []])]);
        [$user, $country, $cart] = $this->customerWithCart();

        Address::query()->create([
            ...$this->address(),
            'user_id' => $user->id,
            'country_id' => $country->id,
            'type' => AddressType::Shipping,
            'shipping_default' => true,
            'billing_default' => false,
            'metadata' => json_encode([
                'destination_id' => 'AREA-9',
                'destination_driver' => 'biteship',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($user)
            ->withSession([
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'zone_country_code' => 'ID',
            ])
            ->get(route('shop.checkout.index'), ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('props.shippingAddress', null)
            // A stale id from another provider is not offered: the client would
            // post it back without its driver and it would be quoted as a
            // RajaOngkir id. The customer picks the kecamatan again.
            ->assertJsonPath('props.savedAddresses.0.destination_id', null)
            ->assertJsonPath('props.savedAddresses.0.destination_label', null)
            ->assertJsonPath('props.savedAddresses.0.destination_driver', null)
            ->assertJsonMissingPath('props.savedAddresses.0.rajaongkir_destination_id');
    }

    public function test_a_checkout_session_saved_with_legacy_keys_prefills_the_neutral_destination(): void
    {
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        Http::fake(['*' => Http::response(['meta' => ['code' => 200], 'data' => []])]);
        [$user, $country, $cart] = $this->customerWithCart();

        $this->actingAs($user)
            ->withSession([
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'zone_country_code' => 'ID',
                // Written by the release before the neutral keys.
                'checkout' => ['shipping_address' => [
                    ...$this->address(),
                    'country_id' => $country->id,
                    'rajaongkir_destination_id' => '17547',
                    'rajaongkir_destination_label' => 'MELAWAI',
                ]],
            ])
            ->get(route('shop.checkout.index', ['step' => 1]), ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('props.shippingAddress.destination_id', '17547')
            ->assertJsonPath('props.shippingAddress.destination_label', 'MELAWAI')
            ->assertJsonMissingPath('props.shippingAddress.rajaongkir_destination_id');
    }

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai Raya No. 1',
            'postal_code' => '12240',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
        ];
    }

    /**
     * @return array{0: User, 1: Country, 2: Cart}
     */
    private function customerWithCart(): array
    {
        $user = User::factory()->create();
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $cart = Cart::query()->create([
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
        ]);
        $product = Product::factory()->standard()->create();
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        return [$user, $country, $cart];
    }
}
