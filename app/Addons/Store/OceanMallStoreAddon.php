<?php

declare(strict_types=1);

namespace App\Addons\Store;

use App\Livewire\Shopper\InventoryForm;
use App\Livewire\Shopper\OrderAllocationOverride;
use App\Livewire\Shopper\OrderCustomer;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\OrderSummary;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Livewire\Shopper\SlideOvers\ShipmentAddEvent;
use Livewire\Livewire;
use Shopper\Addon\BaseAddon;
use Shopper\Core\Models\Order;
use Shopper\ShopperPanel;
use Shopper\View\OrderRenderHook;

/**
 * Always-on OceanMall store customisations of the Shopper admin. Nothing here
 * names a provider: each override decides per order from the order's own
 * payment / shipping drivers (App\Payment\PaymentActions,
 * App\Shipping\ShippingCapabilities) and falls back to Shopper's stock
 * behaviour for manual methods and carriers.
 *
 * - order summary (multi-shipment shipping cost, tax inclusivity),
 * - order customer (keeps Shopper's address unless a provider panel shows it),
 * - fulfillment card / create-label / add-event slide-overs (provider AWB for
 *   provider-fulfilled orders, Shopper's own flow otherwise),
 * - location form (coordinates + origin IDs for configured providers),
 * - multi-warehouse allocation override card.
 *
 * Provider UI lives in that provider's own addon (e.g. 'komerce').
 */
final class OceanMallStoreAddon extends BaseAddon
{
    public const ID = 'oceanmall-store';

    public function getId(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return 'OceanMall Store';
    }

    public function register(ShopperPanel $panel): void
    {
        $panel->addonLivewireComponents([
            'settings.locations.form' => InventoryForm::class,
            'order-summary' => OrderSummary::class,
            'order-customer' => OrderCustomer::class,
            'order-fulfillment' => OrderFulfillment::class,
            'slide-overs.create-shipping-label' => CreateShippingLabel::class,
            'slide-overs.shipment-add-event' => ShipmentAddEvent::class,
        ]);

        Livewire::component('order-allocation-override', OrderAllocationOverride::class);

        $panel->renderHook(
            OrderRenderHook::DETAIL_MAIN_BEFORE,
            static function (): string {
                $order = OrderFromRequest::resolve();

                if (! $order instanceof Order) {
                    return '';
                }

                return view('shopper.partials.order-allocation-override-hook', [
                    'order' => $order,
                ])->render();
            },
        );
    }
}
