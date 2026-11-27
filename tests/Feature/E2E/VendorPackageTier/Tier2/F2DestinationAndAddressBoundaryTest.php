<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier2;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F2DestinationAndAddressBoundaryTest extends VendorPackageTierTestCase
{
    public function test_quota_exhausted_cost_destination_search_returns_quota_error_while_cached_queries_still_work(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();
        $apiUser = $this->apiCustomerWithCart($product);
        ['user' => $webUser] = $this->webCustomerWithCart($product);

        // Warm cache for 'gambir' before quota hits
        $this->apiSearchDestinations($apiUser, 'gambir')->assertOk();

        // Exhaust Shipping Cost quota without flushing the destination cache
        $this->observeVendorCapability('shipping_cost', 429, 'Daily request limit exceeded');
        KomerceHttpFakeBuilder::forTier('quota_exhausted')->apply();

        // Cached query returns 200 without hitting upstream
        $this->apiSearchDestinations($apiUser, 'gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601');

        Http::assertNothingSent();

        // Uncached query must surface quota error cleanly (429 or 503 with Indonesian quota message, never 500 or silent 200 [])
        $apiResponse = $this->apiSearchDestinations($apiUser, 'bandung');
        $this->assertContains($apiResponse->getStatusCode(), [429, 503]);
        $this->assertMatchesRegularExpression('/kuota|batas/i', (string) $apiResponse->json('message'));

        $webResponse = $this->webSearchDestinations($webUser, 'bandung');
        $this->assertContains($webResponse->getStatusCode(), [429, 503]);

        Http::assertNothingSent();
    }

    public function test_destination_search_validates_query_length_and_handles_upstream_500_cleanly(): void
    {
        $this->applyTier('starter')
            ->withCostOutage(500)
            ->apply();

        $product = $this->fixtures->createStockedProduct();
        $apiUser = $this->apiCustomerWithCart($product);
        ['user' => $webUser] = $this->webCustomerWithCart($product);

        $this->apiSearchDestinations($apiUser, 'a')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->webSearchDestinations($webUser, 'a')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->apiSearchDestinations($apiUser, 'bandung')
            ->assertStatus(502)
            ->assertJsonPath('data', []);

        $this->webSearchDestinations($webUser, 'bandung')
            ->assertStatus(502)
            ->assertJsonPath('data', []);
    }

    public function test_missing_destination_rejected_when_requires_destination_true_and_mirrored_to_legacy_key(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser, [
            'destination_id' => null,
            'destination_label' => null,
            'destination_driver' => null,
        ])->assertSessionHasErrors('destination_id');

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser, [
            'destination_id' => null,
            'destination_label' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destination_id', 'rajaongkir_destination_id']);
    }

    public function test_delivery_only_tier_requires_destination_and_rejects_missing_destination_id(): void
    {
        $this->applyTier('delivery_only');
        $product = $this->fixtures->createStockedProduct();
        $apiUser = $this->apiCustomerWithCart($product);

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_capabilities.destination_search', true)
            ->assertJsonPath('data.shipping_capabilities.requires_destination', true);

        $this->apiSaveShippingAddress($apiUser, [
            'destination_id' => null,
            'destination_label' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination_id');
    }

    public function test_stale_foreign_driver_destination_id_is_cleared_on_address_book_and_checkout(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();

        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $staleAddress = $this->fixtures->createSavedAddress($webUser, [
            'shipping_default' => true,
        ], [
            'destination_id' => 'BITESHIP-AREA-123',
            'destination_label' => 'Gambir (Biteship)',
            'destination_driver' => 'biteship',
        ]);

        // Account addresses Inertia page must clear foreign driver destination_id
        $this->webLoadAddresses($webUser)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $first = $page->toArray()['props']['addresses'][0] ?? [];
                $meta = is_string($first['metadata'] ?? null)
                    ? json_decode($first['metadata'], true)
                    : ($first['metadata'] ?? []);
                $this->assertNull($meta['destination_id'] ?? null);
            });

        // Web checkout savedAddresses clears stale id
        $this->webLoadCheckout($webUser)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('savedAddresses.0.destination_id', null)
            );

        // API checkout saved_addresses clears stale id & saved-address apply returns 422
        $apiUser = $this->apiCustomerWithCart($product);
        $apiStale = $this->fixtures->createSavedAddress($apiUser, [], [
            'destination_id' => 'BITESHIP-AREA-123',
            'destination_label' => 'Gambir (Biteship)',
            'destination_driver' => 'biteship',
        ]);

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.saved_addresses.0.destination_id', null);

        $this->apiApplySavedAddress($apiUser, $apiStale->id)
            ->assertStatus(422);
    }

    public function test_free_text_destination_id_and_invalid_pin_point_boundaries(): void
    {
        $product = $this->fixtures->createStockedProduct();

        // Starter requires real destination: 'text:...' is stripped to null and rejected (422)
        $this->applyTier('starter');
        $starterUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($starterUser, [
            'destination_id' => null,
            'rajaongkir_destination_id' => 'text:Jakarta 12190',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination_id');

        // Invalid pin_point rejected with 422
        $this->apiSaveShippingAddress($starterUser, [
            'pin_point' => 'not-a-coordinate',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin_point');

        // Valid pin_point succeeds
        $this->apiSaveShippingAddress($starterUser, [
            'pin_point' => '-6.238,106.783',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.pin_point', '-6.238,106.783');

        // Keyless strips 'text:...' to null and succeeds
        $this->applyTier('keyless');
        $keylessUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($keylessUser, [
            'destination_id' => null,
            'rajaongkir_destination_id' => 'text:Jakarta 12190',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', null);
    }
}
