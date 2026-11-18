<?php

declare(strict_types=1);

namespace App\Observers;

use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShippingCapabilities;
use Shopper\Core\Models\Inventory;
use Throwable;
use WeakMap;

/**
 * Keeps warehouse origins (inventory_shipping_origins) in step with inventories.
 *
 * - Explicit origins travel with the model as a transient `shipping_origins`
 *   attribute (driver => external id); the cpanel location form saves them
 *   this way. A `rajaongkir_origin_id` value set on the model (the legacy
 *   column, kept but no longer read or written) is still accepted and means
 *   `shipping_origins.rajaongkir`.
 * - After a save, each configured driver that resolves destinations and has
 *   no origin for this warehouse yet resolves one from the address.
 */
final class InventoryObserver
{
    public const ATTRIBUTE = 'shipping_origins';

    /** @var array<string, string> legacy attribute => driver */
    public const LEGACY_ATTRIBUTES = [
        'rajaongkir_origin_id' => 'rajaongkir',
    ];

    /** @var WeakMap<Inventory, array{origins: array<string, mixed>, creating: bool}>|null */
    private static ?WeakMap $pending = null;

    public function saving(Inventory $inventory): void
    {
        $attributes = $inventory->getAttributes();
        $origins = [];

        if (array_key_exists(self::ATTRIBUTE, $attributes)) {
            $value = $attributes[self::ATTRIBUTE];
            $value = is_string($value) ? json_decode($value, true) : $value;

            if (is_array($value)) {
                $origins = array_filter($value, static fn (mixed $key): bool => is_string($key) && $key !== '', ARRAY_FILTER_USE_KEY);
            }

            $inventory->offsetUnset(self::ATTRIBUTE);
        }

        foreach (self::LEGACY_ATTRIBUTES as $attribute => $driver) {
            // Only a value the caller set counts: the legacy column is kept
            // until a later release (expand-only migration 2026_11_18_000100),
            // so a loaded model carries its stale value, which must neither
            // override the table nor be written back.
            if (! array_key_exists($attribute, $attributes) || ! $inventory->isDirty($attribute)) {
                continue;
            }

            if (! array_key_exists($driver, $origins)) {
                $origins[$driver] = $attributes[$attribute];
            }

            $inventory->offsetUnset($attribute);
        }

        if ($origins !== []) {
            self::pending()[$inventory] = ['origins' => $origins, 'creating' => ! $inventory->exists];
        }
    }

    public function saved(Inventory $inventory): void
    {
        $store = resolve(InventoryOrigins::class);
        $pending = self::pending();
        $explicit = $pending[$inventory]['origins'] ?? [];
        $creating = (bool) ($pending[$inventory]['creating'] ?? false);
        unset($pending[$inventory]);

        $handled = [];

        foreach ($explicit as $driver => $externalId) {
            $filled = is_scalar($externalId) && trim((string) $externalId) !== '';

            // On create, a blank origin only means "not given": leave the
            // slot to auto-resolution. On update it clears the origin.
            if (! $filled && $creating) {
                continue;
            }

            $store->put($inventory, (string) $driver, $externalId);
            $handled[] = (string) $driver;
        }

        $this->resolveMissingOrigins($inventory, $store, $handled);
    }

    /**
     * @param  list<string>  $skip  Drivers whose origin the caller set or cleared on purpose
     */
    private function resolveMissingOrigins(Inventory $inventory, InventoryOrigins $store, array $skip): void
    {
        foreach (resolve(ShippingCapabilities::class)->supporting(ResolvesDestinations::class) as $code => $driver) {
            if (in_array($code, $skip, true) || $store->has($inventory, $code)) {
                continue;
            }

            try {
                $origin = $driver->resolveOrigin($inventory);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $id = is_array($origin) && is_scalar($origin['id'] ?? null) ? trim((string) $origin['id']) : '';

            if ($id !== '') {
                $label = $origin['label'] ?? null;
                $store->put($inventory, $code, $id, is_scalar($label) ? (string) $label : null);
            }
        }
    }

    /**
     * @return WeakMap<Inventory, array{origins: array<string, mixed>, creating: bool}>
     */
    private static function pending(): WeakMap
    {
        return self::$pending ??= new WeakMap;
    }
}
