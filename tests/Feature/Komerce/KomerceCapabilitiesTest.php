<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KomerceCapabilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://cost.example.test');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        Http::preventStrayRequests();
    }

    public function test_starter_account_is_detected_when_delivery_rejects_the_key(): void
    {
        Http::fake([
            'https://cost.example.test/api/v1/destination/domestic-destination*' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => [['id' => 17601]]]),
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['meta' => ['message' => 'Invalid API Key', 'code' => 401, 'status' => 'failed'], 'data' => []], 401),
            'https://payment.example.test/user/api/v1/user/methods' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => []]),
        ]);

        $this->artisan('komerce:capabilities')->assertSuccessful();

        $capabilities = resolve(KomerceCapabilities::class);

        $this->assertTrue($capabilities->entitled('shipping_cost'));
        $this->assertFalse($capabilities->entitled('shipping_delivery'));
        $this->assertSame('Invalid API Key', $capabilities->reason('shipping_delivery'));
        $this->assertTrue($capabilities->entitled('payment'));
        $this->assertSame('starter', $capabilities->plan());
        $this->assertSame(100, $capabilities->dailyQuota());

        // The product helpers follow the detected entitlement even though a key is configured.
        $this->assertTrue(Komerce::shippingCostEnabled());
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
        $this->assertTrue(Komerce::paymentEnabled());
    }

    public function test_komship_account_is_detected_when_delivery_accepts_the_key(): void
    {
        Http::fake([
            'https://cost.example.test/*' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => []]),
            'https://delivery.example.test/*' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => []]),
            'https://payment.example.test/*' => Http::response(['meta' => ['code' => 200, 'status' => 'success'], 'data' => []]),
        ]);

        resolve(KomerceCapabilities::class)->probe();

        $this->assertSame('komship', resolve(KomerceCapabilities::class)->plan());
        $this->assertNull(resolve(KomerceCapabilities::class)->dailyQuota());
        $this->assertTrue(Komerce::shippingDeliveryEnabled());
    }

    public function test_real_api_calls_are_observed_passively_so_a_rejected_product_switches_off(): void
    {
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response(['meta' => ['message' => 'Invalid API Key', 'code' => 401, 'status' => 'failed'], 'data' => []], 401),
        ]);

        $this->assertTrue(Komerce::shippingDeliveryEnabled());

        try {
            resolve(ShippingDeliveryClient::class)->searchDestinations('Gambir');
        } catch (\Throwable) {
            // The client throws on 401; the observer must have recorded it anyway.
        }

        $this->assertFalse(Komerce::shippingDeliveryEnabled());
    }

    public function test_quota_exhaustion_is_remembered_until_the_next_wib_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 15:00:00', 'Asia/Jakarta'));
        $capabilities = resolve(KomerceCapabilities::class);

        $capabilities->observe('shipping_cost', 200);
        $capabilities->observe('shipping_cost', 200);
        $this->assertSame(2, $capabilities->requestsToday());
        $this->assertFalse($capabilities->quotaExhausted());

        $capabilities->observe('shipping_cost', 429, json_encode(['meta' => ['message' => 'Daily request limit exceeded']]));

        $this->assertTrue($capabilities->quotaExhausted());
        $this->assertSame('2026-10-14 00:00', $capabilities->quotaExhaustedUntil()?->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'));
        // Being rate-limited is not "not entitled": the product stays enabled.
        $this->assertTrue(Komerce::shippingCostEnabled());

        CarbonImmutable::setTestNow();
    }

    public function test_a_concurrent_verdict_is_not_erased_by_a_stale_memo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 15:00:00', 'Asia/Jakarta'));

        // Request A reads (and memoises) the plan state at the start of a
        // long checkout...
        $requestA = new KomerceCapabilities;
        $this->assertFalse($requestA->quotaExhausted());
        $this->assertTrue($requestA->entitled('payment'));

        // ...meanwhile request B (another worker) learns the quota is gone
        // and the payment key was rejected.
        $requestB = new KomerceCapabilities;
        $requestB->observe('shipping_cost', 429, json_encode(['meta' => ['message' => 'Daily request limit exceeded']]));
        $requestB->observe('payment', 401, json_encode(['meta' => ['message' => 'Invalid API Key']]));

        // Then A gets a 2xx on both products and a probe error.
        $requestA->observe('shipping_cost', 200);
        $requestA->observe('shipping_delivery', 200);

        $fresh = new KomerceCapabilities;
        $this->assertTrue($fresh->quotaExhausted());
        $this->assertFalse($fresh->entitled('payment'));
        $this->assertTrue($fresh->entitled('shipping_delivery'));
        $this->assertSame(1, $fresh->requestsToday());
        // A's own memo is refreshed by its write.
        $this->assertTrue($requestA->quotaExhausted());

        CarbonImmutable::setTestNow();
    }

    public function test_more_than_the_starter_quota_in_one_day_infers_the_pro_tier(): void
    {
        config()->set('komerce.plan.quotas.starter', 3);
        $capabilities = resolve(KomerceCapabilities::class);

        foreach (range(1, 4) as $i) {
            $capabilities->observe('shipping_cost', 200);
        }

        $this->assertSame('pro', $capabilities->plan());
        $this->assertSame(20000, $capabilities->dailyQuota());
    }

    public function test_entitlement_verdicts_expire_so_an_upgraded_plan_is_picked_up_again(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 10:00:00'));
        $capabilities = resolve(KomerceCapabilities::class);
        $capabilities->observe('shipping_delivery', 401, json_encode(['meta' => ['message' => 'Invalid API Key']]));
        $this->assertFalse($capabilities->entitled('shipping_delivery'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00'));
        $this->assertTrue($capabilities->entitled('shipping_delivery'));

        CarbonImmutable::setTestNow();
    }
}
