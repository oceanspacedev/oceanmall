<div>
    <div class="flex items-center justify-between">
        <h4 class="text-sm leading-5 font-medium text-gray-900 dark:text-white">
            {{ __('shopper::pages/products.quantity_inventory') }}
        </h4>
        <div class="ml-4 flex items-center">
            {{ $this->stockAction }}
        </div>
    </div>

    <x-filament-actions::modals />

    <div class="mt-5 overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <x-shopper::tables.table-head>
                    {{ __('shopper::pages/products.inventory_name') }}
                </x-shopper::tables.table-head>
                <x-shopper::tables.table-head class="text-right">
                    {{ __('shopper::words.available') }}
                </x-shopper::tables.table-head>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10" x-max="1">
                @foreach ($this->inventories as $inventory)
                    <tr>
                        <x-shopper::tables.table-cell class="whitespace-no-wrap">
                            <div class="flex items-center gap-2">
                                {{ $inventory->name }}

                                @if ($inventory->is_default)
                                    <x-filament::badge color="gray">
                                        {{ __('shopper::words.default') }}
                                    </x-filament::badge>
                                @endif
                            </div>
                        </x-shopper::tables.table-cell>
                        <x-shopper::tables.table-cell class="text-right">
                            {{ $variant->stockInventory($inventory->id) }}
                        </x-shopper::tables.table-cell>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        <h5 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
            Riwayat Mutasi Stok Varian (Terbaru)
        </h5>
        <div class="mt-2 overflow-x-auto rounded-xl bg-white ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <x-shopper::tables.table-head>Waktu</x-shopper::tables.table-head>
                        <x-shopper::tables.table-head>Gudang</x-shopper::tables.table-head>
                        <x-shopper::tables.table-head>Alasan / Catatan</x-shopper::tables.table-head>
                        <x-shopper::tables.table-head>Oleh</x-shopper::tables.table-head>
                        <x-shopper::tables.table-head class="text-right">Delta</x-shopper::tables.table-head>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->histories as $history)
                        <tr>
                            <x-shopper::tables.table-cell class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ $history->created_at?->diffForHumans() }}
                            </x-shopper::tables.table-cell>
                            <x-shopper::tables.table-cell class="text-gray-900 dark:text-gray-100">
                                {{ $history->inventory?->name ?? '-' }}
                            </x-shopper::tables.table-cell>
                            <x-shopper::tables.table-cell class="text-gray-600 dark:text-gray-300">
                                {{ $history->description ?: ($history->event ?: '-') }}
                            </x-shopper::tables.table-cell>
                            <x-shopper::tables.table-cell class="text-gray-600 dark:text-gray-300">
                                {{ $history->user?->full_name ?? '-' }}
                            </x-shopper::tables.table-cell>
                            <x-shopper::tables.table-cell class="text-right">
                                <x-filament::badge size="sm" :color="$history->quantity < 0 ? 'danger' : 'success'">
                                    {{ $history->quantity > 0 ? '+' . $history->quantity : $history->quantity }}
                                </x-filament::badge>
                            </x-shopper::tables.table-cell>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                Belum ada riwayat mutasi stok.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
