<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Dashboard;

use App\Support\FinanceReportService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class StatCards extends Component
{
    public string $period = '30d';

    public function setPeriod(string $period): void
    {
        if (in_array($period, ['7d', '30d', 'mtd', 'ytd'], true)) {
            $this->period = $period;
        }
    }

    public function updatedPeriod(string $period): void
    {
        if (! in_array($period, ['7d', '30d', 'mtd', 'ytd'], true)) {
            $this->period = '30d';
        }
    }

    public function render(FinanceReportService $service): View
    {
        abort_unless(Gate::allows('finance-dashboard'), 403);

        [$from, $to] = $this->range();
        $summary = $service->profitAndMargin($from, $to)['summary'];
        $aov = $summary['orders'] > 0 ? (int) round($summary['net_revenue'] / $summary['orders']) : 0;

        return view('livewire.shopper.dashboard.stat-cards', [
            'summary' => $summary,
            'aov' => $aov,
        ]);
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function range(): array
    {
        $to = now();
        $from = match ($this->period) {
            '7d' => now()->subDays(6),
            'mtd' => now()->startOfMonth(),
            'ytd' => now()->startOfYear(),
            default => now()->subDays(29),
        };

        return [$from, $to];
    }
}
