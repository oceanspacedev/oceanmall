<x-shopper::card>
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tren Omzet Harian (14 Hari Terakhir)</h3>
        <span class="text-xs text-gray-500">Berdasarkan pembayaran lunas (net refund)</span>
    </div>

    <div class="mt-4 grid grid-cols-7 gap-2 sm:grid-cols-14">
        @foreach ($series as $point)
            @php
                $height = $point['revenue'] > 0 ? max(8, (int) round(($point['revenue'] / $maxRevenue) * 96)) : 4;
            @endphp
            <div class="flex flex-col items-center justify-end gap-1" title="{{ $point['label'] }}: Rp {{ number_format($point['revenue'], 0, ',', '.') }}">
                <div class="flex h-24 w-full items-end justify-center">
                    <div
                        class="w-full max-w-[20px] rounded-t {{ $point['revenue'] > 0 ? 'bg-primary-500' : 'bg-gray-200 dark:bg-white/10' }}"
                        style="height: {{ $height }}px"
                    ></div>
                </div>
                <span class="text-[10px] text-gray-500">{{ $point['label'] }}</span>
            </div>
        @endforeach
    </div>
</x-shopper::card>
