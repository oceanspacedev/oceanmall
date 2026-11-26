<x-shopper::container class="space-y-8 py-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-heading text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('shopper::pages/dashboard.menu') }}
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Ringkasan operasional toko, antrean verifikasi, dan kesehatan pembayaran & logistik.
            </p>
        </div>

        @if ($canViewFinance)
            <a
                href="{{ route('shopper.reports.finance') }}"
                class="inline-flex items-center rounded-lg bg-primary-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-primary-500"
            >
                Buka Laporan Keuangan & PPN
            </a>
        @endif
    </div>

    <livewire:oceanmall-dashboard.operational-alerts />

    @if ($canViewFinance)
        <livewire:oceanmall-dashboard.stat-cards />
        <livewire:oceanmall-dashboard.revenue-chart />
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @if ($canViewFinance)
            <livewire:oceanmall-dashboard.top-selling-products />
        @endif

        <div @class(['lg:col-span-2' => ! $canViewFinance])>
            <livewire:oceanmall-dashboard.recent-orders />
        </div>
    </div>
</x-shopper::container>
