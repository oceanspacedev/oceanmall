<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier3;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class QuotaZoneFallbackAndDestinationCacheTest extends VendorPackageTierTestCase
{
    public function test_cost_quota_exhaustion_with_zone_fallback_and_combine_flags_on_web_and_api(): void
    {
        $this->applyTier('starter')
            ->withCostQuotaExhausted(429, 'Daily request limit exceeded')
            ->apply();

        $product = $this->fixtures->createStockedProduct(100000, 10, 500);
        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));

        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();

        // 1. Both flags false -> empty rates + Indonesian WIB quota hint on Web & API (M1 #3 + M4 #14)
        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $props = $page->toArray()['props'];
                $this->assertSame([], $props['deliveryOptions']);
                $this->assertStringContainsString('Batas cek ongkir harian', (string) ($props['shippingRatesHint'] ?? ''));
                $this->assertStringContainsString('WIB', (string) ($props['shippingRatesHint'] ?? ''));
            });

        $apiEmpty = $this->apiLoadCheckout($apiUser)->assertOk();
        $this->assertSame([], $apiEmpty->json('data.shipping_rates'));
        $this->assertStringContainsString('Batas cek ongkir harian', (string) $apiEmpty->json('data.shipping_rates_hint'));
        $this->assertSame('quota', $apiEmpty->json('data.shipping_rates_failure.reason'));

        // 2. SHIPPING_FALLBACK_TO_ZONE_OPTIONS=true -> manual zone option offered, shippingRatesHint === null
        config()->set('shopper.shipping.fallback_to_zone_options', true);
        config()->set('shopper.shipping.combine_zone_options', false);

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'manual')
                ->where('deliveryOptions.0.amount', 15000)
                ->where('shippingRatesHint', null)
            );

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_rates.0.driver', 'manual')
            ->assertJsonPath('data.shipping_rates.0.amount', 15000)
            ->assertJsonPath('data.shipping_rates_hint', null);

        // 3. SHIPPING_COMBINE_ZONE_OPTIONS=true (with fallback=false) -> manual zone option still offered under quota exhaustion
        config()->set('shopper.shipping.fallback_to_zone_options', false);
        config()->set('shopper.shipping.combine_zone_options', true);

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'manual')
                ->where('deliveryOptions.0.amount', 15000)
                ->where('shippingRatesHint', null)
            );

        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_rates.0.driver', 'manual')
            ->assertJsonPath('data.shipping_rates.0.amount', 15000)
            ->assertJsonPath('data.shipping_rates_hint', null);
    }

    public function test_destination_30_day_cache_hit_survives_quota_while_uncached_query_returns_429_or_503_on_web_and_api(): void
    {
        $this->applyTier('starter');
        $product = $this->fixtures->createStockedProduct();
        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $apiUser = $this->apiCustomerWithCart($product);

        // Warm 30-day destination cache for 'gambir' across Web & API
        $this->webSearchDestinations($webUser, 'gambir')->assertOk()->assertJsonPath('data.0.id', '17601');
        $this->apiSearchDestinations($apiUser, 'gambir')->assertOk()->assertJsonPath('data.0.id', '17601');

        // Exhaust Shipping Cost quota without clearing destination cache
        $this->observeVendorCapability('shipping_cost', 429, 'Daily request limit exceeded');
        KomerceHttpFakeBuilder::forTier('quota_exhausted')->apply();

        // Cached 'gambir' returns 200 on both Web & API with zero upstream calls
        $this->webSearchDestinations($webUser, 'gambir')->assertOk()->assertJsonPath('data.0.id', '17601');
        $this->apiSearchDestinations($apiUser, 'gambir')->assertOk()->assertJsonPath('data.0.id', '17601');
        Http::assertNothingSent();

        // Uncached 'bandung' must surface 429/503 Indonesian quota error on Web & API (M1 #4 + M4 #14)
        $webQuotaRes = $this->webSearchDestinations($webUser, 'bandung');
        $this->assertContains($webQuotaRes->getStatusCode(), [429, 503]);
        $this->assertMatchesRegularExpression('/kuota|batas/i', (string) $webQuotaRes->json('message'));

        $apiQuotaRes = $this->apiSearchDestinations($apiUser, 'bandung');
        $this->assertContains($apiQuotaRes->getStatusCode(), [429, 503]);
        $this->assertMatchesRegularExpression('/kuota|batas/i', (string) $apiQuotaRes->json('message'));

        Http::assertNothingSent();
    }
}
