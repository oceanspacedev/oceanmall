<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Support;

/**
 * KiriminAja express courier codes (POST /api/mitra/couriers, `datas[].code`)
 * and the subset the store seeds by default. Instant couriers (GoSend, Grab
 * Express, Borzo) use a separate API this module does not implement.
 */
final class KiriminAjaCouriers
{
    /** @var array<string, string> code => name, seeded by KiriminAjaCarrierSeeder */
    public const DEFAULTS = [
        'jne' => 'JNE Express',
        'jnt' => 'J&T Express',
        'sicepat' => 'SiCepat Ekspres',
        'anteraja' => 'AnterAja',
        'posindonesia' => 'POS Indonesia',
        'idx' => 'ID Express',
        'ninja' => 'Ninja Xpress',
        'lion' => 'Lion Parcel',
        'sap' => 'SAP Express',
        'tiki' => 'TIKI',
    ];

    /** @var list<string> */
    public const INSTANT = ['gosend', 'grab_express', 'borzo'];

    /**
     * Local logo file names (public/images/couriers) for codes that differ.
     *
     * @var array<string, string>
     */
    public const LOGO_ALIASES = [
        'posindonesia' => 'pos',
        'idx' => 'ide',
    ];

    public static function name(string $code): ?string
    {
        return self::DEFAULTS[strtolower(trim($code))] ?? null;
    }

    public static function isExpressCode(string $code): bool
    {
        $code = strtolower(trim($code));

        return preg_match('/^[a-z0-9_-]{2,30}$/', $code) === 1 && ! in_array($code, self::INSTANT, true);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function filter(array $codes): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $code): string => strtolower(trim($code)), $codes),
            self::isExpressCode(...),
        )));
    }
}
