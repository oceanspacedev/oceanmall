<x-shopper::container class="space-y-6 py-5">
    <x-shopper::breadcrumb :back="route('shopper.dashboard')">
        <x-untitledui-chevron-left class="size-4 shrink-0 text-gray-300 dark:text-gray-600" />
        <x-shopper::breadcrumb.link
            :link="route('shopper.dashboard')"
            :title="__('shopper::pages/dashboard.menu')"
        />
    </x-shopper::breadcrumb>

    <x-shopper::heading title="Laporan Keuangan, Pajak PPN & Settlement">
        <x-slot:action>
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button
                    tag="a"
                    :href="route('cpanel.reports.finance.export', ['tab' => $activeTab, 'format' => 'csv', 'from' => $fromDate, 'to' => $toDate])"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-arrow-down-tray"
                >
                    Ekspor CSV
                </x-filament::button>
                <x-filament::button
                    tag="a"
                    :href="route('cpanel.reports.finance.export', ['tab' => $activeTab, 'format' => 'xlsx', 'from' => $fromDate, 'to' => $toDate])"
                    color="primary"
                    size="sm"
                    icon="heroicon-o-document-arrow-down"
                >
                    Ekspor Excel (.xlsx)
                </x-filament::button>
            </div>
        </x-slot:action>
    </x-shopper::heading>

    <x-shopper::tabs-navigation
        wire:model.live="activeTab"
        :tabs="[
            ['key' => 'margin', 'label' => 'Keuangan & Margin'],
            ['key' => 'ppn', 'label' => 'PPN Keluaran 11%'],
            ['key' => 'promo', 'label' => 'Promo ROI'],
            ['key' => 'settlement', 'label' => 'Settlement & COD'],
        ]"
        :active="$activeTab"
    />

    <x-shopper::card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Omzet dibayar, HPP (COGS) &amp; margin kotor, PPN keluaran 11%, ROI promo, serta pelunasan COD.
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Dari</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="dateFrom" />
                    </x-filament::input.wrapper>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Sampai</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="dateTo" />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </div>
    </x-shopper::card>

    @if ($activeTab === 'margin')
        <div class="om-stat-grid-4">
            <x-shopper::card>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Pendapatan Bersih</p>
                <p class="mt-1 font-heading text-xl font-bold text-gray-900 dark:text-white om-tabular">
                    Rp {{ number_format($report['summary']['net_revenue'], 0, ',', '.') }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $report['summary']['orders'] }} pesanan dibayar</p>
            </x-shopper::card>

            <x-shopper::card>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Total HPP (COGS)</p>
                <p class="mt-1 font-heading text-xl font-bold text-gray-900 dark:text-white om-tabular">
                    Rp {{ number_format($report['summary']['cogs'], 0, ',', '.') }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Snapshot biaya pokok produk</p>
            </x-shopper::card>

            <x-shopper::card>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Laba Kotor</p>
                <p class="mt-1 font-heading text-xl font-bold text-success-600 dark:text-success-400 om-tabular">
                    Rp {{ number_format($report['summary']['gross_profit'], 0, ',', '.') }}
                </p>
                <div class="mt-1">
                    <x-filament::badge size="sm" color="success">
                        Margin {{ $report['summary']['margin_pct'] }}%
                    </x-filament::badge>
                </div>
            </x-shopper::card>

            <x-shopper::card>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Refund &amp; Biaya Gateway</p>
                <p class="mt-1 font-heading text-xl font-bold text-warning-600 dark:text-warning-400 om-tabular">
                    Rp {{ number_format($report['summary']['refunds'] + $report['summary']['gateway_fee'], 0, ',', '.') }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Total potongan periode ini</p>
            </x-shopper::card>
        </div>

        <x-shopper::card class="[&>div:first-of-type]:p-0">
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/10">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell px-4 py-3 text-left text-xs font-semibold text-gray-950 dark:text-white">Invoice</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-left text-xs font-semibold text-gray-950 dark:text-white">Pesanan</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-left text-xs font-semibold text-gray-950 dark:text-white">Tanggal Bayar</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Bruto</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">COGS</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Refund</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Laba Kotor</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                        @forelse ($report['rows'] as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row['invoice_number'] }}</td>
                                <td class="fi-ta-cell px-4 py-3">
                                    <x-shopper::link
                                        :href="route('shopper.orders.detail', ['order' => $row['order_id']])"
                                        wire:navigate
                                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $row['order_number'] }}
                                    </x-shopper::link>
                                </td>
                                <td class="fi-ta-cell px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row['paid_at'] }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right om-tabular">Rp {{ number_format($row['gross'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right om-tabular">Rp {{ number_format($row['cogs'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right text-warning-600 dark:text-warning-400 om-tabular">Rp {{ number_format($row['refunds'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right font-semibold text-success-600 dark:text-success-400 om-tabular">Rp {{ number_format($row['gross_profit'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right">
                                    <x-filament::badge size="sm" color="gray">{{ $row['margin_pct'] }}%</x-filament::badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Belum ada transaksi dibayar pada periode ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-shopper::card>
    @elseif ($activeTab === 'ppn')
        <div class="om-stat-grid-3">
            <x-shopper::card>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Dasar Pengenaan Pajak (DPP)</p>
                <p class="mt-1 font-heading text-xl font-bold text-gray-900 dark:text-white om-tabular">
                    Rp {{ number_format($report['summary']['dpp'], 0, ',', '.') }}
                </p>
            </x-shopper::card>
            <x-shopper::card>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">PPN Keluaran 11%</p>
                <p class="mt-1 font-heading text-xl font-bold text-primary-600 dark:text-primary-400 om-tabular">
                    Rp {{ number_format($report['summary']['ppn'], 0, ',', '.') }}
                </p>
            </x-shopper::card>
            <x-shopper::card>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Omzet Kena Pajak</p>
                <p class="mt-1 font-heading text-xl font-bold text-gray-900 dark:text-white om-tabular">
                    Rp {{ number_format($report['summary']['taxable_total'], 0, ',', '.') }}
                </p>
            </x-shopper::card>
        </div>

        <x-shopper::card class="[&>div:first-of-type]:p-0">
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/10">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell px-4 py-3 text-left text-xs font-semibold text-gray-950 dark:text-white">Invoice</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-left text-xs font-semibold text-gray-950 dark:text-white">No Order</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-left text-xs font-semibold text-gray-950 dark:text-white">Pelanggan</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">DPP</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">PPN 11%</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                        @forelse ($report['rows'] as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row['invoice_number'] }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['order_number'] }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['customer'] }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right om-tabular">Rp {{ number_format($row['dpp'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right font-semibold text-primary-600 dark:text-primary-400 om-tabular">Rp {{ number_format($row['ppn'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right font-medium om-tabular">Rp {{ number_format($row['total'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Belum ada data PPN pada periode ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-shopper::card>
    @elseif ($activeTab === 'promo')
        <x-shopper::card class="[&>div:first-of-type]:p-0">
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/10">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell px-4 py-3 text-left text-xs font-semibold text-gray-950 dark:text-white">Kode Promo</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Pesanan Dibayar</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Total Diskon</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">GMV Teratribusi</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-right text-xs font-semibold text-gray-950 dark:text-white">Margin Bersih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                        @forelse ($report['rows'] as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-3">
                                    <x-filament::badge color="primary">{{ $row['code'] }}</x-filament::badge>
                                </td>
                                <td class="fi-ta-cell px-4 py-3 text-right om-tabular">{{ $row['orders'] }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right text-warning-600 dark:text-warning-400 om-tabular">Rp {{ number_format($row['total_discount'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right om-tabular">Rp {{ number_format($row['attributed_gmv'], 0, ',', '.') }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-right font-semibold text-success-600 dark:text-success-400 om-tabular">Rp {{ number_format($row['net_margin'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Belum ada penggunaan kupon pada periode ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-shopper::card>
    @else
        <div class="om-grid-2">
            <x-shopper::card class="[&>div:first-of-type]:p-0">
                <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Settlement per Kanal Pembayaran</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/10">
                        <thead>
                            <tr>
                                <th class="fi-ta-header-cell px-4 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Metode</th>
                                <th class="fi-ta-header-cell px-3 py-2.5 text-right text-xs font-semibold text-gray-950 dark:text-white">Order</th>
                                <th class="fi-ta-header-cell px-3 py-2.5 text-right text-xs font-semibold text-gray-950 dark:text-white">Bruto</th>
                                <th class="fi-ta-header-cell px-3 py-2.5 text-right text-xs font-semibold text-gray-950 dark:text-white">Fee</th>
                                <th class="fi-ta-header-cell px-4 py-2.5 text-right text-xs font-semibold text-gray-950 dark:text-white">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-xs dark:divide-white/10">
                            @forelse ($report['channels'] as $channel)
                                <tr>
                                    <td class="fi-ta-cell px-4 py-2.5 font-medium text-gray-900 dark:text-white">
                                        {{ $channel['method'] }}
                                        <span class="text-gray-400">({{ $channel['driver'] }})</span>
                                    </td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-right om-tabular">{{ $channel['orders'] }}</td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-right om-tabular">Rp {{ number_format($channel['gross'], 0, ',', '.') }}</td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-right om-tabular">Rp {{ number_format($channel['gateway_fee'], 0, ',', '.') }}</td>
                                    <td class="fi-ta-cell px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white om-tabular">Rp {{ number_format($channel['net_settlement'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Belum ada settlement pada periode ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-shopper::card>

            <x-shopper::card class="[&>div:first-of-type]:p-0">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pelunasan Dana COD Kurir</h3>
                    <x-filament::badge size="sm" :color="$report['summary']['cod_unremitted'] > 0 ? 'warning' : 'success'">
                        Outstanding: Rp {{ number_format($report['summary']['cod_unremitted'], 0, ',', '.') }}
                    </x-filament::badge>
                </div>
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/10">
                        <thead>
                            <tr>
                                <th class="fi-ta-header-cell px-4 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Order</th>
                                <th class="fi-ta-header-cell px-3 py-2.5 text-right text-xs font-semibold text-gray-950 dark:text-white">Nominal</th>
                                <th class="fi-ta-header-cell px-3 py-2.5 text-left text-xs font-semibold text-gray-950 dark:text-white">Status Setor</th>
                                <th class="fi-ta-header-cell px-4 py-2.5 text-right text-xs font-semibold text-gray-950 dark:text-white">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-xs dark:divide-white/10">
                            @forelse ($report['cod_orders'] as $cod)
                                <tr>
                                    <td class="fi-ta-cell px-4 py-2.5 font-medium">
                                        <x-shopper::link
                                            :href="route('shopper.orders.detail', ['order' => $cod['order_id']])"
                                            wire:navigate
                                            class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                        >
                                            {{ $cod['order_number'] }}
                                        </x-shopper::link>
                                    </td>
                                    <td class="fi-ta-cell px-3 py-2.5 text-right om-tabular">Rp {{ number_format($cod['price_amount'], 0, ',', '.') }}</td>
                                    <td class="fi-ta-cell px-3 py-2.5">
                                        @if ($cod['cod_remitted_at'])
                                            <span class="text-gray-700 dark:text-gray-300">{{ $cod['cod_remitted_at'] }}</span>
                                        @else
                                            <x-filament::badge size="sm" color="warning">Belum Disetor</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="fi-ta-cell px-4 py-2.5 text-right">
                                        @if ($cod['can_remit'])
                                            <x-filament::button
                                                type="button"
                                                wire:click="markCodRemitted({{ $cod['order_id'] }})"
                                                size="xs"
                                                color="success"
                                            >
                                                Catat Lunas COD
                                            </x-filament::button>
                                        @else
                                            <x-filament::badge size="sm" color="success">Lunas</x-filament::badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Tidak ada pesanan COD pada periode ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-shopper::card>
        </div>
    @endif
</x-shopper::container>
