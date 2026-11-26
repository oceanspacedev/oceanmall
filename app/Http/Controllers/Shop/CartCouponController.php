<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\DiscountRedemption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Shopper\Cart\CartManager;
use Shopper\Cart\CartSessionManager;
use Shopper\Cart\Exceptions\InvalidDiscountException;
use Shopper\Cart\Models\CartLineAdjustment;
use Shopper\Core\Enum\DiscountStatus;
use Shopper\Core\Models\Discount;
use Throwable;

final class CartCouponController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ]);

        $code = strtoupper(trim($data['code']));
        $cart = resolve(CartSessionManager::class)->current();

        if (! $cart || $cart->lines->isEmpty()) {
            return back()->withErrors(['code' => 'Keranjang masih kosong.']);
        }

        $discount = Discount::query()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();

        if (! $discount instanceof Discount || ! $discount->is_active || $discount->status !== DiscountStatus::Active) {
            return back()->withErrors(['code' => 'Kode kupon tidak valid.']);
        }

        if ($discount->hasReachedLimit()) {
            return back()->withErrors(['code' => 'Kuota kupon sudah habis.']);
        }

        $userEmail = $request->user()?->email;
        if ($discount->usage_limit_per_user && is_string($userEmail) && DiscountRedemption::hasActiveRedemption($discount->id, $userEmail)) {
            return back()->withErrors(['code' => 'Kupon ini hanya dapat digunakan satu kali per akun/email.']);
        }

        try {
            resolve(CartManager::class)->applyCoupon($cart, $discount->code);
            $context = resolve(CartManager::class)->calculate($cart->refresh());
        } catch (InvalidDiscountException) {
            return back()->withErrors(['code' => 'Kode kupon tidak valid.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['code' => 'Gagal menerapkan kupon. Coba lagi.']);
        }

        if ($context->discountTotal <= 0) {
            resolve(CartManager::class)->removeCoupon($cart);
            CartLineAdjustment::query()->whereIn('cart_line_id', $cart->lines()->select('id'))->delete();

            return back()->withErrors(['code' => 'Kupon tidak dapat digunakan untuk keranjang ini.']);
        }

        session()->forget(CheckoutController::CONFIRM_PAYMENT_KEYS);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Kupon {$discount->code} diterapkan.",
        ]);

        return back();
    }

    public function destroy(): RedirectResponse
    {
        $cart = resolve(CartSessionManager::class)->current();

        if ($cart) {
            resolve(CartManager::class)->removeCoupon($cart);
            CartLineAdjustment::query()->whereIn('cart_line_id', $cart->lines()->select('id'))->delete();
            session()->forget(CheckoutController::CONFIRM_PAYMENT_KEYS);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Kupon dihapus.',
        ]);

        return back();
    }
}
