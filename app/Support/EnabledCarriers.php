<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Zone;

final class EnabledCarriers
{
    /**
     * Carrier metadata key holding the provider's courier code. A driver that
     * syncs carriers (SyncsCarriers) writes it, so the Shopper slug stays free
     * (two providers can each own a "jne" courier under different slugs).
     */
    public const COURIER_CODE = 'courier_code';

    /**
     * Enabled carriers for storefront display (global cpanel list).
     *
     * @return Collection<int, Carrier>
     */
    public static function forDisplay(?Zone $zone = null): Collection
    {
        return self::baseQuery($zone)->get();
    }

    /**
     * Enabled carriers a shipping driver owns (carriers.driver = $driver).
     *
     * @return Collection<int, Carrier>
     */
    public static function ownedBy(string $driver, ?Zone $zone = null): Collection
    {
        return self::baseQuery($zone)->where('driver', $driver)->get();
    }

    /**
     * The provider courier code of a carrier: metadata courier_code, else the
     * slug (rows created before the code was recorded).
     */
    public static function courierCode(Carrier $carrier): string
    {
        $code = data_get($carrier->metadata, self::COURIER_CODE);

        return is_scalar($code) && trim((string) $code) !== ''
            ? strtolower(trim((string) $code))
            : strtolower(trim((string) $carrier->slug));
    }

    /**
     * @return Builder<Carrier>
     */
    private static function baseQuery(?Zone $zone = null): Builder
    {
        $query = Carrier::query()->where('is_enabled', true);

        if ($zone !== null) {
            $carrierIds = $zone->carriers()->allRelatedIds();
            $query->whereIn('id', $carrierIds);
        }

        return $query->orderBy('id');
    }
}
