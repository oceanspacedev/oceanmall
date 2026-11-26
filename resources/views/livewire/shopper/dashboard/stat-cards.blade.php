<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="font-heading text-base font-semibold text-gray-900 dark:text-white">
                Ringkasan Kinerja Keuangan (Lunas)
            </h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Omzet bersih dikurangi refund, estimasi laba kotor, jumlah pesanan dibayar, dan AOV.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            @foreach (['7d' => '7 Hari', '30d' => '30 Hari', 'mtd' => 'Bulan Ini', 'ytd' => 'Tahun Ini'] as $key => $label)
                <x-filament::button
                    size="xs"
                    :color="$period === $key ? 'primary' : 'gray'"
                    wire:click="setPeriod('{{ $key }}')"
                >
                    {{ $label }}
                </x-filament::button>
            @endforeach
        </div>
    </div>

    <div class="om-stat-grid-4">
        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Pendapatan Bersih</span>
                    <x-heroicon-o-banknotes class="size-5 text-gray-500 dark:text-gray-400" />
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-gray-900 dark:text-white om-tabular">
                Rp {{ number_format($summary['net_revenue'], 0, ',', '.') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Net Revenue periode terpilih</p>
        </x-shopper::card>

        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Laba Kotor</span>
                    <x-heroicon-o-arrow-trending-up class="size-5 text-success-600 dark:text-success-400" />
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-success-600 dark:text-success-400 om-tabular">
                Rp {{ number_format($summary['gross_profit'], 0, ',', '.') }}
            </p>
            <div class="mt-1.5 flex items-center gap-2">
                <x-filament::badge size="sm" color="success">
                    Margin {{ $summary['margin_pct'] }}%
                </x-filament::badge>
            </div>
        </x-shopper::card>

        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Pesanan Dibayar</span>
                    <x-heroicon-o-shopping-bag class="size-5 text-gray-500 dark:text-gray-400" />
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-gray-900 dark:text-white om-tabular">
                {{ number_format($summary['orders']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Order terverifikasi lunas</p>
        </x-shopper::card>

        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Rata-rata Order (AOV)</span>
                    <x-heroicon-o-calculator class="size-5 text-gray-500 dark:text-gray-400" />
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-gray-900 dark:text-white om-tabular">
                Rp {{ number_format($aov, 0, ',', '.') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Per transaksi dibayar</p>
        </x-shopper::card>
    </div>
</div>
