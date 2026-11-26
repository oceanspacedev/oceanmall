<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CustomerCart;
use Shopper\Cart\CartManager;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\ProductType;

final readonly class AddToCart
{
    public function __construct(
        private CartManager $cartManager,
    ) {}

    public function handle(Product $product, ?ProductVariant $variant = null, int $quantity = 1): CartLine
    {
        if ($variant === null && $product->variants()->exists()) {
            throw new \InvalidArgumentException('Pilih varian produk sebelum menambah ke keranjang.');
        }

        if ($product->type === ProductType::External) {
            throw new \InvalidArgumentException('Produk eksternal tidak dapat dibeli melalui keranjang.');
        }

        $cart = cartSession();
        $purchasable = $variant ?? $product;
        $price = $purchasable->getPrice($cart->currency_code);

        if (! $price || (int) $price->amount <= 0) {
            throw new \InvalidArgumentException('Harga produk belum tersedia.');
        }

        $existing = (int) $cart->lines()
            ->where('purchasable_type', $purchasable->getMorphClass())
            ->where('purchasable_id', $purchasable->getKey())
            ->value('quantity');

        if ($existing + $quantity > CustomerCart::MAX_LINE_QUANTITY) {
            throw new \InvalidArgumentException('Maksimal '.CustomerCart::MAX_LINE_QUANTITY.' per produk dalam keranjang.');
        }

        return $this->cartManager->add($cart, $purchasable, $quantity);
    }
}
