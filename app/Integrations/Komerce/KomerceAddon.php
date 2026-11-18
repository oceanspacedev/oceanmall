<?php

declare(strict_types=1);

namespace App\Integrations\Komerce;

use App\Addons\Store\OrderFromRequest;
use App\Integrations\Komerce\Livewire\KomerceOrderShipping;
use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Livewire\Shopper\OrderCustomer;
use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use Livewire\Livewire;
use Shopper\Addon\BaseAddon;
use Shopper\Core\Models\Order;
use Shopper\ShopperPanel;
use Shopper\View\OrderRenderHook;

/**
 * Komerce / RajaOngkir admin UI: the delivery card (Store Order, pickup,
 * labels, tracking, payment sync) on orders that involve Komerce (see
 * rendersFor()). Enabled only while a Komerce product is configured
 * (Komerce::enabled()) and `shopper.addons.komerce` is not false, so a keyless
 * store shows no Komerce UI at all; generic store customisations live in the
 * always-on 'oceanmall-store' addon.
 */
final class KomerceAddon extends BaseAddon
{
    public const ID = 'komerce';

    public function getId(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return 'Komerce / RajaOngkir';
    }

    public function isEnabled(): bool
    {
        return parent::isEnabled() && Komerce::enabled();
    }

    public function register(ShopperPanel $panel): void
    {
        Livewire::component('komerce-order-shipping', KomerceOrderShipping::class);

        // The card shows the destination: Shopper's address block would repeat it.
        OrderCustomer::hideShippingAddressWhen(self::ID, static fn (mixed $order): bool => $order instanceof Order && self::rendersFor($order));

        $panel->renderHook(
            OrderRenderHook::DETAIL_MAIN_BEFORE,
            static function (): string {
                $order = OrderFromRequest::resolve();

                if (! $order instanceof Order || ! self::rendersFor($order)) {
                    return '';
                }

                return view('komerce::partials.order-shipping-hook', [
                    'order' => $order,
                ])->render();
            },
        );
    }

    /**
     * The order ships through Komerce: its shipments are fulfilled by Komerce
     * Delivery, or were quoted by a Komerce driver (RajaOngkir Cost on the
     * Starter plan, where staff enter the AWB by hand). Orders shipped with
     * a manual / zone carrier keep Shopper's own UI (payment sync stays in
     * the order header action). Delivery-only actions inside the card are
     * guarded by Komerce::shippingDeliveryEnabled(). Checked at render time
     * and only while the addon is enabled (some Komerce product configured).
     */
    public static function rendersFor(Order $order): bool
    {
        if (resolve(ShippingCapabilities::class)->fulfillerForOrder($order)?->code() === KomerceShippingDriver::CODE) {
            return true;
        }

        $shippingDrivers = [RajaOngkirDriver::CODE, KomerceShippingDriver::CODE];

        if (OrderShipment::query()->where('order_id', $order->id)->whereIn('driver', $shippingDrivers)->exists()) {
            return true;
        }

        $order->loadMissing('shippingOption.carrier');

        return in_array($order->shippingOption?->carrier?->driver, $shippingDrivers, true);
    }
}
