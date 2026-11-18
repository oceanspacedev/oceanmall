<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Models\InventoryShippingOrigin;
use Illuminate\Support\Collection;
use Shopper\Core\Models\Inventory;

/**
 * Read/write warehouse origin references per shipping driver
 * (table inventory_shipping_origins).
 */
final class InventoryOrigins
{
    public function get(Inventory|int|null $inventory, string $driver): ?string
    {
        $inventoryId = $this->id($inventory);

        if ($inventoryId === null) {
            return null;
        }

        $value = InventoryShippingOrigin::query()
            ->where('inventory_id', $inventoryId)
            ->where('driver', $driver)
            ->value('external_id');

        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }

    public function has(Inventory|int|null $inventory, string $driver): bool
    {
        return $this->get($inventory, $driver) !== null;
    }

    /**
     * Store (or clear, when $externalId is blank) an inventory's origin.
     */
    public function put(Inventory|int $inventory, string $driver, mixed $externalId, ?string $label = null): void
    {
        $inventoryId = $this->id($inventory);

        if ($inventoryId === null) {
            return;
        }

        $externalId = is_scalar($externalId) ? trim((string) $externalId) : '';

        if ($externalId === '') {
            $this->forget($inventoryId, $driver);

            return;
        }

        InventoryShippingOrigin::query()->updateOrCreate(
            ['inventory_id' => $inventoryId, 'driver' => $driver],
            ['external_id' => $externalId, 'label' => $label !== null && trim($label) !== '' ? trim($label) : null],
        );
    }

    public function forget(Inventory|int $inventory, string $driver): void
    {
        $inventoryId = $this->id($inventory);

        if ($inventoryId === null) {
            return;
        }

        InventoryShippingOrigin::query()
            ->where('inventory_id', $inventoryId)
            ->where('driver', $driver)
            ->delete();
    }

    /**
     * Inventory ids that have an origin for the driver.
     *
     * @return list<int>
     */
    public function inventoryIdsFor(string $driver): array
    {
        return InventoryShippingOrigin::query()
            ->where('driver', $driver)
            ->where('external_id', '!=', '')
            ->pluck('inventory_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * driver => external_id for one inventory.
     *
     * @return array<string, string>
     */
    public function allFor(Inventory|int $inventory): array
    {
        $inventoryId = $this->id($inventory);

        if ($inventoryId === null) {
            return [];
        }

        return InventoryShippingOrigin::query()
            ->where('inventory_id', $inventoryId)
            ->pluck('external_id', 'driver')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * external_id keyed by inventory id, for many inventories at once.
     *
     * @param  iterable<int>  $inventoryIds
     * @return Collection<int, string>
     */
    public function forInventories(iterable $inventoryIds, string $driver): Collection
    {
        $ids = collect($inventoryIds)->map(static fn (mixed $id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return InventoryShippingOrigin::query()
            ->where('driver', $driver)
            ->whereIn('inventory_id', $ids->all())
            ->pluck('external_id', 'inventory_id')
            ->map(static fn (mixed $id): string => (string) $id);
    }

    private function id(Inventory|int|null $inventory): ?int
    {
        if ($inventory instanceof Inventory) {
            return $inventory->getKey() !== null ? (int) $inventory->getKey() : null;
        }

        return is_int($inventory) && $inventory > 0 ? $inventory : null;
    }
}
