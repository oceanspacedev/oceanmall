<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;

#[Unguarded]
final class PaymentDispute extends Model
{
    public const TYPE_PAID_AFTER_CANCELLATION = 'paid_after_cancellation';

    public const TYPE_DOUBLE_PAYMENT = 'double_payment';

    public const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';

    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_RESOLVED_REFUNDED = 'resolved_refunded';

    public const STATUS_RESOLVED_APPLIED = 'resolved_applied';

    public const STATUS_DISMISSED = 'dismissed';

    protected $table = 'payment_disputes';

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return BelongsTo<OrderRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'order_refund_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
