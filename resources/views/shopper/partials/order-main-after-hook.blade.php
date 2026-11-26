<div class="space-y-6">
    @livewire('order-finance-ops-panel', ['order' => $order], key('order-finance-ops-'.$order->id))
    @livewire('order-payment-transactions', ['order' => $order], key('order-tx-'.$order->id))
</div>
