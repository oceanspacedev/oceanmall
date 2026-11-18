<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\ShippingCapabilities;
use Illuminate\Console\Command;
use Throwable;

/**
 * Let every configured shipping driver that SyncsCarriers align the Shopper
 * carriers it owns with what its provider offers. Each driver only touches
 * carriers.driver = its own code, so manual carriers and other providers'
 * carriers are never switched off. With no such driver configured this is a
 * successful no-op (scheduled daily).
 */
final class SyncShippingCarriers extends Command
{
    protected $signature = 'shipping:sync-carriers
        {--driver= : Only this shipping driver code}
        {--dry-run : Show what the provider offers without touching the carriers table}';

    /** @var list<string> */
    protected $aliases = ['komerce:sync-carriers'];

    protected $description = 'Sync each configured shipping driver\'s carriers from its provider (courier list, logos, enabled state)';

    public function handle(ShippingCapabilities $capabilities): int
    {
        $drivers = $capabilities->supporting(SyncsCarriers::class);
        $only = trim((string) $this->option('driver'));

        if ($only !== '') {
            $drivers = $drivers->filter(static fn ($driver, string $code): bool => $code === $only);
        }

        if ($drivers->isEmpty()) {
            $this->components->info('No configured shipping driver syncs carriers; nothing to do.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($drivers as $code => $driver) {
            try {
                $result = $driver->syncCarriers((bool) $this->option('dry-run'));
            } catch (Throwable $e) {
                report($e);
                $this->error("[{$code}] {$e->getMessage()}");
                $failed = true;

                continue;
            }

            if (! $result->successful) {
                $this->error("[{$code}] {$result->message}");
                $failed = true;

                continue;
            }

            if ($result->rows !== []) {
                $this->table($result->headers, $result->rows);
            }

            $this->info("[{$code}] {$result->message}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
