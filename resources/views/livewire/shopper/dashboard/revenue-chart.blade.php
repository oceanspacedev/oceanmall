<x-shopper::card>
    <x-slot:title>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="font-heading text-base font-semibold text-gray-900 dark:text-white">
                    Tren Omzet Harian (14 Hari Terakhir)
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Berdasarkan pembayaran lunas (net refund)
                </p>
            </div>
            <x-filament::badge color="primary">
                Total: Rp {{ number_format((int) collect($series)->sum('revenue'), 0, ',', '.') }}
            </x-filament::badge>
        </div>
    </x-slot:title>

    <div class="om-revenue-chart">
        @foreach ($series as $point)
            @php
                $height = $point['revenue'] > 0 ? max(10, (int) round(($point['revenue'] / $maxRevenue) * 124)) : 6;
            @endphp
            <div class="om-revenue-col" title="{{ $point['label'] }}: Rp {{ number_format($point['revenue'], 0, ',', '.') }}">
                <div class="om-revenue-track">
                    <div
                        class="om-revenue-bar {{ $point['revenue'] === 0 ? 'om-revenue-bar--empty' : '' }}"
                        style="height: {{ $height }}px"
                    ></div>
                </div>
                <span class="om-revenue-label">{{ $point['label'] }}</span>
            </div>
        @endforeach
    </div>
</x-shopper::card>
