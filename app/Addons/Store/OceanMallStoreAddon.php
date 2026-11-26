<?php

declare(strict_types=1);

namespace App\Addons\Store;

use App\Livewire\Shopper\Account\TwoFactor;
use App\Livewire\Shopper\Dashboard\OperationalAlerts;
use App\Livewire\Shopper\Dashboard\RecentOrders;
use App\Livewire\Shopper\Dashboard\RevenueChart;
use App\Livewire\Shopper\Dashboard\StatCards;
use App\Livewire\Shopper\Dashboard\TopSellingProducts;
use App\Livewire\Shopper\InventoryForm;
use App\Livewire\Shopper\OrderAllocationOverride;
use App\Livewire\Shopper\OrderCustomer;
use App\Livewire\Shopper\OrderFinanceOpsPanel;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\OrderNotes;
use App\Livewire\Shopper\OrderPaymentTransactions;
use App\Livewire\Shopper\OrderSummary;
use App\Livewire\Shopper\Pages\AllocationOverrideLogs;
use App\Livewire\Shopper\Pages\FinanceReports;
use App\Livewire\Shopper\Pages\ManualPaymentProofs;
use App\Livewire\Shopper\Pages\OrderReturns;
use App\Livewire\Shopper\Pages\PaymentDisputes;
use App\Livewire\Shopper\Products;
use App\Livewire\Shopper\Settings\TeamPermissions;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Livewire\Shopper\SlideOvers\CreateTeamMember;
use App\Livewire\Shopper\SlideOvers\ReviewDetail;
use App\Livewire\Shopper\SlideOvers\ShipmentAddEvent;
use Illuminate\Support\Facades\Route;
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
            'slide-overs.create-team-member' => CreateTeamMember::class,
            'settings.team.permissions' => TeamPermissions::class,
            'account.two-factor' => TwoFactor::class,
            'products.form.inventory' => Products\Inventory::class,
            'products.variant-stock' => Products\VariantStock::class,
            'order-summary' => OrderSummary::class,
            'order-customer' => OrderCustomer::class,
            'order-fulfillment' => OrderFulfillment::class,
            'slide-overs.create-shipping-label' => CreateShippingLabel::class,
            'slide-overs.review-detail' => ReviewDetail::class,
            'slide-overs.shipment-add-event' => ShipmentAddEvent::class,
        ]);

        Livewire::component('order-allocation-override', OrderAllocationOverride::class);
        Livewire::component('order-payment-transactions', OrderPaymentTransactions::class);
        Livewire::component('order-notes', OrderNotes::class);
        Livewire::component('order-finance-ops-panel', OrderFinanceOpsPanel::class);
        Livewire::component('oceanmall-dashboard.operational-alerts', OperationalAlerts::class);
        Livewire::component('oceanmall-dashboard.stat-cards', StatCards::class);
        Livewire::component('oceanmall-dashboard.revenue-chart', RevenueChart::class);
        Livewire::component('oceanmall-dashboard.top-selling-products', TopSellingProducts::class);
        Livewire::component('oceanmall-dashboard.recent-orders', RecentOrders::class);

        $panel->addonSidebar(OceanMallStoreSidebar::class);
        $panel->addonStyles(['css/cpanel-addon.css']);

        $panel->addonRoutes(static function (): void {
            Route::get('orders/payment-proofs', ManualPaymentProofs::class)->name('orders.payment-proofs');
            Route::get('orders/returns', OrderReturns::class)->name('orders.returns');
            Route::get('orders/disputes', PaymentDisputes::class)->name('orders.disputes');
            Route::get('orders/allocation-logs', AllocationOverrideLogs::class)->name('orders.allocation-logs');
            Route::get('reports/finance', FinanceReports::class)->name('reports.finance');
        });

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

        $panel->renderHook(
            OrderRenderHook::DETAIL_MAIN_AFTER,
            static function (): string {
                $order = OrderFromRequest::resolve();

                if (! $order instanceof Order) {
                    return '';
                }

                return view('shopper.partials.order-main-after-hook', [
                    'order' => $order,
                ])->render();
            },
        );

        $panel->renderHook(
            OrderRenderHook::DETAIL_SIDEBAR_AFTER,
            static function (): string {
                $order = OrderFromRequest::resolve();

                if (! $order instanceof Order) {
                    return '';
                }

                return view('shopper.partials.order-sidebar-after-hook', [
                    'order' => $order,
                ])->render();
            },
        );
    }
}
