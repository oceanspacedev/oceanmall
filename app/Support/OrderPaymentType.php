<?php

declare(strict_types=1);

namespace App\Support;

use App\Payment\PaymentMethodCatalog;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;

/**
 * Provider-neutral classification of an order's payment mode so fulfillment,
 * COD collection, and manual bank-transfer proof workflows follow clear rules.
 */
final class OrderPaymentType
{
    public static function isCod(Order $order): bool
    {
        $orderMeta = self::decode($order->metadata);
        $orderType = strtolower(trim((string) data_get($orderMeta, 'payment.payment_type', '')));

        if ($orderType === 'cod') {
            return true;
        }

        $method = $order->relationLoaded('paymentMethod')
            ? $order->paymentMethod
            : $order->paymentMethod()->first();

        return $method instanceof PaymentMethod && self::methodIsCod($method);
    }

    public static function methodIsCod(PaymentMethod $method): bool
    {
        $slug = strtolower(trim((string) $method->slug));
        $meta = PaymentMethodCatalog::metadata($method);
        $metaType = strtolower(trim((string) ($meta['payment_type'] ?? '')));

        return $slug === 'cod' || $metaType === 'cod';
    }

    public static function isManualBankTransfer(Order $order): bool
    {
        if (self::isCod($order)) {
            return false;
        }

        $method = $order->relationLoaded('paymentMethod')
            ? $order->paymentMethod
            : $order->paymentMethod()->first();

        if (! $method instanceof PaymentMethod) {
            return false;
        }

        $driver = strtolower(trim((string) ($method->driver ?: 'manual')));

        if ($driver !== 'manual') {
            return false;
        }

        $meta = PaymentMethodCatalog::metadata($method);
        $metaType = strtolower(trim((string) ($meta['payment_type'] ?? '')));
        $slug = strtolower(trim((string) $method->slug));

        if (($meta['requires_proof'] ?? false) === true) {
            return true;
        }

        if (in_array($metaType, ['bank_transfer', 'manual_bank_transfer'], true)) {
            return true;
        }

        return str_contains($slug, 'transfer') || str_contains($slug, 'bank') || $metaType === '';
    }

    /**
     * Paid orders are fulfillable, and COD orders remain fulfillable while
     * still unpaid until cash is collected upon delivery.
     */
    public static function canFulfill(Order $order): bool
    {
        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Completed, OrderStatus::Archived], true)) {
            return false;
        }

        if ($order->payment_status === PaymentStatus::Paid) {
            return true;
        }

        return self::isCod($order) && $order->payment_status === PaymentStatus::Pending;
    }

    /**
     * Checks whether all selected shipping options in a checkout payload
     * support COD (refuses only when a real-time provider explicitly marks a
     * selected rate as non-COD).
     */
    public static function shippingRatesSupportCod(mixed $checkout): bool
    {
        $rates = [];
        $byShipment = data_get($checkout, 'shipping_options_by_shipment');

        if (is_array($byShipment) && $byShipment !== []) {
            foreach ($byShipment as $option) {
                if (is_array($option)) {
                    $rates[] = $option;
                }
            }
        } else {
            $single = data_get($checkout, 'shipping_option.0');
            if (is_array($single)) {
                $rates[] = $single;
            }
        }

        foreach ($rates as $rate) {
            foreach (['is_cod', 'kiriminaja_cod', 'available_for_cash_on_delivery'] as $flag) {
                if (array_key_exists($flag, $rate) && $rate[$flag] === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
