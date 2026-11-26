<x-shopper::card>
    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Produk Terlaris (Lunas)</h3>
    <div class="mt-3 divide-y divide-gray-100 text-xs dark:divide-white/10">
        @forelse ($items as $item)
            <div class="flex items-center justify-between py-2.5">
                <div>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $item->name }}</p>
                    <p class="text-[11px] text-gray-500">SKU: {{ $item->sku ?: '-' }} · {{ (int) $item->total_qty }} terjual</p>
                </div>
                <span class="font-semibold text-gray-900 dark:text-white">
                    Rp {{ number_format((int) $item->total_sales, 0, ',', '.') }}
                </span>
            </div>
        @empty
            <p class="py-4 text-center text-gray-500">Belum ada penjualan dibayar.</p>
        @endforelse
    </div>
</x-shopper::card>
