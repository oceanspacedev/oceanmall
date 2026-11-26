<x-shopper::card class="[&>div:first-of-type]:p-0">
    <div class="flex items-center justify-between gap-2 p-4">
        <div>
            <h3 class="font-heading text-sm font-semibold text-gray-900 dark:text-white">
                Riwayat Transaksi Pembayaran
            </h3>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Log otorisasi, capture, refund, dan percobaan pembayaran.
            </p>
        </div>
        <x-filament::badge size="sm" color="gray">
            {{ $transactions->count() }} catatan
        </x-filament::badge>
    </div>

    <div class="overflow-x-auto border-t border-gray-200 dark:border-white/10">
        @if ($transactions->isEmpty())
            <p class="p-4 text-xs text-gray-500 dark:text-gray-400">
                Belum ada transaksi pembayaran tercatat untuk pesanan ini.
            </p>
        @else
            <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/10">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell px-4 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Waktu</th>
                        <th class="fi-ta-header-cell px-3 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Driver</th>
                        <th class="fi-ta-header-cell px-3 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Tipe</th>
                        <th class="fi-ta-header-cell px-3 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Status</th>
                        <th class="fi-ta-header-cell px-3 py-2.5 text-right text-xs font-semibold text-gray-950 dark:text-white">Nominal</th>
                        <th class="fi-ta-header-cell px-3 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Referensi</th>
                        <th class="fi-ta-header-cell px-4 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 text-xs dark:divide-white/10">
                    @foreach ($transactions as $tx)
                        @php
                            $typeVal = $tx->type instanceof \BackedEnum ? $tx->type->value : (string) $tx->type;
                            $statusVal = $tx->status instanceof \BackedEnum ? $tx->status->value : (string) $tx->status;
                            $badgeColor = match ($statusVal) {
                                'success' => 'success',
                                'pending' => 'warning',
                                'failed' => 'danger',
                                default => 'gray',
                            };
                        @endphp
                        <tr>
                            <td class="fi-ta-cell px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-300">
                                {{ $tx->created_at?->format('d M Y H:i') }}
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 font-mono text-gray-900 dark:text-white">{{ $tx->driver }}</td>
                            <td class="fi-ta-cell px-3 py-2.5 uppercase">{{ $typeVal }}</td>
                            <td class="fi-ta-cell px-3 py-2.5">
                                <x-filament::badge size="sm" :color="$badgeColor">{{ $statusVal }}</x-filament::badge>
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 text-right font-medium text-gray-900 dark:text-white om-tabular">
                                {{ shopper_money_format((int) $tx->amount, (string) $tx->currency_code) }}
                            </td>
                            <td class="fi-ta-cell px-3 py-2.5 font-mono text-gray-700 dark:text-gray-300">{{ $tx->reference ?: '-' }}</td>
                            <td class="fi-ta-cell px-4 py-2.5 text-gray-600 dark:text-gray-400">{{ $tx->notes ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-shopper::card>
