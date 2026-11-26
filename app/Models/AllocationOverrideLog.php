<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;

#[Unguarded]
final class AllocationOverrideLog extends Model
{
    public $timestamps = false;

    protected $table = 'allocation_override_logs';

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Inventory, $this> */
    public function fromInventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'from_inventory_id');
    }

    /** @return BelongsTo<Inventory, $this> */
    public function toInventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'to_inventory_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
