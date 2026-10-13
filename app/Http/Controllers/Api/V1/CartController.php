<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\CustomerCart;
use App\Support\CustomerCatalogPresenter;
use App\Support\CustomerCheckoutState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shopper\Cart\CartManager;
use Shopper\Cart\Exceptions\InsufficientStockException;
use Shopper\Cart\Exceptions\InvalidDiscountException;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\DiscountStatus;
use Shopper\Core\Models\Discount;
use Throwable;

final class CartController extends Controller
{
    public function __construct(
        private readonly CustomerCart $customerCart,
        private readonly CustomerCatalogPresenter $presenter,
        private readonly CustomerCheckoutState $checkoutState,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $guestToken = $this->customerCart->tokenFrom($request);

        if ($user instanceof User) {
            return $this->cartResponse($this->customerCart->current($user, $guestToken));
        }

        if (is_string($guestToken)) {
            $cart = $this->customerCart->findGuest($guestToken);

            return $cart instanceof Cart
                ? $this->cartResponse($cart)
                : response()->json(['data' => $this->presenter->emptyCart()]);
        }

        return response()->json(['data' => $this->presenter->emptyCart()]);
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:'.shopper_table('products').',id'],
            'variant_id' => ['nullable', 'integer', 'exists:'.shopper_table('product_variants').',id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.CustomerCart::MAX_LINE_QUANTITY],
            // "Buy now": set the line to exactly this quantity instead of adding to it.
            'replace' => ['nullable', 'boolean'],
        ]);

        $product = Product::query()->scopes('publish')->findOrFail($data['product_id']);
        $variant = isset($data['variant_id'])
            ? ProductVariant::query()->where('product_id', $product->id)->find($data['variant_id'])
            : null;

        if (isset($data['variant_id']) && $variant === null) {
            return response()->json(['message' => 'Varian tidak ditemukan untuk produk ini.'], 422);
        }

        if ($variant === null && $product->variants()->exists()) {
            return response()->json(['message' => 'Pilih varian produk sebelum menambah ke keranjang.'], 422);
        }

        $cart = $this->writableCart($request);
        if (! $cart instanceof Cart) {
            return $this->missingCartToken();
        }

        $purchasable = $variant ?? $product;
        $quantity = (int) ($data['quantity'] ?? 1);
        $existing = $cart->lines()
            ->where('purchasable_type', $purchasable->getMorphClass())
            ->where('purchasable_id', $purchasable->getKey())
            ->first();

        if (! ($data['replace'] ?? false) && $existing && $existing->quantity + $quantity > CustomerCart::MAX_LINE_QUANTITY) {
            return response()->json([
                'message' => 'Maksimal '.CustomerCart::MAX_LINE_QUANTITY.' per produk dalam keranjang.',
            ], 422);
        }

        try {
            if (($data['replace'] ?? false) && $existing) {
                resolve(CartManager::class)->update($cart, $existing->id, ['quantity' => $quantity]);
            } else {
                resolve(CartManager::class)->add($cart, $purchasable, $quantity);
            }
        } catch (InsufficientStockException) {
            return response()->json(['message' => 'Stok tidak mencukupi.'], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->forgetCheckout($request);

        return $this->cartResponse($cart->refresh());
    }

    public function update(Request $request, int $line): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CustomerCart::MAX_LINE_QUANTITY],
        ]);

        $cart = $this->existingCart($request);
        if (! $cart instanceof Cart) {
            return $this->missingCart();
        }

        try {
            resolve(CartManager::class)->update($cart, $line, ['quantity' => (int) $data['quantity']]);
        } catch (InsufficientStockException) {
            return response()->json(['message' => 'Stok tidak mencukupi.'], 422);
        }

        $this->forgetCheckout($request);

        return $this->cartResponse($cart->refresh());
    }

    public function destroy(Request $request, int $line): JsonResponse
    {
        $cart = $this->existingCart($request);
        if (! $cart instanceof Cart) {
            return $this->missingCart();
        }

        resolve(CartManager::class)->remove($cart, $line);
        $this->forgetCheckout($request);

        return $this->cartResponse($cart->refresh());
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->existingCart($request);
        if (! $cart instanceof Cart) {
            // Nothing to clear is still a successful "empty cart".
            return response()->json(['data' => $this->presenter->emptyCart()]);
        }

        resolve(CartManager::class)->clear($cart);
        $this->forgetCheckout($request);

        return $this->cartResponse($cart->refresh());
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ]);
        $code = strtoupper(trim($data['code']));
        $cart = $this->existingCart($request);
        if (! $cart instanceof Cart) {
            return $this->missingCart();
        }

        if ($cart->lines()->count() < 1) {
            return response()->json(['message' => 'Keranjang masih kosong.'], 422);
        }

        $discount = Discount::query()->where('code', $code)->first();
        if (! $discount instanceof Discount || ! $discount->is_active || $discount->status !== DiscountStatus::Active) {
            return response()->json(['message' => 'Kode kupon tidak valid.'], 422);
        }

        try {
            resolve(CartManager::class)->applyCoupon($cart, $code);
        } catch (InvalidDiscountException) {
            return response()->json(['message' => 'Kode kupon tidak valid.'], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Kupon tidak bisa dipakai.'], 422);
        }

        return $this->cartResponse($cart->refresh());
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        $cart = $this->existingCart($request);
        if (! $cart instanceof Cart) {
            return $this->missingCart();
        }

        resolve(CartManager::class)->removeCoupon($cart);

        return $this->cartResponse($cart->refresh());
    }

    private function cartResponse(Cart $cart): JsonResponse
    {
        $cart->load(['lines.purchasable.media']);
        $context = $cart->lines->isEmpty()
            ? null
            : resolve(CartManager::class)->calculate($cart);

        return response()->json([
            'data' => $this->presenter->cart($cart, $context),
        ]);
    }

    private function writableCart(Request $request): ?Cart
    {
        $user = $this->actor($request);
        $guestToken = $this->customerCart->tokenFrom($request);

        if ($user instanceof User) {
            return $this->customerCart->current($user, $guestToken);
        }

        if (is_string($guestToken)) {
            return $this->customerCart->forGuest($guestToken);
        }

        return null;
    }

    private function existingCart(Request $request): ?Cart
    {
        $user = $this->actor($request);
        $guestToken = $this->customerCart->tokenFrom($request);

        if ($user instanceof User) {
            return $this->customerCart->current($user, $guestToken);
        }

        return is_string($guestToken) ? $this->customerCart->findGuest($guestToken) : null;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user('sanctum') ?? $request->user();

        return $user instanceof User ? $user : null;
    }

    private function forgetCheckout(Request $request): void
    {
        $user = $this->actor($request);
        if ($user instanceof User) {
            $this->checkoutState->forgetQuote($user);
        }
    }

    private function missingCartToken(): JsonResponse
    {
        return response()->json(['message' => 'Kirim X-Cart-Token atau masuk dulu.'], 422);
    }

    private function missingCart(): JsonResponse
    {
        return response()->json(['message' => 'Keranjang tidak ditemukan.'], 422);
    }
}
