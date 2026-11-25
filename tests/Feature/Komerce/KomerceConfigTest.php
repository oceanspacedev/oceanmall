<?php

namespace Tests\Feature\Komerce;

use App\Integrations\Komerce\Komerce;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class KomerceConfigTest extends TestCase
{
    public function test_legacy_general_api_key_is_not_a_service_fallback()
    {
        Config::set('komerce.api_key', 'general-secret-key');
        Config::set('komerce.payment_api_key', '');
        Config::set('komerce.shipping_cost_api_key', '');
        Config::set('komerce.shipping_delivery_api_key', '');

        $this->assertFalse(Komerce::paymentEnabled());
        $this->assertFalse(Komerce::shippingCostEnabled());
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
        $this->assertFalse(Komerce::enabled());
    }

    public function test_komerce_enabled_helper_evaluates_correctly()
    {
        Config::set('komerce.enabled', null);
        Config::set('komerce.payment_api_key', 'some-key');

        $this->assertTrue(Komerce::enabled());

        Config::set('komerce.payment_api_key', '');
        Config::set('komerce.shipping_cost_api_key', '');
        $this->assertFalse(Komerce::enabled());
    }

    public function test_service_readiness_is_isolated_by_dedicated_key(): void
    {
        Config::set('komerce.enabled', null);
        Config::set('komerce.shipping_cost_api_key', 'cost-key');

        $this->assertTrue(Komerce::shippingCostEnabled());
        $this->assertFalse(Komerce::paymentEnabled());
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
        $this->assertTrue(Komerce::enabled());
    }

    public function test_qrisly_enabled_helper_evaluates_correctly()
    {
        Config::set('komerce.qrisly_api_key', 'qris-api-key');
        Config::set('komerce.qrisly_qris_id', 'qris-id-123');

        $this->assertTrue(Komerce::qrislyEnabled());

        Config::set('komerce.qrisly_qris_id', '');

        $this->assertFalse(Komerce::qrislyEnabled());
    }
}
