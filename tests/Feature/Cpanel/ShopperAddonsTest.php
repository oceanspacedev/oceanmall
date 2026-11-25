<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use App\Addons\Store\OceanMallStoreAddon;
use App\Integrations\Komerce\KomerceAddon;
use App\Integrations\Komerce\Livewire\KomerceOrderShipping;
use App\Livewire\Shopper\InventoryForm;
use App\Livewire\Shopper\OrderAllocationOverride;
use App\Livewire\Shopper\OrderCustomer;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\OrderSummary;
use App\Livewire\Shopper\Pages\OrderDetail;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Livewire\Shopper\SlideOvers\ShipmentAddEvent;
use Livewire\Mechanisms\ComponentRegistry;
use Shopper\Facades\Shopper;
use Tests\TestCase;

/**
 * Two addons: the generic, always-on store addon and the Komerce addon that
 * only exists while a Komerce product is configured.
 */
final class ShopperAddonsTest extends TestCase
{
    public function test_store_addon_registers_the_generic_panel_components(): void
    {
        $this->assertTrue(Shopper::hasAddon(OceanMallStoreAddon::ID));
        $this->assertInstanceOf(OceanMallStoreAddon::class, Shopper::getAddon(OceanMallStoreAddon::ID));

        $registry = app(ComponentRegistry::class);

        $this->assertSame(InventoryForm::class, $registry->getClass('shopper-settings.locations.form'));
        $this->assertSame(OrderSummary::class, $registry->getClass('shopper-order-summary'));
        $this->assertSame(OrderCustomer::class, $registry->getClass('shopper-order-customer'));
        $this->assertSame(OrderFulfillment::class, $registry->getClass('shopper-order-fulfillment'));
        $this->assertSame(OrderShipments::class, $registry->getClass('shopper-order-shipments'));
        $this->assertSame(CreateShippingLabel::class, $registry->getClass('shopper-slide-overs.create-shipping-label'));
        $this->assertSame(ShipmentAddEvent::class, $registry->getClass('shopper-slide-overs.shipment-add-event'));
        $this->assertSame(OrderAllocationOverride::class, $registry->getClass('order-allocation-override'));
        $this->assertSame(OrderShipments::class, config('shopper.components.order.pages.order-shipments'));
        $this->assertSame(OrderDetail::class, config('shopper.components.order.pages.order-detail'));
    }

    public function test_komerce_addon_is_off_without_a_komerce_key(): void
    {
        $this->requireModules('Komerce');

        $this->assertFalse((new KomerceAddon)->isEnabled());
    }

    public function test_komerce_addon_turns_on_with_any_komerce_product_and_obeys_the_shopper_switch(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        $this->assertTrue((new KomerceAddon)->isEnabled());

        config()->set('shopper.addons.komerce', false);
        $this->assertFalse((new KomerceAddon)->isEnabled(), 'shopper.addons.komerce = false removes the provider UI.');

        config()->set('shopper.addons.komerce', true);
        config()->set('komerce.enabled', false);
        $this->assertFalse((new KomerceAddon)->isEnabled(), 'KOMERCE_ENABLED=false is the master switch.');
    }

    public function test_komerce_addon_registers_its_order_card_once_enabled(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        $this->registerKomerceAddon();

        $this->assertTrue(Shopper::hasAddon(KomerceAddon::ID));
        $this->assertSame('Komerce / RajaOngkir', Shopper::getAddon(KomerceAddon::ID)->getName());
        $this->assertSame(KomerceOrderShipping::class, app(ComponentRegistry::class)->getClass('komerce-order-shipping'));
    }
}
