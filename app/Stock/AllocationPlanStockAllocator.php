<?php

declare(strict_types=1);

namespace App\Stock;

use App\Support\CheckoutAllocationContext;
use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Contracts\StockAllocator;
use Shopper\Core\Models\Contracts\Stockable;
use Shopper\Core\Stock\PriorityStockAllocator;
use Shopper\Core\Stock\StockAllocation;

final readonly class AllocationPlanStockAllocator implements StockAllocator
{
    public function __construct(
        private CheckoutAllocationContext $context,
        private PriorityStockAllocator $fallback,
    ) {}

    /**
     * Allocations follow the checkout's allocation plan (the warehouses the
     * customer was quoted shipping from), but never beyond the stock each
     * warehouse holds now: the plan is computed at the shipping step and can
     * be stale by the time the order is placed (the last unit sold meanwhile,
     * staff lowered the stock). Shopper's LockingStockReserver calls this
     * under the product row lock, so the stock read here is the committed
     * one and a short allocation makes it refuse the line instead of going
     * below zero.
     *
     * A product that allows backorders keeps its planned quantities: it may
     * go below zero, but in the warehouse whose shipment ships it (Shopper's
     * own backorder branch would take it from the default warehouse, which
     * neither the shipment nor the stock released on cancel point at).
     *
     * @return array<int, StockAllocation>
     */
    public function allocate(Stockable $product, int $quantity): array
    {
        $plan = $this->context->get();

        if ($plan === null || ! ($product instanceof Model)) {
            return $this->fallback->allocate($product, $quantity);
        }

        $morphClass = $product->getMorphClass();
        $productId = (int) $product->getKey();

        $backorder = (bool) $product->getAttribute('allow_backorder');
        $planned = false;
        $allocations = [];
        /** @var array<int, int> $taken */
        $taken = [];

        foreach ($plan->shipments as $shipmentDraft) {
            foreach ($shipmentDraft->lines as $line) {
                if ($line['purchasable_type'] !== $morphClass || $line['purchasable_id'] !== $productId) {
                    continue;
                }

                $planned = true;
                $inventoryId = $shipmentDraft->inventory_id;
                $available = $product->stockInventory($inventoryId) - ($taken[$inventoryId] ?? 0);
                $allocatable = $backorder
                    ? (int) $line['qty']
                    : min((int) $line['qty'], max(0, $available));

                if ($allocatable > 0) {
                    $allocations[] = new StockAllocation($inventoryId, $allocatable);
                    $taken[$inventoryId] = ($taken[$inventoryId] ?? 0) + $allocatable;
                }
            }
        }

        // A planned product is only ever taken from its planned warehouses
        // (the shipments and their shipping rates are tied to them): when
        // they no longer hold enough, the short allocation refuses the order.
        if ($planned) {
            return $allocations;
        }

        return $this->fallback->allocate($product, $quantity);
    }
}
