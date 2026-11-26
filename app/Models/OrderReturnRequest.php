<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;

#[Unguarded]
final class OrderReturnRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_RESTOCKED = 'restocked';

    public const STATUS_REFUNDED = 'refunded';

    protected $table = 'order_return_requests';

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Inventory, $this> */
    public function restockInventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'restock_inventory_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'evidence_paths' => 'array',
            'reviewed_at' => 'datetime',
            'received_at' => 'datetime',
            'restocked_at' => 'datetime',
        ];
    }
}
