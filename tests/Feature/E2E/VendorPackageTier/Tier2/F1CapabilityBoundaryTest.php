<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier2;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\ShippingCostClient;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use Carbon\CarbonImmutable;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F1CapabilityBoundaryTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireModules('Komerce');
    }

    public function test_http_400_with_top_level_or_meta_invalid_api_key_revokes_entitlement(): void
    {
        // Top-level {"message": "Invalid API key"} without meta.message
        $this->applyTier('komship')
            ->withDeliveryEntitled(false, 400, 'Invalid API key', topLevelMessage: true)
            ->apply();

        $this->assertTrue(Komerce::shippingDeliveryEnabled());

        try {
            resolve(ShippingDeliveryClient::class)->searchDestinations('Gambir');
        } catch (\Throwable) {
            // Expected client exception on 400
        }

        $capabilities = resolve(KomerceCapabilities::class);
        $this->assertFalse($capabilities->entitled('shipping_delivery'));
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
        $this->assertSame('Invalid API key', $capabilities->reason('shipping_delivery'));

        // Meta-wrapped {"meta": {"message": "Invalid API Key"}}
        $this->applyTier('komship')
            ->withDeliveryEntitled(false, 400, 'Invalid API Key', topLevelMessage: false)
            ->apply();

        try {
            resolve(ShippingDeliveryClient::class)->searchDestinations('Gambir');
        } catch (\Throwable) {
        }

        $capabilities = resolve(KomerceCapabilities::class);
        $this->assertFalse($capabilities->entitled('shipping_delivery'));
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
    }

    public function test_http_503_upstream_outage_does_not_revoke_product_entitlement(): void
    {
        $this->applyTier('komship')
            ->withDeliveryEntitled(false, 503, 'Service Unavailable')
            ->withPaymentOutage(503)
            ->apply();

        $this->artisan('komerce:capabilities')->assertSuccessful();

        $capabilities = resolve(KomerceCapabilities::class);
        $this->assertTrue($capabilities->entitled('shipping_delivery'));
        $this->assertTrue($capabilities->entitled('payment'));
        $this->assertTrue(Komerce::shippingDeliveryEnabled());
        $this->assertTrue(Komerce::paymentEnabled());
    }

    public function test_passive_429_sets_quota_exhaustion_until_next_wib_midnight_without_revoking_entitlement(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 23:50:00', 'Asia/Jakarta'));

        try {
            $this->applyTier('starter')
                ->withCostQuotaExhausted(429, 'Daily request limit exceeded')
                ->apply();

            try {
                resolve(ShippingCostClient::class)->searchDomestic('gambir');
            } catch (\Throwable) {
            }

            $capabilities = resolve(KomerceCapabilities::class);
            $this->assertTrue($capabilities->quotaExhausted('shipping_cost'));
            $this->assertTrue($capabilities->entitled('shipping_cost'));
            $this->assertTrue(Komerce::shippingCostEnabled());

            // Advance 15 minutes past WIB midnight -> quota resets automatically
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 00:05:00', 'Asia/Jakarta'));
            $this->resetTierState(flushCache: false);
            $this->assertFalse(resolve(KomerceCapabilities::class)->quotaExhausted('shipping_cost'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_qrisly_revoked_state_is_preserved_across_capabilities_probe(): void
    {
        $this->applyTier('payment_and_qrisly')
            ->withQrislyEntitled(false, 401, 'Invalid API Key')
            ->apply();

        resolve(KomerceCapabilities::class)->observe(
            'qrisly',
            401,
            json_encode(['meta' => ['message' => 'Invalid API Key']], JSON_THROW_ON_ERROR),
        );

        $this->assertFalse(resolve(KomerceCapabilities::class)->entitled('qrisly'));
        $this->assertFalse(Komerce::qrislyEnabled());

        // Active probe (which has no lightweight read endpoint for qrisly) must NOT wipe out revoked state
        $this->artisan('komerce:capabilities')->assertSuccessful();

        $this->resetTierState(flushCache: false);
        // Re-read persisted capabilities from cache
        $fresh = new KomerceCapabilities;
        $this->assertFalse($fresh->entitled('qrisly'));
        $this->assertFalse(Komerce::qrislyEnabled());
    }

    public function test_entitlement_verdict_expires_after_24h_ttl_to_detect_plan_upgrade(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 10:00:00'));

        try {
            $this->applyTier('komship');
            $capabilities = resolve(KomerceCapabilities::class);
            $capabilities->observe(
                'shipping_delivery',
                401,
                json_encode(['meta' => ['message' => 'Invalid API Key']], JSON_THROW_ON_ERROR),
            );
            $this->assertFalse($capabilities->entitled('shipping_delivery'));

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 11:00:00'));
            $this->assertTrue($capabilities->entitled('shipping_delivery'));
            $this->assertTrue(Komerce::shippingDeliveryEnabled());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_concurrent_capability_writes_do_not_erase_other_product_verdicts(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 15:00:00', 'Asia/Jakarta'));

        try {
            $this->applyTier('komship');

            $workerA = new KomerceCapabilities;
            $this->assertFalse($workerA->quotaExhausted('shipping_cost'));
            $this->assertTrue($workerA->entitled('payment'));

            $workerB = new KomerceCapabilities;
            $workerB->observe('shipping_cost', 429, json_encode(['meta' => ['message' => 'Daily request limit exceeded']], JSON_THROW_ON_ERROR));
            $workerB->observe('payment', 401, json_encode(['meta' => ['message' => 'Invalid API Key']], JSON_THROW_ON_ERROR));

            // Stale workerA now writes a 200 on shipping_delivery
            $workerA->observe('shipping_delivery', 200);

            $fresh = new KomerceCapabilities;
            $this->assertTrue($fresh->quotaExhausted('shipping_cost'));
            $this->assertFalse($fresh->entitled('payment'));
            $this->assertTrue($fresh->entitled('shipping_delivery'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
