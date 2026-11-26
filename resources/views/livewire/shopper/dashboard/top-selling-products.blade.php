<x-shopper::card class="[&>div:first-of-type]:p-0">
    <div class="flex items-center justify-between p-4">
        <h3 class="font-heading text-base font-semibold text-gray-900 dark:text-white">
            Produk Terlaris (Lunas)
        </h3>
        <x-shopper::link
            :href="route('shopper.products.index')"
            wire:navigate
            class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
        >
            Lihat Produk
            <x-untitledui-arrow-narrow-right class="size-3.5" />
        </x-shopper::link>
    </div>

    <div class="border-t border-gray-200 dark:border-white/10">
        @if ($items->isNotEmpty())
            <table class="fi-ta-table w-full table-fixed divide-y divide-gray-200 text-start dark:divide-white/10">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell px-3 py-2 sm:first-of-type:ps-6">
                            <span class="fi-ta-header-cell-label text-xs font-semibold text-gray-950 dark:text-white">Produk</span>
                        </th>
                        <th class="fi-ta-header-cell w-16 px-3 py-2 text-right">
                            <span class="fi-ta-header-cell-label text-xs font-semibold text-gray-950 dark:text-white">Qty</span>
                        </th>
                        <th class="fi-ta-header-cell w-28 px-3 py-2 text-right sm:last-of-type:pe-6">
                            <span class="fi-ta-header-cell-label text-xs font-semibold text-gray-950 dark:text-white">Omzet</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/10">
                    @foreach ($items as $item)
                        <tr>
                            <td class="fi-ta-cell overflow-hidden px-3 py-2.5 sm:first-of-type:ps-6">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $item->name }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">SKU: {{ $item->sku ?: '-' }}</p>
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 text-right text-sm text-gray-700 dark:text-gray-300 om-tabular">
                                {{ (int) $item->total_qty }}
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 text-right text-sm font-medium text-gray-900 dark:text-white sm:last-of-type:pe-6 om-tabular">
                                Rp {{ number_format((int) $item->total_sales, 0, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="p-6 text-center">
                <x-untitledui-package class="mx-auto size-8 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Belum ada penjualan dibayar.</p>
            </div>
        @endif
    </div>
</x-shopper::card>
