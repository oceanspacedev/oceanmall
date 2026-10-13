<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Komerce\PaymentClient;
use Throwable;

/**
 * Official Komerce Payment catalog (GET /api/v1/user/methods) and the matching
 * rule between a cpanel payment method and a catalog row. Shared by checkout
 * and the storefront footer so both show exactly what the provider offers.
 */
final class KomercePaymentCatalog
{
    /**
     * @return list<array<string, mixed>>|null Null when the catalog is unavailable
     */
    public function official(): ?array
    {
        if (! komerce_payment_enabled()) {
            return null;
        }

        try {
            $response = resolve(PaymentClient::class)->listMethods();
        } catch (Throwable) {
            return null;
        }

        $rows = data_get($response, 'data', []);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    /**
     * @param  array<string, mixed>  $meta  Local payment-method metadata (payment_type, channel_code)
     * @param  list<array<string, mixed>>  $official
     * @return array<string, mixed>|null
     */
    public function match(array $meta, array $official): ?array
    {
        $paymentType = strtolower((string) ($meta['payment_type'] ?? 'bank_transfer'));
        $channel = strtoupper(trim((string) ($meta['channel_code'] ?? '')));

        foreach ($official as $row) {
            $officialType = strtolower(trim((string) ($row['payment_type'] ?? '')));
            $officialType = $officialType === 'va' ? 'bank_transfer' : $officialType;
            $officialBank = strtoupper(trim((string) ($row['bank_code'] ?? '')));

            if ($paymentType === 'qris' && $officialType === 'qris') {
                return $row;
            }

            if ($paymentType === 'bank_transfer' && $officialType === 'bank_transfer' && $channel !== '' && $officialBank === $channel) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Provider display name for a matched row, e.g. "Bank Central Asia" or "QRIS".
     *
     * @param  array<string, mixed>  $row
     */
    public function displayName(array $row): ?string
    {
        $name = trim((string) ($row['display_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function logoUrl(array $row): ?string
    {
        $logo = trim((string) ($row['logo_url'] ?? ''));

        return $logo !== '' ? $logo : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeMeta(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
