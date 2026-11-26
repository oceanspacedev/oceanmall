<div class="divide-y divide-gray-200 dark:divide-white/10">
    <div class="py-4">
        @livewire('order-finance-ops-panel', ['order' => $order], key('order-finance-ops-'.$order->id))
    </div>
    <div class="py-4">
        @livewire('order-payment-transactions', ['order' => $order], key('order-tx-'.$order->id))
    </div>
</div>
