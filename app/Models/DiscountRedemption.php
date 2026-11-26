<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shopper\Core\Models\Order;

#[Unguarded]
final class DiscountRedemption extends Model
{
    protected $table = 'discount_redemptions';

    public static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', User::normalizeEmail($email), (string) config('app.key'));
    }

    public static function hasActiveRedemption(int $discountId, string $email): bool
    {
        $normalized = User::normalizeEmail($email);
        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->where('discount_id', $discountId)
            ->where('email_hash', self::hashEmail($normalized))
            ->whereNull('released_at')
            ->exists();
    }

    public static function record(int $discountId, int $orderId, string $email): void
    {
        $normalized = User::normalizeEmail($email);
        if ($normalized === '') {
            return;
        }

        self::query()->updateOrCreate(
            ['discount_id' => $discountId, 'order_id' => $orderId],
            ['email_hash' => self::hashEmail($normalized), 'released_at' => null],
        );
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
        ];
    }
}
