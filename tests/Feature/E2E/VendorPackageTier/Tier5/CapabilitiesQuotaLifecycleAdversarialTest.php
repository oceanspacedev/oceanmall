<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Account\ConfirmOrderReceived;
use App\Integrations\Komerce\Services\ShippingCostClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Models\OrderShipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class CapabilitiesQuotaLifecycleAdversarialTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModules('Komerce');
    }

    public function test_komerce_capabilities_clears_stale_quota_exhausted_after_200_probe_while_preserving_concurrent_passive_429(): void
    {
        $this->applyTier('quota_exhausted');

        $caps = resolve(KomerceCapabilities::class);
        $this->assertTrue($caps->quotaExhausted('shipping_cost'));
        $this->assertNotNull($caps->quotaExhaustedUntil('shipping_cost'));

        // Passive in-flight 200 observation must NOT erase a concurrent worker's 429 verdict.
        $caps->observe('shipping_cost', 200, json_encode([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $this->assertTrue($caps->quotaExhausted('shipping_cost'));

        // Active probe (komerce:capabilities) getting 200 after plan upgrade / limit lift clears stale quota state.
        $this->applyTier('starter', [], false)
            ->withCostDefaults()
            ->apply();
        $caps = resolve(KomerceCapabilities::class);
        $caps->observe('shipping_cost', 429, json_encode(['meta' => ['message' => 'Daily limit exceeded']], JSON_THROW_ON_ERROR));
        $this->assertTrue($caps->quotaExhausted('shipping_cost'));

        $this->artisan('komerce:capabilities')->assertSuccessful();

        $this->assertFalse($caps->quotaExhausted('shipping_cost'));
        $this->assertNull($caps->quotaExhaustedUntil('shipping_cost'));
        $this->assertNull(data_get($caps->state(), 'shipping_cost.quota_reason'));
    }

    public function test_komerce_capabilities_wib_midnight_quota_rollover_resets_exhaustion_and_daily_counter(): void
    {
        $this->applyTier('starter');
        $caps = resolve(KomerceCapabilities::class);

        $beforeMidnight = CarbonImmutable::parse('2026-11-26 23:59:50', 'Asia/Jakarta');
        CarbonImmutable::setTestNow($beforeMidnight);

        try {
            $caps->observe('shipping_cost', 200, '{"meta":{"code":200,"status":"success"},"data":[]}');
            $this->assertSame(1, $caps->requestsToday('shipping_cost'));

            $caps->observe('shipping_cost', 429, '{"meta":{"code":429,"status":"error","message":"Daily limit exceeded"}}');
            $this->assertTrue($caps->quotaExhausted('shipping_cost'));
            $this->assertSame(
                '2026-11-27T00:00:00+07:00',
                $caps->quotaExhaustedUntil('shipping_cost')?->setTimezone('Asia/Jakarta')->toIso8601String(),
            );

            // Advance past WIB midnight (Asia/Jakarta 00:00:05)
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 00:00:05', 'Asia/Jakarta'));

            $this->assertFalse($caps->quotaExhausted('shipping_cost'));
            $this->assertSame(0, $caps->requestsToday('shipping_cost'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_http_200_quota_envelope_passively_marks_shipping_cost_exhausted_for_subsequent_checkout(): void
    {
        $this->applyTier('starter')
            ->withRoute(
                KomerceHttpFakeBuilder::COST_BASE.'/api/v1/calculate/domestic-cost*',
                Http::response([
                    'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Daily limit exceeded'],
                    'data' => [],
                ], 200),
            )
            ->apply();

        try {
            resolve(ShippingCostClient::class)->calculate(['id' => 17248], ['id' => 17601], 1000, ['jne']);
            $this->fail('Expected RuntimeException on HTTP 200 quota envelope.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Daily limit exceeded', $e->getMessage());
        }

        $this->assertTrue(resolve(KomerceCapabilities::class)->quotaExhausted('shipping_cost'));
    }

    public function test_confirm_order_received_deterministically_rejects_unpaid_shipped_and_unfulfilled_orders(): void
    {
        $this->applyTier('starter');
        $customer = $this->fixtures->createVerifiedCustomer();

        foreach ([ShippingStatus::Shipped, ShippingStatus::Unfulfilled] as $shippingStatus) {
            ['order' => $order] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'status' => OrderStatus::Processing,
                    'payment_status' => PaymentStatus::Pending,
                    'shipping_status' => $shippingStatus,
                ],
                [
                    'status' => 'labeled',
                    'awb' => 'JNE-UNPAID-CONF-'.$shippingStatus->value,
                    'tracking_number' => 'JNE-UNPAID-CONF-'.$shippingStatus->value,
                ],
            );

            $this->assertInstanceOf(OrderShipment::class, OrderShipment::query()->where('order_id', $order->id)->first());

            try {
                resolve(ConfirmOrderReceived::class)->handle($order->fresh());
                $this->fail("Expected ValidationException for unpaid order with shipping_status [{$shippingStatus->value}].");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
