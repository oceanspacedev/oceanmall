<?php

declare(strict_types=1);

namespace App\Addons\Store;

use Illuminate\Support\Facades\Gate;
use Shopper\Sidebar\AbstractAdminSidebar;
use Shopper\Sidebar\Contracts\Builder\Group;
use Shopper\Sidebar\Contracts\Builder\Item;
use Shopper\Sidebar\Contracts\Builder\Menu;

final class OceanMallStoreSidebar extends AbstractAdminSidebar
{
    public function extendWith(Menu $menu): Menu
    {
        $canBrowseOrders = (bool) ($this->user?->isAdmin() || $this->user?->hasPermissionTo('browse_orders'));

        $menu->group(__('shopper::layout.sidebar.sales'), function (Group $group) use ($canBrowseOrders): void {
            $group->item(__('shopper::pages/orders.menu'), function (Item $item) use ($canBrowseOrders): void {
                $item->item('Verifikasi Transfer Manual', function (Item $sub) use ($canBrowseOrders): void {
                    $sub->weight(3);
                    $sub->setAuthorized($canBrowseOrders);
                    $sub->useSpa();
                    $sub->route('shopper.orders.payment-proofs');
                });

                $item->item('Retur & RMA', function (Item $sub) use ($canBrowseOrders): void {
                    $sub->weight(4);
                    $sub->setAuthorized($canBrowseOrders);
                    $sub->useSpa();
                    $sub->route('shopper.orders.returns');
                });

                $item->item('Sengketa Pembayaran', function (Item $sub) use ($canBrowseOrders): void {
                    $sub->weight(5);
                    $sub->setAuthorized($canBrowseOrders);
                    $sub->useSpa();
                    $sub->route('shopper.orders.disputes');
                });

                $canOverrideAllocation = (bool) ($this->user !== null && Gate::forUser($this->user)->allows('override-allocation'));
                $item->item('Log Override Alokasi', function (Item $sub) use ($canOverrideAllocation): void {
                    $sub->weight(6);
                    $sub->setAuthorized($canOverrideAllocation);
                    $sub->useSpa();
                    $sub->route('shopper.orders.allocation-logs');
                });
            });
        });

        $canViewFinance = (bool) ($this->user !== null && Gate::forUser($this->user)->allows('finance-dashboard'));
        $menu->group('Laporan', function (Group $group) use ($canViewFinance): void {
            $group->weight(15);
            $group->setAuthorized($canViewFinance);
            $group->item('Keuangan, Pajak & Settlement', function (Item $item) use ($canViewFinance): void {
                $item->weight(1);
                $item->setAuthorized($canViewFinance);
                $item->useSpa();
                $item->route('shopper.reports.finance');
            });
        });

        return $menu;
    }
}
