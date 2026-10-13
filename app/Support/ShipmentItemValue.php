<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Models\Order;

/**
 * Declared goods value of one shipment, for courier tariffs that need it
 * (Komerce Delivery rejects item_value <= 0 and prices insurance from it).
 *
 * Callers pass the cart or order they already hold, so quoting never has to
 * reach for the session cart (which does not exist on the stateless API).
 */
final class ShipmentItemValue
{
    /**
     * @param  list<array{purchasable_type: string, purchasable_id: int, qty: int}>  $lines
     */
    public static function forCart(Cart $cart, array $lines): int
    {
        $cart->loadMissing('lines');

        $prices = $cart->lines->mapWithKeys(static fn ($line): array => [
            self::key((string) $line->purchasable_type, (int) $line->purchasable_id) => (int) $line->unit_price_amount,
        ]);

        return self::sum($prices, $lines);
    }

    /**
     * @param  list<array{purchasable_type: string, purchasable_id: int, qty: int}>  $lines
     */
    public static function forOrder(Order $order, array $lines): int
    {
        $prices = $order->items()->get()->mapWithKeys(static fn ($item): array => [
            self::key((string) $item->product_type, (int) $item->product_id) => (int) $item->unit_price_amount,
        ]);

        return self::sum($prices, $lines);
    }

    /**
     * @param  Collection<string, int>  $prices
     * @param  list<array{purchasable_type: string, purchasable_id: int, qty: int}>  $lines
     */
    private static function sum(Collection $prices, array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $price = $prices->get(self::key((string) $line['purchasable_type'], (int) $line['purchasable_id']), 0);
            $total += $price * max(1, (int) $line['qty']);
        }

        return $total;
    }

    private static function key(string $type, int $id): string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return $class.'#'.$id;
    }
}
