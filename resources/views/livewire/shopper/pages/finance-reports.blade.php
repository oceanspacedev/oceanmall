<x-shopper::container class="py-6 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-heading text-2xl font-bold text-gray-900 dark:text-white">
                Laporan Keuangan, Pajak PPN & Settlement
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Omzet dibayar, COGS & margin kotor, PPN keluaran 11%, ROI promo, serta pelunasan COD.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ route('cpanel.reports.finance.export', ['tab' => $activeTab, 'format' => 'csv', 'from' => $fromDate, 'to' => $toDate]) }}"
                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/15 dark:bg-gray-800 dark:text-gray-200"
            >
                Ekspor CSV
            </a>
            <a
                href="{{ route('cpanel.reports.finance.export', ['tab' => $activeTab, 'format' => 'xlsx', 'from' => $fromDate, 'to' => $toDate]) }}"
                class="inline-flex items-center rounded-lg bg-primary-600 px-3 py-2 text-xs font-semibold text-white hover:bg-primary-500"
            >
                Ekspor Excel (.xlsx)
            </a>
        </div>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4 rounded-xl bg-white p-4 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap gap-2">
            @foreach ([
                'margin' => 'Keuangan & Margin',
                'ppn' => 'PPN Keluaran 11%',
                'promo' => 'Promo ROI',
                'settlement' => 'Settlement & COD',
            ] as $key => $label)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-primary-600 text-white' => $activeTab === $key,
                        'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300' => $activeTab !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2 text-xs">
            <label class="text-gray-600 dark:text-gray-400">
                Dari
                <input type="date" wire:model.live="dateFrom" class="ml-1 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800 dark:text-white" />
            </label>
            <label class="text-gray-600 dark:text-gray-400">
                Sampai
                <input type="date" wire:model.live="dateTo" class="ml-1 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800 dark:text-white" />
            </label>
        </div>
    </div>

    @if ($activeTab === 'margin')
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-shopper::card>
                <p class="text-xs text-gray-500">Pendapatan Bersih</p>
                <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">Rp {{ number_format($report['summary']['net_revenue'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $report['summary']['orders'] }} pesanan dibayar</p>
            </x-shopper::card>
            <x-shopper::card>
                <p class="text-xs text-gray-500">Total HPP (COGS)</p>
                <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">Rp {{ number_format($report['summary']['cogs'], 0, ',', '.') }}</p>
            </x-shopper::card>
            <x-shopper::card>
                <p class="text-xs text-gray-500">Laba Kotor</p>
                <p class="mt-1 text-xl font-bold text-emerald-600">Rp {{ number_format($report['summary']['gross_profit'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs text-gray-500">Margin {{ $report['summary']['margin_pct'] }}%</p>
            </x-shopper::card>
            <x-shopper::card>
                <p class="text-xs text-gray-500">Total Refund & Biaya Gateway</p>
                <p class="mt-1 text-xl font-bold text-amber-600">Rp {{ number_format($report['summary']['refunds'] + $report['summary']['gateway_fee'], 0, ',', '.') }}</p>
            </x-shopper::card>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">Invoice</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Pesanan</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Tanggal Bayar</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Bruto</th>
                        <th class="px-3 py-2.5 text-right font-semibold">COGS</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Refund</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Laba Kotor</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Margin %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $row['invoice_number'] }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('shopper.orders.detail', ['order' => $row['order_id']]) }}" class="text-primary-600 hover:underline">
                                    {{ $row['order_number'] }}
                                </a>
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ $row['paid_at'] }}</td>
                            <td class="px-3 py-2 text-right">Rp {{ number_format($row['gross'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right">Rp {{ number_format($row['cogs'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right text-amber-600">Rp {{ number_format($row['refunds'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-emerald-600">Rp {{ number_format($row['gross_profit'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right">{{ $row['margin_pct'] }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">Belum ada transaksi dibayar pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($activeTab === 'ppn')
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-shopper::card>
                <p class="text-xs text-gray-500">Dasar Pengenaan Pajak (DPP)</p>
                <p class="mt-1 text-xl font-bold">Rp {{ number_format($report['summary']['dpp'], 0, ',', '.') }}</p>
            </x-shopper::card>
            <x-shopper::card>
                <p class="text-xs text-gray-500">PPN Keluaran 11%</p>
                <p class="mt-1 text-xl font-bold text-primary-600">Rp {{ number_format($report['summary']['ppn'], 0, ',', '.') }}</p>
            </x-shopper::card>
            <x-shopper::card>
                <p class="text-xs text-gray-500">Total Omzet Kena Pajak</p>
                <p class="mt-1 text-xl font-bold">Rp {{ number_format($report['summary']['taxable_total'], 0, ',', '.') }}</p>
            </x-shopper::card>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">Invoice</th>
                        <th class="px-3 py-2.5 text-left font-semibold">No Order</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Pelanggan</th>
                        <th class="px-3 py-2.5 text-right font-semibold">DPP</th>
                        <th class="px-3 py-2.5 text-right font-semibold">PPN 11%</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $row['invoice_number'] }}</td>
                            <td class="px-3 py-2">{{ $row['order_number'] }}</td>
                            <td class="px-3 py-2">{{ $row['customer'] }}</td>
                            <td class="px-3 py-2 text-right">Rp {{ number_format($row['dpp'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right font-semibold">Rp {{ number_format($row['ppn'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right">Rp {{ number_format($row['total'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Belum ada data PPN pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($activeTab === 'promo')
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">Kode Promo</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Pesanan Dibayar</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Total Diskon</th>
                        <th class="px-3 py-2.5 text-right font-semibold">GMV Teratribusi</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Margin Bersih</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="px-3 py-2 font-semibold">{{ $row['code'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $row['orders'] }}</td>
                            <td class="px-3 py-2 text-right text-amber-600">Rp {{ number_format($row['total_discount'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right">Rp {{ number_format($row['attributed_gmv'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-emerald-600">Rp {{ number_format($row['net_margin'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Belum ada penggunaan kupon pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-white/10">
                    Settlement per Kanal Pembayaran
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-3 py-2 text-left">Metode</th>
                            <th class="px-3 py-2 text-right">Order</th>
                            <th class="px-3 py-2 text-right">Bruto</th>
                            <th class="px-3 py-2 text-right">Fee</th>
                            <th class="px-3 py-2 text-right">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse ($report['channels'] as $channel)
                            <tr>
                                <td class="px-3 py-2 font-medium">{{ $channel['method'] }} <span class="text-gray-400">({{ $channel['driver'] }})</span></td>
                                <td class="px-3 py-2 text-right">{{ $channel['orders'] }}</td>
                                <td class="px-3 py-2 text-right">Rp {{ number_format($channel['gross'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right">Rp {{ number_format($channel['gateway_fee'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right font-semibold">Rp {{ number_format($channel['net_settlement'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Belum ada settlement pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-white/10">
                    <span>Pelunasan Dana COD Kurir</span>
                    <span class="text-xs text-amber-600">Outstanding: Rp {{ number_format($report['summary']['cod_unremitted'], 0, ',', '.') }}</span>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-3 py-2 text-left">Order</th>
                            <th class="px-3 py-2 text-right">Nominal</th>
                            <th class="px-3 py-2 text-left">Status Setor</th>
                            <th class="px-3 py-2 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse ($report['cod_orders'] as $cod)
                            <tr>
                                <td class="px-3 py-2 font-medium">
                                    <a href="{{ route('shopper.orders.detail', ['order' => $cod['order_id']]) }}" class="text-primary-600 hover:underline">
                                        {{ $cod['order_number'] }}
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-right">Rp {{ number_format($cod['price_amount'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2">{{ $cod['cod_remitted_at'] ?: 'Belum Disetor' }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($cod['can_remit'])
                                        <button
                                            type="button"
                                            wire:click="markCodRemitted({{ $cod['order_id'] }})"
                                            class="rounded bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-500"
                                        >
                                            Catat Lunas COD
                                        </button>
                                    @else
                                        <span class="text-emerald-600 font-semibold">Lunas</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Tidak ada pesanan COD pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-shopper::container>
