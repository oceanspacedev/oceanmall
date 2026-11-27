<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Support;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Payment\KomerceDriver;
use Shopper\Core\Models\Order;
use Shopper\Payment\Models\PaymentTransaction;

final class KomercePaymentProvider
{
    /**
     * Resolve which Komerce sub-provider ('qrisly' or 'payment_api') owns an order's charge.
     *
     * @param  array<string, mixed>|null  $instructions
     */
    public static function resolve(Order $order, ?array $instructions = null): string
    {
        $fromInstructions = data_get($instructions, 'provider');

        if (is_string($fromInstructions) && trim($fromInstructions) !== '') {
            return strtolower(trim($fromInstructions));
        }

        $metadata = self::decodeMetadata($order->getAttribute('metadata'));

        foreach (['komerce.payment_instructions.provider', 'komerce.provider'] as $path) {
            $value = data_get($metadata, $path);

            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        $transaction = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', KomerceDriver::CODE)
            ->latest('id')
            ->first(['metadata']);

        $txMetadata = self::decodeMetadata($transaction?->metadata);
        $fromTx = data_get($txMetadata, 'komerce_provider');

        if (is_string($fromTx) && trim($fromTx) !== '') {
            return strtolower(trim($fromTx));
        }

        if (filled(data_get($metadata, 'komerce.qrisly_history_id'))) {
            return 'qrisly';
        }

        $order->loadMissing('paymentMethod');
        $methodMeta = self::decodeMetadata($order->paymentMethod?->metadata);

        $paymentType = strtolower(trim((string) (
            data_get($txMetadata, 'komerce_payment_type')
            ?? data_get($metadata, 'komerce.payment_type')
            ?? data_get($metadata, 'komerce.payment_instructions.payment_type')
            ?? data_get($methodMeta, 'payment_type')
            ?? (str_contains(strtolower((string) ($order->paymentMethod?->slug ?? '')), 'qris') ? 'qris' : '')
        )));

        if ($paymentType === 'qris') {
            $amount = (int) ($order->price_amount ?? 0);
            $qrislyApplies = Komerce::qrislyEnabled()
                && (! Komerce::paymentEnabled() || ($amount <= KomerceDriver::QRISLY_MAX_AMOUNT && ($amount <= 0 || $amount >= KomerceDriver::QRISLY_MIN_AMOUNT)));

            if ($qrislyApplies) {
                return 'qrisly';
            }
        }

        return 'payment_api';
    }

    public static function resolveForReference(string $reference): string
    {
        $reference = trim($reference);

        if ($reference !== '') {
            $transaction = PaymentTransaction::query()
                ->where('driver', KomerceDriver::CODE)
                ->where('reference', $reference)
                ->latest('id')
                ->first(['order_id', 'metadata']);

            if ($transaction instanceof PaymentTransaction) {
                $fromTx = data_get(self::decodeMetadata($transaction->metadata), 'komerce_provider');

                if (is_string($fromTx) && trim($fromTx) !== '') {
                    return strtolower(trim($fromTx));
                }

                $order = Order::query()->find($transaction->order_id);

                if ($order instanceof Order) {
                    return self::resolve($order);
                }
            }

            $order = Order::query()
                ->where(static function ($query) use ($reference): void {
                    $query->where('metadata->komerce_payment_ref', $reference)
                        ->orWhere('metadata->komerce->payment_ref', $reference)
                        ->orWhere('metadata->komerce->qrisly_history_id', $reference);
                })
                ->latest('id')
                ->first();

            if ($order instanceof Order) {
                return self::resolve($order);
            }
        }

        if (! Komerce::paymentEnabled() && Komerce::qrislyEnabled()) {
            return 'qrisly';
        }

        return 'payment_api';
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
}
