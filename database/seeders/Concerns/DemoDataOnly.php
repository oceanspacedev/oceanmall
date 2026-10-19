<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

/**
 * Fake customers, orders, reviews, coupons and known-password accounts must
 * never reach production — not via DatabaseSeeder, not via --class=.
 */
trait DemoDataOnly
{
    protected function refuseInProduction(): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        $this->command?->warn(static::class.' skipped: demo data is never seeded in production.');

        return true;
    }
}
