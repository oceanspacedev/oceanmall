<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Dashboard;

use App\Support\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class RevenueChart extends Component
{
    public function render(FinanceReportService $service): View
    {
        abort_unless(Gate::allows('finance-dashboard'), 403);

        $from = now()->subDays(13);
        $to = now();
        $rows = $service->profitAndMargin($from, $to)['rows'];

        $buckets = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->timezone('Asia/Jakarta')->format('Y-m-d');
            $buckets[$day] = ['date' => $day, 'label' => Carbon::parse($day)->format('d M'), 'revenue' => 0, 'profit' => 0];
        }

        foreach ($rows as $row) {
            $day = substr((string) $row['paid_at'], 0, 10);
            if (isset($buckets[$day])) {
                $buckets[$day]['revenue'] += (int) $row['net_revenue'];
                $buckets[$day]['profit'] += (int) $row['gross_profit'];
            }
        }

        $series = array_values($buckets);
        $max = max(1, (int) max(array_column($series, 'revenue')));

        return view('livewire.shopper.dashboard.revenue-chart', [
            'series' => $series,
            'maxRevenue' => $max,
        ]);
    }
}
