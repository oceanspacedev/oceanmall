<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier1;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\ShippingCostClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\Facades\Shipping;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F1CapabilityDetectionTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireModules('Komerce');
    }

    public function test_keyless_tier_reports_manual_store_and_renders_no_komerce_quota_badge(): void
    {
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();

        $this->assertFalse(Komerce::enabled());
        $this->assertFalse(Komerce::shippingCostEnabled());
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
        $this->assertFalse(Komerce::paymentEnabled());
        $this->assertFalse(Komerce::qrislyEnabled());

        $this->artisan('providers:status')
            ->assertSuccessful()
            ->expectsOutputToContain('none (Shopper manual)');

        $this->assertSame(0, Artisan::call('komerce:capabilities', ['--no-probe' => true, '--json' => true]));

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('starter', $payload['plan']);
        $this->assertFalse($payload['shipping_cost']['entitled']);

        $this->cpanelOperationalAlerts($admin)
            ->assertDontSee('Status Integrasi Eksternal')
            ->assertDontSee('Kuota Cek Ongkir Harian');

        Http::assertNothingSent();
    }

    public function test_starter_tier_probe_detects_cost_and_payment_when_delivery_returns_401(): void
    {
        $this->applyTier('komship');
        $this->applyTier('starter', [
            'komerce.shipping_delivery_api_key' => 'unentitled-delivery-key',
        ]);

        $this->assertSame(0, Artisan::call('komerce:capabilities', ['--json' => true]));

        $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('starter', $summary['plan']);
        $this->assertTrue($summary['shipping_cost']['entitled']);
        $this->assertSame(100, $summary['shipping_cost']['daily_quota']);
        $this->assertTrue($summary['products']['shipping_cost']['entitled']);
        $this->assertFalse($summary['products']['shipping_delivery']['entitled']);
        $this->assertTrue($summary['products']['payment']['entitled']);

        $this->assertTrue(Komerce::shippingCostEnabled());
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
        $this->assertTrue(Komerce::paymentEnabled());
    }

    public function test_komship_tier_probe_detects_full_entitlement_and_uncapped_daily_quota(): void
    {
        $this->applyTier('komship');

        $this->assertSame(0, Artisan::call('komerce:capabilities', ['--json' => true]));

        $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('komship', $summary['plan']);
        $this->assertNull($summary['shipping_cost']['daily_quota']);
        $this->assertTrue($summary['products']['shipping_cost']['entitled']);
        $this->assertTrue($summary['products']['shipping_delivery']['entitled']);
        $this->assertTrue($summary['products']['payment']['entitled']);
        $this->assertTrue($summary['products']['qrisly']['entitled']);

        $this->assertTrue(Komerce::shippingDeliveryEnabled());
        $this->assertTrue(Komerce::shippingCostEnabled());
        $this->assertTrue(Komerce::paymentEnabled());
        $this->assertTrue(Komerce::qrislyEnabled());
    }

    public function test_pro_tier_inferred_when_daily_cost_requests_exceed_starter_threshold(): void
    {
        $this->applyTier('starter', [
            'komerce.plan.quotas.starter' => 3,
            'komerce.plan.quotas.pro' => 20000,
        ]);

        $costClient = resolve(ShippingCostClient::class);
        foreach (['gambir', 'senayan', 'menteng', 'tebet'] as $query) {
            $costClient->searchDomestic($query, 5);
        }

        $this->assertSame(0, Artisan::call('komerce:capabilities', ['--no-probe' => true, '--json' => true]));

        $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('pro', $summary['plan']);
        $this->assertSame(20000, $summary['shipping_cost']['daily_quota']);
        $this->assertSame(4, $summary['shipping_cost']['requests_today']);
    }

    public function test_qrisly_only_and_delivery_only_tiers_report_expected_shopper_driver_readiness(): void
    {
        $this->applyTier('qrisly_only');
        $this->assertTrue(Komerce::qrislyEnabled());
        $this->assertFalse(Komerce::paymentEnabled());
        $this->assertTrue(Payment::isConfigured('komerce'));
        $this->assertFalse(Shipping::isConfigured('rajaongkir'));
        $this->assertFalse(Shipping::isConfigured('komerce'));

        $this->applyTier('delivery_only');
        $this->assertTrue(Komerce::shippingDeliveryEnabled());
        $this->assertFalse(Komerce::shippingCostEnabled());
        $this->assertTrue(Shipping::isConfigured('komerce'));
        $this->assertFalse(Shipping::isConfigured('rajaongkir'));
        $this->assertFalse(Payment::isConfigured('komerce'));
    }

    public function test_cpanel_operational_alerts_displays_shipping_cost_quota_progress_on_starter(): void
    {
        $this->applyTier('starter');
        $admin = $this->fixtures->createAdmin();

        $costClient = resolve(ShippingCostClient::class);
        $costClient->searchDomestic('gambir', 5);
        $costClient->searchDomestic('bandung', 5);

        $this->assertSame(2, resolve(KomerceCapabilities::class)->requestsToday('shipping_cost'));

        $this->cpanelOperationalAlerts($admin)
            ->assertSee('Status Integrasi Eksternal')
            ->assertSee('Kuota Cek Ongkir Harian')
            ->assertSee('2/100');
    }
}
