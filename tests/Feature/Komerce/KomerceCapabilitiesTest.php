<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Shipping\ShippingQuoteContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Package;
use Tests\TestCase;

final class KomerceCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_top_level_message_envelopes_are_parsed_for_entitlement_and_quota(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 15:00:00', 'Asia/Jakarta'));
        $capabilities = resolve(KomerceCapabilities::class);

        $capabilities->observe('qrisly', 400, json_encode(['success' => false, 'message' => 'Invalid API Key']));
        $this->assertFalse($capabilities->entitled('qrisly'));
        $this->assertSame('Invalid API Key', $capabilities->reason('qrisly'));

        $capabilities->observe('payment', 400, json_encode(['success' => false, 'message' => 'Daily quota limit exceeded']));
        $this->assertTrue($capabilities->quotaExhausted('payment'));
        $this->assertSame('Daily quota limit exceeded', data_get($capabilities->state(), 'payment.quota_reason'));

        $capabilities->observe('shipping_cost', 429, json_encode(['message' => 'Too many requests today']));
        $this->assertTrue($capabilities->quotaExhausted('shipping_cost'));
        $this->assertSame('Too many requests today', data_get($capabilities->state(), 'shipping_cost.quota_reason'));

        CarbonImmutable::setTestNow();
    }

    public function test_probe_preserves_qrisly_not_entitled_verdict_within_ttl_until_expired_or_unconfigured(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 10:00:00'));
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', 'qris-123');
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');
        config()->set('komerce.payment_api_key', '');

        $capabilities = resolve(KomerceCapabilities::class);
        $capabilities->observe('qrisly', 401, json_encode(['success' => false, 'message' => 'Unauthorized QRISLY key']));
        $checkedAt = data_get($capabilities->state(), 'qrisly.checked_at');

        $capabilities->probe();

        $this->assertFalse($capabilities->entitled('qrisly'));
        $this->assertFalse(Komerce::qrislyEnabled());
        $this->assertSame('Unauthorized QRISLY key', $capabilities->reason('qrisly'));
        $this->assertSame($checkedAt, data_get($capabilities->state(), 'qrisly.checked_at'));

        // After the 24h TTL expires, probe() re-enables a configured QRISLY credential.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 11:00:00'));
        $capabilities->probe();

        $this->assertTrue($capabilities->entitled('qrisly'));
        $this->assertTrue(Komerce::qrislyEnabled());
        $this->assertNull($capabilities->reason('qrisly'));

        // Missing qris_id resets entitlement back to null.
        config()->set('komerce.qrisly_qris_id', '');
        $capabilities->probe();
        $this->assertNull(data_get($capabilities->state(), 'qrisly.entitled'));

        CarbonImmutable::setTestNow();
    }

    public function test_pre_probe_starter_with_unentitled_delivery_key_degrades_cleanly_on_checkout_and_sync(): void
    {
        Http::fake([
            'https://delivery.example.test/tariff/api/v1/calculate*' => Http::response(
                ['meta' => ['code' => 401, 'status' => 'failed', 'message' => 'Invalid API Key']],
                401,
            ),
            'https://cost.example.test/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['code' => 'jne', 'name' => 'JNE', 'service' => 'REG', 'cost' => 15000, 'etd' => '2-3'],
                ],
            ]),
        ]);

        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE Express', 'driver' => 'rajaongkir', 'is_enabled' => true]);
        Inventory::factory()->create([
            'is_default' => true,
            'rajaongkir_origin_id' => '17248',
            'latitude' => -6.7366,
            'longitude' => 108.5414,
        ]);

        $rates = resolve(FetchDeliveryRates::class)->handle([
            'country_id' => 1,
            'postal_code' => '10110',
            'destination_id' => '17601',
            'destination_driver' => 'rajaongkir',
            'pin_point' => '-6.1754,106.8272',
        ], [new Package(10, 10, 10, 1.0)], itemValue: 100000);

        $this->assertCount(1, $rates);
        $this->assertSame('jne:REG', $rates[0]['service_code']);
        $this->assertNull(resolve(ShippingQuoteContext::class)->lastFailure());
        $this->assertFalse(Komerce::shippingDeliveryEnabled());

        // Also test cold-cache carrier sync degradation in a fresh capabilities cache.
        resolve(KomerceCapabilities::class)->forget();
        Carrier::query()->where('slug', 'jne')->update(['is_enabled' => false]);
        config()->set('komerce.sync.destination_id', 17601);

        $this->artisan('shipping:sync-carriers')->assertSuccessful();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
    }

    public function test_403_quota_message_records_quota_exhaustion_without_disabling_entitlement(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 15:00:00', 'Asia/Jakarta'));
        $capabilities = resolve(KomerceCapabilities::class);

        $capabilities->observe('shipping_cost', 403, json_encode(['message' => 'Kuota harian habis']));

        $this->assertTrue($capabilities->entitled('shipping_cost'));
        $this->assertTrue(Komerce::shippingCostEnabled());
        $this->assertTrue($capabilities->quotaExhausted('shipping_cost'));
        $this->assertSame('2026-10-14 00:00', $capabilities->quotaExhaustedUntil('shipping_cost')?->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'));

        $capabilities->observe('shipping_delivery', 403, json_encode(['meta' => ['message' => 'Daily limit exceeded']]));

        $this->assertTrue($capabilities->entitled('shipping_delivery'));
        $this->assertTrue($capabilities->quotaExhausted('shipping_delivery'));

        // Non-quota 403 and 401 still disable entitlement.
        $capabilities->observe('payment', 403, json_encode(['message' => 'Access denied for this endpoint']));
        $this->assertFalse($capabilities->entitled('payment'));
        $this->assertFalse($capabilities->quotaExhausted('payment'));

        CarbonImmutable::setTestNow();
    }

    public function test_http_200_error_and_quota_envelopes_are_detected_in_observe_and_probe(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 15:00:00', 'Asia/Jakarta'));
        $capabilities = resolve(KomerceCapabilities::class);

        $capabilities->observe('shipping_cost', 200, json_encode([
            'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded'],
            'data' => [],
        ]));

        $this->assertTrue($capabilities->quotaExhausted('shipping_cost'));
        $this->assertSame(0, $capabilities->requestsToday('shipping_cost'));
        $this->assertNull(data_get($capabilities->state(), 'shipping_cost.entitled'));

        $capabilities->observe('payment', 200, json_encode([
            'meta' => ['code' => 401, 'status' => 'failed', 'message' => 'Invalid API Key'],
            'data' => [],
        ]));

        $this->assertFalse($capabilities->entitled('payment'));
        $this->assertSame(0, $capabilities->requestsToday('payment'));

        $capabilities->observe('qrisly', 200, json_encode([
            'success' => false,
            'message' => 'Invalid API Key',
        ]));

        $this->assertFalse($capabilities->entitled('qrisly'));
        $this->assertSame(0, $capabilities->requestsToday('qrisly'));

        // probe() also detects HTTP 200 error envelopes.
        $capabilities->forget();
        Http::fake([
            'https://cost.example.test/api/v1/destination/domestic-destination*' => Http::response([
                'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded'],
                'data' => [],
            ], 200),
            'https://delivery.example.test/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['code' => 401, 'status' => 'failed', 'message' => 'Invalid API Key'],
                'data' => [],
            ], 200),
            'https://payment.example.test/user/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [],
            ], 200),
        ]);

        $capabilities->probe();

        $this->assertTrue($capabilities->quotaExhausted('shipping_cost'));
        $this->assertSame(0, $capabilities->requestsToday('shipping_cost'));
        $this->assertFalse($capabilities->entitled('shipping_delivery'));
        $this->assertSame('starter', $capabilities->plan());
        $this->assertTrue($capabilities->entitled('payment'));

        CarbonImmutable::setTestNow();
    }
}
