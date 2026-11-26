<div class="overflow-hidden rounded-lg divide-y divide-gray-200 bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10 dark:divide-white/10">
    <div class="flex items-center justify-between gap-2 bg-gray-50 p-3 dark:bg-gray-950">
        <h4 class="text-base/5 font-semibold text-gray-900 dark:text-white">
            {{ __('shopper::pages/orders.summary') }}
        </h4>
        <x-filament::badge
            size="sm"
            :color="$order->payment_status->getColor()"
            :icon="$order->payment_status->getIcon()"
        >
            {{ $order->payment_status->getLabel() }}
        </x-filament::badge>
    </div>

    <div class="divide-y divide-gray-200 dark:divide-white/10">
        <div class="space-y-3 p-4 text-sm">
            <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                <span>
                    {{ __('shopper::words.subtotal') }}
                    <span class="text-xs">
                        ({{ $itemsCount }} item)
                    </span>
                </span>
                <span class="font-medium text-gray-900 dark:text-white om-tabular">
                    {{ shopper_money_format($subtotal, $order->currency_code) }}
                </span>
            </div>

            @if ($discountAmount > 0)
                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                    <span>Diskon Promo</span>
                    <span class="font-medium text-warning-600 dark:text-warning-400 om-tabular">
                        -{{ shopper_money_format($discountAmount, $order->currency_code) }}
                    </span>
                </div>
            @endif

            <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-2">
                    {{ __('shopper::words.shipping') }}
                    @if ($carrierLogoUrl)
                        <img src="{{ $carrierLogoUrl }}" alt="" class="h-4 w-auto object-contain" />
                    @endif
                </span>
                <span class="font-medium text-gray-900 dark:text-white om-tabular">
                    {{ shopper_money_format($shippingPrice, $order->currency_code) }}
                </span>
            </div>

            @if ($taxAmount > 0)
                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                    <span>
                        {{ \Illuminate\Support\Facades\Lang::has('shopper::words.tax') ? __('shopper::words.tax') : 'Pajak' }}
                        {{ $isTaxInclusive ? '(Inklusif)' : '' }}
                    </span>
                    <span class="font-medium text-gray-900 dark:text-white om-tabular">
                        {{ shopper_money_format($taxAmount, $order->currency_code) }}
                    </span>
                </div>
            @endif

            @if ($order->paymentMethod)
                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-2">
                        {{ __('shopper::forms.label.payment_method') }}
                        @if ($paymentLogoUrl)
                            <img src="{{ $paymentLogoUrl }}" alt="" class="h-4 w-auto object-contain" />
                        @endif
                    </span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $order->paymentMethod->title }}</span>
                </div>
            @endif
        </div>

        <div class="space-y-3 p-4">
            <div class="flex items-center justify-between text-sm font-semibold text-gray-900 dark:text-white">
                <span>{{ __('shopper::words.total') }}</span>
                <span class="om-tabular">{{ shopper_money_format($total, $order->currency_code) }}</span>
            </div>

            @if ($refundedTotal > 0)
                <div class="flex items-center justify-between text-xs text-danger-600 dark:text-danger-400">
                    <span>Total Refund</span>
                    <span class="font-medium om-tabular">-{{ shopper_money_format($refundedTotal, $order->currency_code) }}</span>
                </div>
            @endif

            @if ($canViewFinance)
                <div class="space-y-1.5 rounded-lg bg-gray-50 p-3 text-xs ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-white/10">
                    @if ($gatewayFee > 0)
                        <div class="flex justify-between text-gray-500 dark:text-gray-400">
                            <span>Biaya Gateway</span>
                            <span class="om-tabular">{{ shopper_money_format($gatewayFee, $order->currency_code) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-gray-600 dark:text-gray-300">
                        <span>Net Settlement</span>
                        <span class="font-medium om-tabular">{{ shopper_money_format($netSettlement, $order->currency_code) }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600 dark:text-gray-300">
                        <span>HPP (COGS Snapshot)</span>
                        <span class="font-medium om-tabular">{{ shopper_money_format($cogsTotal, $order->currency_code) }}</span>
                    </div>
                    <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold text-success-600 dark:border-white/10 dark:text-success-400">
                        <span>Estimasi Laba Kotor</span>
                        <span class="om-tabular">{{ shopper_money_format($grossProfit, $order->currency_code) }}</span>
                    </div>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 bg-gray-50 px-4 py-3 text-xs dark:bg-gray-950">
            <div class="flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">Invoice:</span>
                <x-filament::badge size="sm" :color="$order->invoice_number ? 'primary' : 'gray'">
                    {{ $order->invoice_number ?: 'Draft' }}
                </x-filament::badge>
            </div>
            <x-filament::button
                tag="a"
                :href="route('cpanel.orders.invoice', ['order' => $order])"
                target="_blank"
                size="xs"
                color="gray"
                icon="heroicon-o-printer"
            >
                Cetak Invoice
            </x-filament::button>
        </div>
    </div>
</div>
