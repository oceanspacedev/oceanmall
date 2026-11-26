<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Dashboard;

use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\PaymentDispute;
use App\Support\ProviderQuotaRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

final class OperationalAlerts extends Component
{
    public function render(ProviderQuotaRegistry $quotas): View
    {
        $pendingProofs = OrderPaymentProof::query()->where('status', 'pending')->count();
        $openDisputes = PaymentDispute::query()->where('status', 'open')->count();
        $pendingReturns = OrderReturnRequest::query()->where('status', 'requested')->count();
        $overdueShipments = Order::query()
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('shipping_status', 'unshipped')
            ->where(static function ($q): void {
                $cutoff = now()->subDay();
                $q->where('paid_at', '<', $cutoff)
                    ->orWhere(static fn ($f) => $f->whereNull('paid_at')->where('created_at', '<', $cutoff));
            })
            ->count();

        return view('livewire.shopper.dashboard.operational-alerts', [
            'pendingProofs' => $pendingProofs,
            'openDisputes' => $openDisputes,
            'pendingReturns' => $pendingReturns,
            'overdueShipments' => $overdueShipments,
            'providerQuotas' => $quotas->all(),
        ]);
    }
}
