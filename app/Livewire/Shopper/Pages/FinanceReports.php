<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Orders\RemitCodOrder;
use App\Models\User;
use App\Support\FinanceReportService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Shopper\Core\Models\Order;

#[Layout('shopper::components.layouts.app')]
class FinanceReports extends Component
{
    public string $activeTab = 'margin';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('finance-dashboard'), 403);

        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['margin', 'ppn', 'promo', 'settlement'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function markCodRemitted(int $orderId): void
    {
        abort_unless(Gate::allows('orders-mark-paid'), 403);

        $actor = Auth::user();
        if (! $actor instanceof User) {
            return;
        }

        $order = Order::query()->findOrFail($orderId);
        resolve(RemitCodOrder::class)->handle($order, $actor);

        Notification::make()
            ->title("Setoran COD untuk pesanan {$order->number} telah dicatat.")
            ->success()
            ->send();
    }

    public function render(FinanceReportService $service): View
    {
        abort_unless(Gate::allows('finance-dashboard'), 403);

        $from = Carbon::parse($this->dateFrom ?: now()->subDays(29)->toDateString());
        $to = Carbon::parse($this->dateTo ?: now()->toDateString());

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $report = match ($this->activeTab) {
            'ppn' => $service->ppnReport($from, $to),
            'promo' => $service->promoRoi($from, $to),
            'settlement' => $service->settlementAndCod($from, $to),
            default => $service->profitAndMargin($from, $to),
        };

        return view('livewire.shopper.pages.finance-reports', [
            'report' => $report,
            'fromDate' => $from->toDateString(),
            'toDate' => $to->toDateString(),
        ])->title('Laporan Keuangan, Pajak & Settlement');
    }
}
