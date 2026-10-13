<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\KomerceCapabilities;
use Illuminate\Console\Command;

final class KomerceCapabilitiesCommand extends Command
{
    protected $signature = 'komerce:capabilities
        {--json : Print the detected state as JSON}
        {--no-probe : Only show the cached state without calling the provider}';

    protected $description = 'Detect which Komerce Collaborator products this account is entitled to (plan tier is inferred from the APIs)';

    public function handle(KomerceCapabilities $capabilities): int
    {
        $state = $this->option('no-probe') ? $capabilities->state() : $capabilities->probe();

        $summary = [
            'plan' => $capabilities->plan(),
            'plan_note' => 'inferred from API entitlements and observed quota, Komerce exposes no plan endpoint',
            'shipping_cost' => [
                'entitled' => $capabilities->entitled('shipping_cost') && ($state['shipping_cost']['entitled'] ?? null) !== null,
                'requests_today' => $capabilities->requestsToday('shipping_cost'),
                'daily_quota' => $capabilities->dailyQuota('shipping_cost'),
                'quota_exhausted_until' => $capabilities->quotaExhaustedUntil('shipping_cost')?->toIso8601String(),
            ],
            'products' => $state,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];

        foreach (KomerceCapabilities::PRODUCTS as $product) {
            $entry = $state[$product] ?? [];
            $rows[] = [
                $product,
                match ($entry['entitled'] ?? null) {
                    true => 'yes',
                    false => 'NO',
                    default => 'unknown',
                },
                (string) ($entry['reason'] ?? ''),
                (string) ($entry['checked_at'] ?? '-'),
            ];
        }

        $this->table(['product', 'entitled', 'reason', 'checked_at'], $rows);
        $this->info(sprintf('Inferred plan: %s', strtoupper($capabilities->plan())));
        $this->line(sprintf(
            'Shipping Cost today: %d request(s)%s%s',
            $summary['shipping_cost']['requests_today'],
            $summary['shipping_cost']['daily_quota'] !== null ? ' of '.$summary['shipping_cost']['daily_quota'] : '',
            $summary['shipping_cost']['quota_exhausted_until'] ? ' — QUOTA EXHAUSTED until '.$summary['shipping_cost']['quota_exhausted_until'] : '',
        ));

        return self::SUCCESS;
    }
}
