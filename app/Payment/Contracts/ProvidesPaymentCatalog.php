<?php

declare(strict_types=1);

namespace App\Payment\Contracts;

/**
 * A payment driver that publishes the methods/channels the merchant account
 * can actually use, so checkout and the storefront show only those.
 */
interface ProvidesPaymentCatalog
{
    /**
     * @return list<array<string, mixed>>|null Provider catalog rows; null when unavailable
     */
    public function paymentCatalog(): ?array;

    /**
     * Match a Shopper payment method's metadata against the catalog.
     *
     * @param  array<string, mixed>  $methodMetadata
     * @param  list<array<string, mixed>>  $catalog
     * @return array{display_name: string|null, logo_url: string|null, entry: array<string, mixed>}|null Null when the account does not offer this method
     */
    public function catalogEntryFor(array $methodMetadata, array $catalog): ?array;

    /**
     * Whether this store's own configuration can take payments with the
     * method (e.g. QR needs the QR product, a VA needs the VA product),
     * before the catalog is consulted.
     *
     * @param  array<string, mixed>  $methodMetadata
     */
    public function methodAvailable(array $methodMetadata): bool;
}
