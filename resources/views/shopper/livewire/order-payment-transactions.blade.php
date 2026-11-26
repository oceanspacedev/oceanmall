<div class="rounded-xl bg-white p-5 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="flex items-center justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Riwayat Transaksi Pembayaran</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">Log otorisasi, capture, refund, dan percobaan pembayaran.</p>
        </div>
        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
            {{ $transactions->count() }} catatan
        </span>
    </div>

    @if ($transactions->isEmpty())
        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">Belum ada transaksi pembayaran tercatat untuk pesanan ini.</p>
    @else
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-3 font-medium">Waktu</th>
                        <th class="py-2 pr-3 font-medium">Driver</th>
                        <th class="py-2 pr-3 font-medium">Tipe</th>
                        <th class="py-2 pr-3 font-medium">Status</th>
                        <th class="py-2 pr-3 font-medium">Nominal</th>
                        <th class="py-2 pr-3 font-medium">Referensi</th>
                        <th class="py-2 font-medium">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($transactions as $tx)
                        @php
                            $typeVal = $tx->type instanceof \BackedEnum ? $tx->type->value : (string) $tx->type;
                            $statusVal = $tx->status instanceof \BackedEnum ? $tx->status->value : (string) $tx->status;
                        @endphp
                        <tr>
                            <td class="py-2 pr-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $tx->created_at?->format('d M Y H:i') }}</td>
                            <td class="py-2 pr-3 font-mono text-gray-900 dark:text-white">{{ $tx->driver }}</td>
                            <td class="py-2 pr-3 uppercase">{{ $typeVal }}</td>
                            <td class="py-2 pr-3">
                                <span @class([
                                    'inline-flex rounded px-1.5 py-0.5 font-medium',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $statusVal === 'success',
                                    'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $statusVal === 'pending',
                                    'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-300' => $statusVal === 'failed',
                                ])>{{ $statusVal }}</span>
                            </td>
                            <td class="py-2 pr-3 font-medium text-gray-900 dark:text-white">{{ shopper_money_format((int) $tx->amount, (string) $tx->currency_code) }}</td>
                            <td class="py-2 pr-3 font-mono text-gray-700 dark:text-gray-300">{{ $tx->reference ?: '-' }}</td>
                            <td class="py-2 text-gray-600 dark:text-gray-400">{{ $tx->notes ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
