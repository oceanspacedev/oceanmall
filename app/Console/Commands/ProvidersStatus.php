<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ActiveProvider;
use App\Support\OperatorSwitch;
use Illuminate\Console\Command;

/**
 * Which payment and shipping provider the store sells through right now, and
 * which keyed providers are standing by (App\Support\ActiveProvider).
 */
final class ProvidersStatus extends Command
{
    protected $signature = 'providers:status';

    protected $description = 'Show the one payment and the one shipping provider the store sells through';

    public function handle(): int
    {
        $rows = [];
        $warnings = [];
        $ambiguous = false;

        foreach ([OperatorSwitch::PAYMENT => 'PAYMENT_PROVIDER', OperatorSwitch::SHIPPING => 'SHIPPING_PROVIDER'] as $section => $env) {
            $setting = ActiveProvider::setting($section);
            $configured = ActiveProvider::configuredFamilies($section);
            $selected = ActiveProvider::family($section);
            $selling = $selected !== null && in_array($selected, $configured, true) ? $selected : null;
            $standby = array_values(array_diff($configured, [$selling]));

            if ($setting === null && count($configured) > 1) {
                $ambiguous = true;
            }

            if ($setting !== null && $selected !== 'manual' && $selling === null) {
                $warnings[] = in_array($selected, ActiveProvider::knownFamilies($section), true)
                    ? "{$env}={$setting} is switched off or has no usable key: {$section} runs on Shopper manual until that is fixed."
                    : "{$env}={$setting} names no {$section} provider of this store: {$section} runs on Shopper manual.";
            }

            $rows[] = [
                $section,
                $setting === null ? "(empty: first configured, set {$env} to choose)" : $setting,
                $selling ?? 'none (Shopper manual)',
                $standby === [] ? '-' : implode(', ', $standby),
            ];
        }

        $this->table(['Section', 'Setting', 'Sells through', 'Keyed, standing by'], $rows);

        if ($ambiguous) {
            $this->warn('Several providers hold a key and no setting chooses between them: the first registered one sells. Set PAYMENT_PROVIDER / SHIPPING_PROVIDER to make the choice explicit.');
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        $this->line('Providers standing by take no new orders; they still finish the orders they already hold.');

        return self::SUCCESS;
    }
}
