<?php

declare(strict_types=1);

namespace App\Shipping\Contracts;

use Shopper\Core\Models\Inventory;

/**
 * A shipping driver that owns a destination vocabulary (area / subdistrict
 * ids) and can search it, e.g. RajaOngkir destination ids, Biteship area ids.
 *
 * Address metadata stores the chosen id under the neutral `destination_id`
 * key together with `destination_driver` = this driver's code().
 */
interface ResolvesDestinations
{
    /**
     * Search destinations for an address form autocomplete.
     *
     * Each row has `id` (string) and `label` (string), and SHOULD carry the
     * neutral region keys the address forms copy into the address:
     *
     *   postal_code  string|null   e.g. "12160"
     *   city         string|null   city / regency
     *   state        string|null   province
     *   district     string|null   kecamatan (informational)
     *
     * A key may be null or missing when the provider does not know it: the
     * web and mobile forms then keep that field editable and the customer
     * types it. Rows go through AddressDestination::searchRow() before they
     * reach a client, which fills missing neutral keys with null. Extra keys
     * are passed through untouched.
     *
     * @return list<array<string, mixed>>
     */
    public function searchDestinations(string $query, int $limit = 10): array;

    /**
     * Resolve the provider origin for a warehouse from its address, or null
     * when no single verified match exists.
     *
     * @return array{id: string, label?: string|null}|null
     */
    public function resolveOrigin(Inventory $inventory): ?array;
}
