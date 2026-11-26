<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Shopper\Core\Models\Order;

final class OrderPaymentTransactions extends Component
{
    #[Locked]
    public Order $order;

    #[On('order.updated')]
    public function refreshOrder(): void
    {
        $this->order->refresh();
    }

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user !== null && ($user->isAdmin() || Gate::allows('read_orders') || Gate::allows('browse_orders')), 403);

        $transactions = $this->order->paymentTransactions()->latest('id')->get();

        return view('shopper.livewire.order-payment-transactions', [
            'transactions' => $transactions,
        ]);
    }
}
