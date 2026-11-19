<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Support;

/**
 * Biteship courier codes the module knows by name.
 *
 * Instant / same-day couriers (Gojek, Grab, Lalamove...) need origin AND
 * destination coordinates for every rate and order. The store cannot promise
 * a customer pin (mobile builds collect none, see
 * App\Shipping\Contracts\RequiresCoordinates), so this module does not
 * implement RequiresCoordinates and keeps those couriers out: the carrier
 * sync never enables them and rates never quote them. A courier also counts
 * as instant when every service Biteship lists for it is measured in hours.
 */
final class BiteshipCouriers
{
    /** @var array<string, string> courier_code => name, the default carrier set */
    public const DEFAULTS = [
        'jne' => 'JNE',
        'jnt' => 'J&T Express',
        'sicepat' => 'SiCepat',
        'anteraja' => 'AnterAja',
        'pos' => 'POS Indonesia',
        'idexpress' => 'ID Express',
        'ninja' => 'Ninja Xpress',
        'lion' => 'Lion Parcel',
        'sap' => 'SAP Express',
        'tiki' => 'TIKI',
    ];

    /** @var list<string> */
    public const INSTANT = ['gojek', 'grab', 'lalamove', 'borzo', 'deliveree', 'rara', 'paxel', 'mrspeedy'];

    /** @var array<string, string> courier_code => local logo slug (public/images/couriers) */
    public const LOGO_ALIASES = [
        'idexpress' => 'ide',
    ];

    public static function isInstant(string $code): bool
    {
        return in_array(strtolower(trim($code)), self::INSTANT, true);
    }

    public static function name(string $code): ?string
    {
        return self::DEFAULTS[strtolower(trim($code))] ?? null;
    }
}
