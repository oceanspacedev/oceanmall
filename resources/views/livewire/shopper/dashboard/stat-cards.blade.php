<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Ringkasan Kinerja Keuangan (Lunas)</h2>
        <div class="flex gap-1.5 text-xs">
            @foreach (['7d' => '7 Hari', '30d' => '30 Hari', 'mtd' => 'Bulan Ini', 'ytd' => 'Tahun Ini'] as $key => $label)
                <button
                    type="button"
                    wire:click="setPeriod('{{ $key }}')"
                    @class([
                        'rounded-lg px-2.5 py-1 font-medium',
                        'bg-primary-600 text-white' => $period === $key,
                        'bg-white text-gray-600 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10' => $period !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-shopper::card>
            <p class="text-xs text-gray-500">Pendapatan Bersih (Net Revenue)</p>
            <p class="mt-2 font-heading text-2xl font-bold text-gray-900 dark:text-white">
                Rp {{ number_format($summary['net_revenue'], 0, ',', '.') }}
            </p>
        </x-shopper::card>

        <x-shopper::card>
            <p class="text-xs text-gray-500">Laba Kotor (Gross Profit)</p>
            <p class="mt-2 font-heading text-2xl font-bold text-emerald-600">
                Rp {{ number_format($summary['gross_profit'], 0, ',', '.') }}
            </p>
            <p class="mt-1 text-xs text-gray-500">Margin {{ $summary['margin_pct'] }}%</p>
        </x-shopper::card>

        <x-shopper::card>
            <p class="text-xs text-gray-500">Pesanan Dibayar</p>
            <p class="mt-2 font-heading text-2xl font-bold text-gray-900 dark:text-white">
                {{ $summary['orders'] }}
            </p>
        </x-shopper::card>

        <x-shopper::card>
            <p class="text-xs text-gray-500">Rata-rata Nilai Order (AOV)</p>
            <p class="mt-2 font-heading text-2xl font-bold text-gray-900 dark:text-white">
                Rp {{ number_format($aov, 0, ',', '.') }}
            </p>
        </x-shopper::card>
    </div>
</div>
