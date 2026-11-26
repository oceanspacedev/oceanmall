<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Support;

use App\Payment\PaymentReturnUrl;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Shopper\Core\Models\Order;

/**
 * Builds the Snap "create transaction" body for one payment attempt.
 *
 * - transaction_details.gross_amount is the order total in whole rupiah
 *   (IDR is zero-decimal in this store: never scaled by 100).
 * - enabled_payments is the chosen method's Snap code (payment method
 *   metadata.channel_code, e.g. "bca_va", "other_qris"), so Snap opens on
 *   that channel only.
 * - expiry bounds how long the attempt can be paid and page_expiry how long
 *   the Snap page itself opens (both the same duration from now); the same
 *   instant is stored as the order's payment expiry (orders:expire-unpaid).
 * - item_details must add up to gross_amount or Snap refuses the request:
 *   order lines are sent with one extra "shipping & fees" line for any
 *   positive remainder; when lines exceed the total (discounts) they are
 *   left out entirely rather than risk a refused payment.
 */
final class MidtransSnapPayload
{
    /** Snap item name limit. */
    private const ITEM_NAME_MAX = 50;

    /**
     * @return array<string, mixed>
     */
    public static function build(Order $order, string $midtransOrderId, int $amount, ?string $channel, CarbonInterface $startsAt, int $expiryMinutes, ?string $returnUrl = null): array
    {
        $payload = [
            'transaction_details' => [
                'order_id' => $midtransOrderId,
                'gross_amount' => $amount,
            ],
            'expiry' => [
                'start_time' => $startsAt->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s O'),
                'unit' => 'minute',
                'duration' => $expiryMinutes,
            ],
            // The Snap page / token has its own lifetime (default 24 h),
            // independent of `expiry`: keep it payable for exactly as long
            // as the stored pay link says (MIDTRANS_EXPIRY_MINUTES may be up
            // to 7 days, or shorter than a day).
            'page_expiry' => [
                'unit' => 'minute',
                'duration' => $expiryMinutes,
            ],
            'callbacks' => [
                'finish' => self::finishUrl($order, $returnUrl),
            ],
        ];

        $channel = $channel !== null ? trim($channel) : '';

        if ($channel !== '') {
            $payload['enabled_payments'] = [$channel];
        }

        if ($channel === 'credit_card') {
            $payload['credit_card'] = ['secure' => true];
        }

        $customer = self::customerDetails($order);

        if ($customer !== []) {
            $payload['customer_details'] = $customer;
        }

        $items = self::itemDetails($order, $amount);

        if ($items !== null) {
            $payload['item_details'] = $items;
        }

        return $payload;
    }

    public static function finishUrl(Order $order, ?string $returnUrl = null): string
    {
        $override = is_string($returnUrl) ? trim($returnUrl) : '';
        if ($override !== '') {
            return $override;
        }

        $stored = PaymentReturnUrl::get($order);
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $configured = trim((string) config('midtrans.finish_url', ''));

        if ($configured !== '') {
            return $configured;
        }

        if (Route::has('account.orders.show')) {
            return route('account.orders.show', ['order' => $order->id]);
        }

        if (Route::has('shop.checkout.success')) {
            return route('shop.checkout.success', ['order' => $order->id]);
        }

        return url('/');
    }

    /**
     * @return array<string, string>
     */
    private static function customerDetails(Order $order): array
    {
        $order->loadMissing('customer');
        $customer = $order->customer;
        $shipping = MidtransOrderState::metadata($order)['shipping_address'] ?? [];
        $shipping = is_array($shipping) ? $shipping : [];

        $details = [
            'first_name' => trim((string) ($customer?->first_name ?? $shipping['first_name'] ?? '')),
            'last_name' => trim((string) ($customer?->last_name ?? $shipping['last_name'] ?? '')),
            'email' => trim((string) ($customer?->email ?? '')),
            'phone' => trim((string) ($customer?->phone_number ?? $shipping['phone_number'] ?? '')),
        ];

        return array_filter($details, static fn (string $value): bool => $value !== '');
    }

    /**
     * @return list<array{id: string, name: string, price: int, quantity: int}>|null
     */
    private static function itemDetails(Order $order, int $amount): ?array
    {
        $order->loadMissing('items');
        $items = [];
        $sum = 0;

        foreach ($order->items as $item) {
            $name = trim((string) $item->name);
            $quantity = (int) $item->quantity;
            $price = (int) $item->unit_price_amount;

            if ($name === '' || $quantity <= 0 || $price <= 0) {
                return null;
            }

            $items[] = [
                'id' => substr((string) ($item->sku ?: 'item-'.$item->id), 0, self::ITEM_NAME_MAX),
                'name' => mb_substr($name, 0, self::ITEM_NAME_MAX),
                'price' => $price,
                'quantity' => $quantity,
            ];
            $sum += $price * $quantity;
        }

        if ($items === [] || $sum > $amount) {
            return null;
        }

        if ($sum < $amount) {
            $items[] = [
                'id' => 'shipping-fees',
                'name' => 'Ongkos kirim & biaya lain',
                'price' => $amount - $sum,
                'quantity' => 1,
            ];
        }

        return $items;
    }
}
