<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Couriers supported by the RajaOngkir Shipping Cost API. The code list comes
 * from the API's own validation error (17 domestic couriers, the free
 * Collaborator plan); the docs page lists a subset.
 */
final class RajaOngkirCouriers
{
    /**
     * Domestic couriers the Shipping Cost API accepts in `courier`, exactly as
     * its own validation message lists them (checked live 2026-10-13):
     * "the valid courier is jne, sicepat, ide, sap, jnt, ninja, tiki, lion,
     * anteraja, pos, ncs, rex, rpx, sentral, star, wahana, spx".
     *
     * @var array<string, string> code => courier name
     */
    public const OFFICIAL = [
        'jne' => 'JNE',
        'sicepat' => 'SiCepat',
        'ide' => 'IDExpress',
        'sap' => 'SAP Express',
        'jnt' => 'J&T Express',
        'ninja' => 'Ninja Xpress',
        'tiki' => 'TIKI',
        'lion' => 'Lion Parcel',
        'anteraja' => 'AnterAja',
        'pos' => 'POS Indonesia',
        'ncs' => 'NCS',
        'rex' => 'Royal Express Asia',
        'rpx' => 'RPX',
        'sentral' => 'Sentral Cargo',
        'star' => 'Star Cargo',
        'wahana' => 'Wahana Express',
        'spx' => 'SPX',
    ];

    /** @var array<string, string> Komerce Delivery `shipping_name` => RajaOngkir code */
    public const KOMERCE_NAMES = [
        'JNE' => 'jne',
        'JNT' => 'jnt',
        'J&T' => 'jnt',
        'SICEPAT' => 'sicepat',
        'IDEXPRESS' => 'ide',
        'IDE' => 'ide',
        'SAP' => 'sap',
        'NINJA' => 'ninja',
        'TIKI' => 'tiki',
        'WAHANA' => 'wahana',
        'POS' => 'pos',
        'SENTRAL' => 'sentral',
        'LION' => 'lion',
        'REX' => 'rex',
        'SPX' => 'spx',
        'ANTERAJA' => 'anteraja',
        'NCS' => 'ncs',
        'RPX' => 'rpx',
        'STAR' => 'star',
    ];

    /**
     * Resolve a Komerce Delivery `shipping_name` (e.g. "IDEXPRESS") to the
     * RajaOngkir courier code, or null when Komerce ships with a courier that
     * is not part of the RajaOngkir Shipping Cost list.
     */
    public static function codeFromKomerceName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $key = strtoupper(preg_replace('/[^A-Z&]/i', '', $name) ?? '');

        return self::KOMERCE_NAMES[$key] ?? (self::isOfficial($key) ? strtolower($key) : null);
    }

    public static function isOfficial(?string $code): bool
    {
        return $code !== null && array_key_exists(strtolower(trim($code)), self::OFFICIAL);
    }

    public static function name(string $code): ?string
    {
        return self::OFFICIAL[strtolower(trim($code))] ?? null;
    }

    /**
     * Keep only codes RajaOngkir actually quotes, preserving order.
     *
     * @param  iterable<int, string>  $codes
     * @return list<string>
     */
    public static function filter(iterable $codes): array
    {
        $kept = [];

        foreach ($codes as $code) {
            $normalized = strtolower(trim((string) $code));

            if (self::isOfficial($normalized) && ! in_array($normalized, $kept, true)) {
                $kept[] = $normalized;
            }
        }

        return $kept;
    }

    /**
     * Courier codes the storefront advertises: enabled cpanel carriers (synced
     * from Komerce Delivery), narrowed by the optional RAJAONGKIR_COURIERS override.
     *
     * @return list<string>
     */
    public static function quotable(): array
    {
        $configured = array_map(
            static fn (mixed $code): string => strtolower(trim((string) $code)),
            (array) config('komerce.couriers', []),
        );

        $enabled = EnabledCarriers::rajaOngkirSlugs();
        $kept = [];

        foreach ($enabled as $slug) {
            if (($configured === [] || in_array($slug, $configured, true)) && ! in_array($slug, $kept, true)) {
                $kept[] = $slug;
            }
        }

        return $kept;
    }
}
