<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier4;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Scenario6QuotaExhaustedCostTierTest extends VendorPackageTierTestCase
{
    public function test_quota_exhausted_cost_tier_across_checkout_destinations_tracking_and_zone_fallback(): void
    {
        $this->applyTier('starter');

        $product = $this->fixtures->createStockedProduct(100000, 10, 500);
        ['user' => $webUser] = $this->webCustomerWithCart($product, 1, 100000);
        $apiUser = $this->apiCustomerWithCart($product, 1);

        // 1. Warm 'gambir' destination cache and seed in-flight shipment with prior tracking history
        $this->apiSearchDestinations($apiUser, 'gambir')->assertOk()->assertJsonPath('data.0.id', '17601');

        $existingEvent = [
            'description' => 'Prior manifest checkpoint',
            'datetime' => '2026-11-24 08:00',
            'location' => 'Jakarta',
        ];
        ['order' => $inFlightOrder, 'shipment' => $inFlightShipment] = $this->fixtures->createOrderWithShipment(
            $apiUser,
            [],
            [
                'awb' => 'JNE-QUOTA-HIST-1',
                'tracking_number' => 'JNE-QUOTA-HIST-1',
                'status' => 'in_transit',
                'metadata' => [
                    'tracking_history' => [$existingEvent],
                    'komerce' => ['tracking_history' => [$existingEvent]],
                ],
            ],
        );

        // 2. Upstream Shipping Cost starts returning 429 (without flushing 'gambir' cache)
        KomerceHttpFakeBuilder::forTier('quota_exhausted')->apply();

        // First-429 Web checkout must immediately classify as QUOTA with WIB hint (M1 #3)
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $props = $page->toArray()['props'];
                $this->assertSame([], $props['deliveryOptions']);
                $this->assertStringContainsString('Batas cek ongkir harian', (string) ($props['shippingRatesHint'] ?? ''));
                $this->assertStringContainsString('WIB', (string) ($props['shippingRatesHint'] ?? ''));
            });

        // Subsequent API checkout sends ZERO Cost requests and exposes shipping_rates_hint & shipping_rates_failure (M4 #14)
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();

        $apiCheckout = $this->apiLoadCheckout($apiUser)->assertOk();
        $this->assertSame([], $apiCheckout->json('data.shipping_rates'));
        $this->assertStringContainsString('Batas cek ongkir harian', (string) $apiCheckout->json('data.shipping_rates_hint'));
        $this->assertSame('quota', $apiCheckout->json('data.shipping_rates_failure.reason'));
        $this->assertNotEmpty($apiCheckout->json('data.shipping_rates_failure.retry_at_wib'));
        Http::assertNothingSent();

        // 3. Cached 'gambir' destination returns 200; uncached 'bandung' returns 429/503 Indonesian quota error (M1 #4 + M4 #14)
        $this->apiSearchDestinations($apiUser, 'gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601');

        $uncachedDest = $this->apiSearchDestinations($apiUser, 'bandung');
        $this->assertContains($uncachedDest->getStatusCode(), [429, 503]);
        $this->assertMatchesRegularExpression('/kuota|batas/i', (string) $uncachedDest->json('message'));
        Http::assertNothingSent();

        // 4. Customer tracking under exhausted quota preserves prior history, returns 422 Indonesian error, skips cooldown
        $trackRes = $this->apiTrackShipment($apiUser, $inFlightOrder->number, $inFlightShipment->id)
            ->assertStatus(422);
        $this->assertMatchesRegularExpression('/kuota|batas|gagal/i', (string) $trackRes->json('message'));
        $this->assertFalse(Cache::has('shipment-track-cooldown:'.$inFlightShipment->id));
        $this->assertSame(
            'Prior manifest checkpoint',
            data_get($inFlightShipment->fresh()->metadata, 'komerce.tracking_history.0.description'),
        );
        Http::assertNothingSent();

        // 5. Enabling SHIPPING_FALLBACK_TO_ZONE_OPTIONS lets Web customer complete zone-fallback purchase cleanly
        config()->set('shopper.shipping.fallback_to_zone_options', true);

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'manual')
                ->where('deliveryOptions.0.amount', 15000)
                ->where('shippingRatesHint', null)
            );

        $this->webPickShippingOption($webUser, $this->fixtures->manualOption->id)
            ->assertRedirect(route('shop.checkout.index'));
        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['manual']->id)
            ->assertRedirect();

        $fallbackOrder = Order::query()->where('customer_id', $webUser->id)->latest('id')->firstOrFail();
        $this->assertSame(OrderStatus::New, $fallbackOrder->status);
        $this->assertSame(115000, (int) $fallbackOrder->price_amount);
    }
}
