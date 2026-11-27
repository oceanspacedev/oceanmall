<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier1;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Address;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F2DestinationAndAddressTest extends VendorPackageTierTestCase
{
    public function test_keyless_tier_disables_destination_search_and_accepts_address_without_destination_id(): void
    {
        $this->applyTier('keyless');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);

        $this->webLoadCheckout($webUser)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shop/checkout')
                ->where('shippingCapabilities.destinationSearch', false)
                ->where('shippingCapabilities.requiresDestination', false)
                ->where('shippingCapabilities.requiresCoordinates', false)
            );

        $this->webSearchDestinations($webUser, 'Jakarta')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->webSaveShippingAddress($webUser, [
            'destination_id' => null,
            'destination_label' => null,
            'destination_driver' => null,
        ])->assertRedirect(route('shop.checkout.index'));

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_capabilities.destination_search', false)
            ->assertJsonPath('data.shipping_capabilities.requires_destination', false);

        // Unknown postal code echoes free-text row `text:55281`; already-saved postal code returns known address-book region
        $this->apiSearchDestinations($apiUser, '55281')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'text:55281')
            ->assertJsonPath('data.0.postal_code', '55281');

        $this->apiSearchDestinations($apiUser, '10110')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'text:Jakarta Pusat, DKI Jakarta 10110')
            ->assertJsonPath('data.0.postal_code', '10110');

        $this->apiSaveShippingAddress($apiUser, [
            'destination_id' => null,
            'destination_label' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.city', 'Jakarta Pusat');

        Http::assertNothingSent();
    }

    public function test_starter_tier_searches_cost_destinations_and_persists_neutral_metadata_on_web_and_api(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);

        $this->webSearchDestinations($webUser, 'Gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601')
            ->assertJsonPath('data.0.postal_code', '10110')
            ->assertJsonPath('data.0.city', 'JAKARTA PUSAT');

        $this->webSaveShippingAddress($webUser)
            ->assertRedirect(route('shop.checkout.index'));

        $webAddress = Address::query()->where('user_id', $webUser->id)->firstOrFail();
        $webMeta = is_string($webAddress->metadata) ? json_decode($webAddress->metadata, true) : $webAddress->metadata;
        $this->assertSame('17601', $webMeta['destination_id'] ?? null);
        $this->assertSame('rajaongkir', $webMeta['destination_driver'] ?? null);

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSearchDestinations($apiUser, 'Senayan')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601');

        $this->apiSaveShippingAddress($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17601')
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', '17601')
            ->assertJsonPath('data.shipping_address.destination_driver', 'rajaongkir');
    }

    public function test_delivery_only_tier_enables_destination_search_and_persists_destination_via_delivery_driver(): void
    {
        $this->applyTier('delivery_only');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);

        $this->webLoadCheckout($webUser)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shippingCapabilities.destinationSearch', true)
                ->where('shippingCapabilities.requiresDestination', true)
            );

        $this->webSearchDestinations($webUser, 'Gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601')
            ->assertJsonPath('data.0.postal_code', '10110');

        $this->webSaveShippingAddress($webUser, [
            'destination_id' => '17601',
            'destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
        ])->assertRedirect(route('shop.checkout.index'));

        $webAddress = Address::query()->where('user_id', $webUser->id)->firstOrFail();
        $webMeta = is_string($webAddress->metadata) ? json_decode($webAddress->metadata, true) : $webAddress->metadata;
        $this->assertSame('17601', $webMeta['destination_id'] ?? null);
        $this->assertSame('komerce', $webMeta['destination_driver'] ?? null);

        $this->webLoadCheckout($webUser)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shippingAddress.destination_id', '17601')
                ->where('shippingAddress.destination_driver', 'komerce')
            );

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSearchDestinations($apiUser, 'Gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601');

        $this->apiSaveShippingAddress($apiUser, [
            'destination_id' => '17601',
            'destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17601')
            ->assertJsonPath('data.shipping_address.destination_driver', 'komerce');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/tariff/api/v1/destination/search')
            && $request->hasHeader('x-api-key', 'delivery-key')
            && ($request->data()['keyword'] ?? null) === 'gambir');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v1/destination/domestic-destination'));
    }

    public function test_account_address_book_crud_and_default_shipping_auto_apply_at_checkout(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();

        // Web address creation + default shipping auto-apply
        ['user' => $webUser] = $this->webCustomerWithCart($product);

        $this->webCreateAccountAddress($webUser, [
            'street_address' => 'Jl. Sudirman No. 88',
            'destination_id' => '17547',
            'destination_label' => 'SENAYAN, KEBAYORAN BARU, JAKARTA SELATAN, DKI JAKARTA, 12190',
            'postal_code' => '12190',
            'city' => 'Jakarta Selatan',
        ])->assertRedirect(route('account.addresses'));

        $address = Address::query()->where('user_id', $webUser->id)->firstOrFail();

        $this->actingAs($webUser)
            ->patch(route('account.addresses.default-shipping', $address))
            ->assertRedirect(route('account.addresses'));

        $this->webLoadCheckout($webUser)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shippingAddress.street_address', 'Jl. Sudirman No. 88')
                ->where('shippingAddress.destination_id', '17547')
            );

        // API saved address apply
        $apiUser = $this->apiCustomerWithCart($product);
        $created = $this->apiCreateAddress($apiUser, [
            'street_address' => 'Jl. Thamrin No. 12',
            'shipping_default' => true,
        ])->assertCreated();

        $addressId = (int) $created->json('data.id');

        $this->apiApplySavedAddress($apiUser, $addressId)
            ->assertOk()
            ->assertJsonPath('data.shipping_address.street_address', 'Jl. Thamrin No. 12')
            ->assertJsonPath('data.shipping_address.destination_id', '17601');
    }

    public function test_api_partial_address_update_preserves_destination_and_accepts_legacy_rajaongkir_keys(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();
        $apiUser = $this->apiCustomerWithCart($product);

        $saved = $this->fixtures->createSavedAddress($apiUser, [
            'shipping_default' => true,
        ]);

        // Partial update changing only street_address keeps destination_id & driver
        $this->apiUpdateAddress($apiUser, $saved->id, [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka No. 99',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'country_id' => $this->fixtures->country->id,
            'type' => AddressType::Shipping->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.street_address', 'Jl. Merdeka No. 99')
            ->assertJsonPath('data.destination_id', '17601')
            ->assertJsonPath('data.rajaongkir_destination_id', '17601');

        // Legacy rajaongkir_destination_id input is accepted and echoed neutrally
        $this->apiSaveShippingAddress($apiUser, [
            'destination_id' => null,
            'destination_label' => null,
            'rajaongkir_destination_id' => '17547',
            'rajaongkir_destination_label' => 'SENAYAN, KEBAYORAN BARU, JAKARTA SELATAN, DKI JAKARTA, 12190',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17547')
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', '17547')
            ->assertJsonPath('data.shipping_address.destination_driver', 'rajaongkir');
    }
}
