<x-shopper::card :title="__('shopper::pages/orders.summary')">
    <div class="space-y-3 text-sm">
        <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
            <span>{{ __('shopper::words.subtotal') }} ({{ $itemsCount }} item)</span>
            <span class="font-medium text-gray-900 dark:text-white">{{ shopper_money_format($subtotal, $order->currency_code) }}</span>
        </div>

        @if ($discountAmount > 0)
            <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                <span>Diskon Promo</span>
                <span class="font-medium text-amber-600">-{{ shopper_money_format($discountAmount, $order->currency_code) }}</span>
            </div>
        @endif

        <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-2">
                {{ __('shopper::words.shipping') }}
                @if ($carrierLogoUrl)
                    <img src="{{ $carrierLogoUrl }}" alt="" class="h-4 w-auto object-contain" />
                @endif
            </span>
            <span class="font-medium text-gray-900 dark:text-white">{{ shopper_money_format($shippingPrice, $order->currency_code) }}</span>
        </div>

        @if ($taxAmount > 0)
            <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                <span>{{ __('shopper::words.tax') }} {{ $isTaxInclusive ? '(Inklusif)' : '' }}</span>
                <span class="font-medium text-gray-900 dark:text-white">{{ shopper_money_format($taxAmount, $order->currency_code) }}</span>
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

        <div class="flex items-center justify-between border-t border-gray-200 pt-3 font-semibold text-gray-900 dark:border-white/10 dark:text-white">
            <span>{{ __('shopper::words.total') }}</span>
            <span>{{ shopper_money_format($total, $order->currency_code) }}</span>
        </div>

        @if ($refundedTotal > 0)
            <div class="flex items-center justify-between text-xs text-rose-600">
                <span>Total Refund</span>
                <span>-{{ shopper_money_format($refundedTotal, $order->currency_code) }}</span>
            </div>
        @endif

        @if ($canViewFinance)
            <div class="mt-2 space-y-1.5 rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5">
                @if ($gatewayFee > 0)
                    <div class="flex justify-between text-gray-500 dark:text-gray-400">
                        <span>Biaya Gateway</span>
                        <span>{{ shopper_money_format($gatewayFee, $order->currency_code) }}</span>
                    </div>
                @endif
                <div class="flex justify-between text-gray-600 dark:text-gray-300">
                    <span>Net Settlement</span>
                    <span class="font-medium">{{ shopper_money_format($netSettlement, $order->currency_code) }}</span>
                </div>
                <div class="flex justify-between text-gray-600 dark:text-gray-300">
                    <span>HPP (COGS Snapshot)</span>
                    <span class="font-medium">{{ shopper_money_format($cogsTotal, $order->currency_code) }}</span>
                </div>
                <div class="flex justify-between font-semibold text-emerald-600">
                    <span>Estimasi Laba Kotor</span>
                    <span>{{ shopper_money_format($grossProfit, $order->currency_code) }}</span>
                </div>
            </div>
        @endif

        <div class="border-t border-gray-200 pt-3 dark:border-white/10 flex items-center justify-between text-xs">
            <span class="text-gray-500">Invoice: <strong>{{ $order->invoice_number ?: 'Draft' }}</strong></span>
            <a
                href="{{ route('cpanel.orders.invoice', ['order' => $order]) }}"
                target="_blank"
                rel="noopener"
                class="font-semibold text-primary-600 hover:underline"
            >
                Cetak Invoice &rarr;
            </a>
        </div>
    </div>
</x-shopper::card>
