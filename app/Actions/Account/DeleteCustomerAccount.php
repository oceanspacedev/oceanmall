<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Http\Middleware\EnsureCpanelIsStaffOnly;
use App\Models\User;
use App\Support\CustomerCheckoutState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartAddress;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Review;

/**
 * Deletes a customer account and the personal data that hangs off it. Shared by the web
 * "Hapus akun" button and the mobile `DELETE /api/v1/auth/me`, so both remove the same things
 * (App Store 5.1.1(v) and the Play account-deletion policy).
 *
 * What goes: the user row, API tokens, saved addresses (they cascade), notifications, the
 * customer's reviews, their open carts, the cached checkout state, password-reset tokens and
 * web sessions.
 *
 * What stays: orders and their shipping name, phone and address. `orders.customer_id` and
 * `order_addresses.customer_id` are nullOnDelete, so the rows survive without a link to any
 * account; they are the transaction record the store must keep (tax, disputes, fulfilment of an
 * order that is still on its way). The privacy policy says so.
 */
final readonly class DeleteCustomerAccount
{
    public function __construct(private CustomerCheckoutState $checkoutState) {}

    public function assertDeletable(User $user): void
    {
        if (EnsureCpanelIsStaffOnly::userIsStaff($user)) {
            throw ValidationException::withMessages([
                'account' => 'Akun staf tidak dapat dihapus melalui halaman pelanggan.',
            ]);
        }

        $hasActiveOrders = Order::query()
            ->where('customer_id', $user->getKey())
            ->where(static function ($query): void {
                $query->whereNotIn('status', [
                    OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value,
                    OrderStatus::Archived->value,
                ])->orWhere('payment_status', PaymentStatus::Authorized->value);
            })
            ->exists();

        if ($hasActiveOrders) {
            throw ValidationException::withMessages([
                'account' => 'Akun tidak dapat dihapus saat masih ada pesanan aktif.',
            ]);
        }
    }

    public function handle(User $user): void
    {
        $this->assertDeletable($user);

        $email = (string) $user->email;

        DB::transaction(function () use ($user, $email): void {
            // personal_access_tokens is a morph with no foreign key, so nothing cascades.
            $user->tokens()->delete();
            $user->notifications()->delete();

            Review::query()
                ->where('author_type', $user->getMorphClass())
                ->where('author_id', $user->getKey())
                ->delete();

            CartAddress::query()
                ->whereIn(
                    'cart_id',
                    Cart::query()->where('customer_id', $user->getKey())->select('id'),
                )
                ->delete();

            Cart::query()
                ->where('customer_id', $user->getKey())
                ->whereNull('completed_at')
                ->delete();

            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $email)
                ->delete();

            $sessions = (string) config('session.table', 'sessions');
            if (config('session.driver') === 'database' && Schema::hasTable($sessions)) {
                DB::table($sessions)->where('user_id', $user->getKey())->delete();
            }

            $user->delete();
        });

        $this->checkoutState->forget($user);
    }
}
