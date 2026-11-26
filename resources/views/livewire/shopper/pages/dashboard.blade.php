<x-shopper::container class="space-y-8 py-8">
    <x-shopper::heading :title="__('shopper::pages/dashboard.menu')">
        @if ($canViewFinance)
            <x-slot:action>
                <x-filament::button
                    tag="a"
                    :href="route('shopper.reports.finance')"
                    color="primary"
                    icon="heroicon-o-chart-bar-square"
                >
                    Laporan Keuangan & PPN
                </x-filament::button>
            </x-slot:action>
        @endif
    </x-shopper::heading>

    <livewire:oceanmall-dashboard.operational-alerts />

    @if ($canViewFinance)
        <livewire:oceanmall-dashboard.stat-cards />

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <livewire:oceanmall-dashboard.revenue-chart />
            </div>
            <div>
                <livewire:oceanmall-dashboard.top-selling-products />
            </div>
        </div>
    @endif

    <livewire:oceanmall-dashboard.recent-orders />
</x-shopper::container>
