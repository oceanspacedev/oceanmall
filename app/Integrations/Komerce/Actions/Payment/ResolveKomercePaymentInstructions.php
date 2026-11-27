<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Actions\Payment;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Support\KomercePaymentProvider;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

final class ResolveKomercePaymentInstructions
{
    /**
     * Rebuild customer-facing payment instructions from order metadata.
     *
     * @return array<string, mixed>|null
     */
    public function handle(Order $order): ?array
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            return null;
        }

        $metadata = $this->decodeMetadata($order->getAttribute('metadata'));
        $instructions = data_get($metadata, 'komerce.payment_instructions');

        if (! is_array($instructions) || blank(data_get($instructions, 'payment_id'))) {
            return null;
        }

        $explicitProvider = is_string($instructions['provider'] ?? null) && trim($instructions['provider']) !== ''
            ? strtolower(trim($instructions['provider']))
            : null;
        $provider = $explicitProvider ?? KomercePaymentProvider::resolve($order, ['provider' => null]);

        return [
            'payment_id' => (string) $instructions['payment_id'],
            'payment_type' => (string) ($instructions['payment_type'] ?? 'bank_transfer'),
            'provider' => $provider,
            'virtual_account_number' => $instructions['virtual_account_number'] ?? null,
            'bank_code' => $instructions['bank_code'] ?? null,
            'qris_string' => $instructions['qris_string'] ?? null,
            'payment_url' => $instructions['payment_url'] ?? null,
            'expiry_date' => $instructions['expiry_date'] ?? null,
            'amount' => (int) ($instructions['amount'] ?? $order->price_amount),
            'currency_code' => (string) ($instructions['currency_code'] ?? $order->currency_code ?? 'IDR'),
        ];
    }

    public function canRetry(Order $order): bool
    {
        if (
            $order->status === OrderStatus::Cancelled
            || in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::Voided, PaymentStatus::Refunded], true)
        ) {
            return false;
        }

        $order->loadMissing('paymentMethod');

        $metadata = $this->decodeMetadata($order->getAttribute('metadata'));
        $isKomerceOrder = ($order->paymentMethod?->driver ?? null) === 'komerce'
            || filled(data_get($metadata, 'komerce'));

        if (! $isKomerceOrder) {
            return false;
        }

        $paymentType = $this->resolvePaymentType($order, $metadata);

        if ($paymentType === 'qris') {
            $amount = (int) ($order->price_amount ?? 0);
            $qrislyOk = Komerce::qrislyEnabled() && ($amount <= 0 || ($amount >= 1000 && $amount <= 100_000_000));
            $paymentApiOk = Komerce::paymentEnabled() && ($amount <= 0 || $amount >= 10_000);

            return $qrislyOk || $paymentApiOk;
        }

        return Komerce::paymentEnabled();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolvePaymentType(Order $order, array $metadata): string
    {
        $methodMeta = $this->decodeMetadata($order->paymentMethod?->metadata);

        $raw = data_get($metadata, 'komerce.payment_type')
            ?? data_get($metadata, 'komerce.payment_instructions.payment_type')
            ?? data_get($metadata, 'payment.payment_type')
            ?? $methodMeta['payment_type']
            ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            $normalized = strtolower(trim($raw));

            if ($normalized === 'qris') {
                return 'qris';
            }

            if (in_array($normalized, ['bank_transfer', 'va'], true)) {
                return 'bank_transfer';
            }
        }

        $slug = mb_strtolower((string) ($order->paymentMethod?->slug ?? ''));

        return str_contains($slug, 'qris') ? 'qris' : 'bank_transfer';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
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
