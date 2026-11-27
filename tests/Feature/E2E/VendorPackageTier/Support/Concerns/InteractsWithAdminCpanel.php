<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Support\Concerns;

use App\Livewire\Shopper\Dashboard\OperationalAlerts;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\Pages\OrderDetail;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Shopper\Core\Models\Order;

/**
 * Reusable Admin Cpanel Livewire & HTTP helpers.
 */
trait InteractsWithAdminCpanel
{
    /**
     * @return Testable<OrderDetail>
     */
    protected function cpanelOrderDetail(User $admin, Order $order): Testable
    {
        return Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order]);
    }

    /**
     * @return Testable<Component>
     */
    protected function cpanelOrderShipping(User $admin, Order $order): Testable
    {
        return Livewire::actingAs($admin)
            ->test('komerce-order-shipping', ['order' => $order]);
    }

    /**
     * @return Testable<OrderFulfillment>
     */
    protected function cpanelOrderFulfillment(User $admin, Order $order): Testable
    {
        return Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $order]);
    }

    /**
     * @return Testable<CreateShippingLabel>
     */
    protected function cpanelCreateShippingLabel(User $admin, Order $order): Testable
    {
        return Livewire::actingAs($admin)
            ->test(CreateShippingLabel::class, ['order' => $order]);
    }

    /**
     * @return Testable<OperationalAlerts>
     */
    protected function cpanelOperationalAlerts(User $admin): Testable
    {
        return Livewire::actingAs($admin)
            ->test(OperationalAlerts::class);
    }

    protected function cpanelPrintLabel(
        User $admin,
        Order $order,
        ?int $shipmentId = null,
        ?string $page = null,
    ): TestResponse {
        $params = array_filter([
            'order' => $order,
            'shipment' => $shipmentId,
            'page' => $page,
        ], static fn (mixed $v): bool => $v !== null);

        return $this->from(route('shopper.orders.detail', $order))
            ->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $params));
    }
}
