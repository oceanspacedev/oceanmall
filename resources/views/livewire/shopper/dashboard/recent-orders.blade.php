<x-shopper::card class="[&>div:first-of-type]:p-0">
    <div class="flex items-center justify-between p-4">
        <h3 class="font-heading text-base font-semibold text-gray-900 dark:text-white">
            Pesanan Terbaru
        </h3>
        <x-shopper::link
            :href="route('shopper.orders.index')"
            wire:navigate
            class="inline-flex items-center gap-1 text-sm font-medium text-primary-500 transition-colors hover:text-primary-700 dark:text-gray-400 dark:hover:text-white"
        >
            Lihat Semua
            <x-untitledui-arrow-narrow-right class="size-3.5" />
        </x-shopper::link>
    </div>

    <div class="overflow-x-auto border-t border-gray-200 dark:border-white/10">
        @if ($orders->isNotEmpty())
            <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell px-3 py-2 sm:first-of-type:ps-6">
                            <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">No. Pesanan</span>
                        </th>
                        <th class="fi-ta-header-cell px-3 py-2">
                            <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Pelanggan</span>
                        </th>
                        <th class="fi-ta-header-cell px-3 py-2 text-right">
                            <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Nominal</span>
                        </th>
                        <th class="fi-ta-header-cell px-3 py-2">
                            <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Pembayaran</span>
                        </th>
                        <th class="fi-ta-header-cell px-3 py-2 sm:last-of-type:pe-6">
                            <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Status</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                    @foreach ($orders as $order)
                        <tr
                            class="cursor-pointer transition-colors hover:bg-gray-50 dark:hover:bg-white/5"
                            x-on:click="window.Livewire.navigate('{{ route('shopper.orders.detail', ['order' => $order]) }}')"
                        >
                            <td class="fi-ta-cell px-3 py-2.5 sm:first-of-type:ps-6">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                    #{{ $order->number }}
                                </span>
                                @if ($order->invoice_number)
                                    <span class="ml-1.5 text-xs text-gray-400">({{ $order->invoice_number }})</span>
                                @endif
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 text-sm text-gray-600 dark:text-gray-300">
                                {{ $order->customer?->full_name ?: 'Tamu' }}
                                <span class="text-xs text-gray-400">· {{ $order->created_at?->diffForHumans() }}</span>
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 text-right text-sm font-medium text-gray-900 dark:text-white om-tabular">
                                Rp {{ number_format((int) $order->price_amount, 0, ',', '.') }}
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5">
                                <x-filament::badge
                                    size="sm"
                                    :color="$order->payment_status->getColor()"
                                    :icon="$order->payment_status->getIcon()"
                                >
                                    {{ $order->payment_status->getLabel() }}
                                </x-filament::badge>
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 sm:last-of-type:pe-6">
                                <x-filament::badge
                                    size="sm"
                                    :color="$order->status->getColor()"
                                    :icon="$order->status->getIcon()"
                                >
                                    {{ $order->status->getLabel() }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="p-6 text-center">
                <x-untitledui-shopping-bag-02 class="mx-auto size-8 text-gray-300 dark:text-gray-600" />
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Belum ada pesanan.</p>
            </div>
        @endif
    </div>
</x-shopper::card>
