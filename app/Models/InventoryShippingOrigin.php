<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shopper\Core\Models\Inventory;

/**
 * A warehouse's origin reference at one shipping provider
 * (e.g. driver=rajaongkir, external_id=17248).
 *
 * @property int $inventory_id
 * @property string $driver
 * @property string $external_id
 * @property string|null $label
 */
final class InventoryShippingOrigin extends Model
{
    protected $table = 'inventory_shipping_origins';

    protected $guarded = [];

    /**
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }
}
