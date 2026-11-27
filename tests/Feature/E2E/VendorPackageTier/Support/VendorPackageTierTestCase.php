<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Support;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\KomerceDeliveryFulfillment;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\E2E\VendorPackageTier\Support\Concerns\InteractsWithAdminCpanel;
use Tests\Feature\E2E\VendorPackageTier\Support\Concerns\InteractsWithCustomerApi;
use Tests\Feature\E2E\VendorPackageTier\Support\Concerns\InteractsWithWebStorefront;
use Tests\Feature\E2E\VendorPackageTier\Support\Concerns\SignsPaymentWebhooks;
use Tests\TestCase;

/**
 * Shared E2E base class for OceanMall Komerce / RajaOngkir vendor package-tier verification.
 */
abstract class VendorPackageTierTestCase extends TestCase
{
    use InteractsWithAdminCpanel;
    use InteractsWithCustomerApi;
    use InteractsWithWebStorefront;
    use RefreshDatabase;
    use SignsPaymentWebhooks;

    protected StoreFixtureFactory $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireModules('Komerce');

        Http::preventStrayRequests();

        $manifest = public_path('build/manifest.json');
        $this->withHeader(
            'X-Inertia-Version',
            file_exists($manifest) ? (string) hash_file('xxh128', $manifest) : '',
        );

        $this->fixtures = $this->seedBaseFixtures();
    }

    protected function seedBaseFixtures(): StoreFixtureFactory
    {
        return StoreFixtureFactory::seedBaseStore();
    }

    /**
     * Configure one of the 7 vendor package/access tiers:
     *   'keyless' | 'starter' | 'delivery_only' | 'qrisly_only' |
     *   'payment_and_qrisly' | 'komship' | 'quota_exhausted'
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function applyTier(string $tier, array $overrides = [], bool $applyHttpFake = true): KomerceHttpFakeBuilder
    {
        $this->resetTierState();

        $baseConfig = [
            'komerce.enabled' => null,
            'komerce.payment_base_url' => KomerceHttpFakeBuilder::PAYMENT_BASE,
            'komerce.qrisly_base_url' => KomerceHttpFakeBuilder::QRISLY_BASE,
            'komerce.rajaongkir.cost_base_url' => KomerceHttpFakeBuilder::COST_BASE,
            'komerce.rajaongkir.delivery_base_url' => KomerceHttpFakeBuilder::DELIVERY_BASE,
            'komerce.pickup_time' => '10:00:00',
            'komerce.pickup_vehicle' => 'Motor',
            'komerce.couriers' => [],
            'komerce.sync.destination_id' => 17601,
            'komerce.sync.destination_pin_point' => '-6.1754,106.8272',
            'komerce.sync.weight_grams' => 1000,
            'komerce.sync.item_value' => 100000,
            'komerce.webhook_secret' => '',
            'komerce.payment_api_key' => '',
            'komerce.shipping_cost_api_key' => '',
            'komerce.shipping_delivery_api_key' => '',
            'komerce.qrisly_api_key' => '',
            'komerce.qrisly_qris_id' => '',
            'shopper.payment.drivers.komerce.enabled' => true,
            'shopper.payment.drivers.stripe.enabled' => false,
            'shopper.shipping.drivers.rajaongkir.enabled' => true,
            'shopper.shipping.drivers.komerce.enabled' => true,
            'shopper.payment.provider' => null,
            'shopper.shipping.provider' => null,
            'shopper.shipping.destination_driver' => null,
            'shopper.shipping.fallback_to_zone_options' => false,
            'shopper.shipping.combine_zone_options' => false,
        ];

        $tierConfig = match ($tier) {
            'keyless' => [
                'komerce.enabled' => null,
            ],
            'starter' => [
                'komerce.enabled' => true,
                'komerce.shipping_cost_api_key' => 'cost-key',
                'komerce.payment_api_key' => 'payment-key',
                'komerce.webhook_secret' => 'webhook-secret',
            ],
            'delivery_only' => [
                'komerce.enabled' => true,
                'komerce.shipping_delivery_api_key' => 'delivery-key',
            ],
            'qrisly_only' => [
                'komerce.enabled' => true,
                'komerce.qrisly_api_key' => 'qrisly-key',
                'komerce.qrisly_qris_id' => '18',
            ],
            'payment_and_qrisly' => [
                'komerce.enabled' => true,
                'komerce.payment_api_key' => 'payment-key',
                'komerce.qrisly_api_key' => 'qrisly-key',
                'komerce.qrisly_qris_id' => '18',
                'komerce.webhook_secret' => 'webhook-secret',
            ],
            'komship' => [
                'komerce.enabled' => true,
                'komerce.shipping_cost_api_key' => 'cost-key',
                'komerce.shipping_delivery_api_key' => 'delivery-key',
                'komerce.payment_api_key' => 'payment-key',
                'komerce.qrisly_api_key' => 'qrisly-key',
                'komerce.qrisly_qris_id' => '18',
                'komerce.webhook_secret' => 'webhook-secret',
            ],
            'quota_exhausted' => [
                'komerce.enabled' => true,
                'komerce.shipping_cost_api_key' => 'cost-key',
                'komerce.payment_api_key' => 'payment-key',
                'komerce.webhook_secret' => 'webhook-secret',
            ],
            default => throw new \InvalidArgumentException("Unknown vendor tier [{$tier}]."),
        };

        config(array_merge($baseConfig, $tierConfig, $overrides));

        if ($tier !== 'keyless') {
            $this->registerKomerceAddon();
        }

        if ($tier === 'quota_exhausted') {
            resolve(KomerceCapabilities::class)->observe(
                'shipping_cost',
                429,
                json_encode(['meta' => ['message' => 'Daily request limit exceeded', 'code' => 429]], JSON_THROW_ON_ERROR),
            );
        }

        $builder = KomerceHttpFakeBuilder::forTier($tier);

        if ($applyHttpFake) {
            $builder->apply();
        }

        return $builder;
    }

    /**
     * Reset KomerceCapabilities in-memory memo, scoped instances, and optionally caches
     * so mid-test tier switches or simulated multi-request transitions behave deterministically.
     */
    protected function resetTierState(bool $flushCache = true): void
    {
        if ($flushCache) {
            resolve(KomerceCapabilities::class)->forget();
            $this->app->forgetScopedInstances();
            Cache::flush();

            return;
        }

        // Preserve persisted komerce:capabilities:v1 across simulated requests/commands
        // while clearing the per-request scoped KomerceCapabilities in-memory memo.
        $this->app->forgetScopedInstances();
        Cache::forget('komerce:payment-methods');
        Cache::forget('komerce:payment-methods:unavailable');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postDeliveryWebhook(array $payload, string $method = 'POST'): TestResponse
    {
        return strtoupper($method) === 'PUT'
            ? $this->putJson('/webhooks/komerce/delivery', $payload)
            : $this->postJson('/webhooks/komerce/delivery', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postQrislyWebhook(array $payload): TestResponse
    {
        return $this->postJson('/webhooks/komerce/qrisly', $payload);
    }

    protected function observeVendorCapability(string $product, int $status, string $message = 'Invalid API key'): void
    {
        $body = ['meta' => ['message' => $message]];
        if ($status === 429) {
            $body['meta']['code'] = 429;
        }

        resolve(KomerceCapabilities::class)->observe(
            $product,
            $status,
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    protected function komerceProductEnabled(string $product): bool
    {
        return match ($product) {
            'enabled' => Komerce::enabled(),
            'payment' => Komerce::paymentEnabled(),
            'shipping_cost' => Komerce::shippingCostEnabled(),
            'shipping_delivery' => Komerce::shippingDeliveryEnabled(),
            'qrisly' => Komerce::qrislyEnabled(),
            default => throw new \InvalidArgumentException("Unknown Komerce product [{$product}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $values  Keys relative to `komerce.` (e.g. 'shipping_delivery_api_key')
     */
    protected function setVendorTierConfig(array $values): void
    {
        $prefixed = [];

        foreach ($values as $key => $value) {
            $prefixed[str_starts_with($key, 'komerce.') ? $key : "komerce.{$key}"] = $value;
        }

        config($prefixed);
    }

    protected function storeOrderPendingMetadataKey(): string
    {
        return 'komerce.'.KomerceDeliveryFulfillment::STORE_ORDER_PENDING;
    }
}
