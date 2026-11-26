<x-shopper::card>
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pesanan Terbaru</h3>
        <a href="{{ route('shopper.orders.index') }}" class="text-xs font-medium text-primary-600 hover:underline">
            Lihat Semua &rarr;
        </a>
    </div>
    <div class="mt-3 divide-y divide-gray-100 text-xs dark:divide-white/10">
        @forelse ($orders as $order)
            <div class="flex items-center justify-between py-2.5">
                <div>
                    <a href="{{ route('shopper.orders.detail', ['order' => $order]) }}" class="font-semibold text-gray-900 hover:text-primary-600 dark:text-white">
                        {{ $order->number }}
                    </a>
                    @if ($order->invoice_number)
                        <span class="ml-1.5 text-[11px] text-gray-400">({{ $order->invoice_number }})</span>
                    @endif
                    <p class="text-[11px] text-gray-500">{{ $order->customer?->full_name ?: 'Tamu' }} · {{ $order->created_at?->diffForHumans() }}</p>
                </div>
                <div class="text-right">
                    <p class="font-semibold text-gray-900 dark:text-white">
                        Rp {{ number_format((int) $order->price_amount, 0, ',', '.') }}
                    </p>
                    <p class="text-[11px] text-gray-500">{{ strtoupper((string) $order->payment_status->value) }} / {{ strtoupper((string) $order->status->value) }}</p>
                </div>
            </div>
        @empty
            <p class="py-4 text-center text-gray-500">Belum ada pesanan.</p>
        @endforelse
    </div>
</x-shopper::card>
