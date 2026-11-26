<div class="space-y-4">
    <div class="om-stat-grid-4">
        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Bukti Transfer</span>
                    <x-filament::badge size="sm" :color="$pendingProofs > 0 ? 'warning' : 'gray'">
                        {{ $pendingProofs > 0 ? 'Perlu Cek' : 'Aman' }}
                    </x-filament::badge>
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-gray-900 dark:text-white om-tabular">
                {{ $pendingProofs }}
            </p>

            <div class="mt-2 flex items-center justify-between">
                <span class="text-xs text-gray-500 dark:text-gray-400">Menunggu verifikasi</span>
                <x-shopper::link
                    :href="route('shopper.orders.payment-proofs')"
                    wire:navigate
                    class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                >
                    Lihat
                    <x-untitledui-arrow-narrow-right class="size-3.5" />
                </x-shopper::link>
            </div>
        </x-shopper::card>

        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Sengketa Dana</span>
                    <x-filament::badge size="sm" :color="$openDisputes > 0 ? 'danger' : 'gray'">
                        {{ $openDisputes > 0 ? 'Terbuka' : 'Bersih' }}
                    </x-filament::badge>
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-gray-900 dark:text-white om-tabular">
                {{ $openDisputes }}
            </p>

            <div class="mt-2 flex items-center justify-between">
                <span class="text-xs text-gray-500 dark:text-gray-400">Antrean sengketa open</span>
                <x-shopper::link
                    :href="route('shopper.orders.disputes')"
                    wire:navigate
                    class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                >
                    Tinjau
                    <x-untitledui-arrow-narrow-right class="size-3.5" />
                </x-shopper::link>
            </div>
        </x-shopper::card>

        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Retur / RMA</span>
                    <x-filament::badge size="sm" :color="$pendingReturns > 0 ? 'warning' : 'gray'">
                        {{ $pendingReturns > 0 ? 'Baru' : 'Normal' }}
                    </x-filament::badge>
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-gray-900 dark:text-white om-tabular">
                {{ $pendingReturns }}
            </p>

            <div class="mt-2 flex items-center justify-between">
                <span class="text-xs text-gray-500 dark:text-gray-400">Permintaan retur baru</span>
                <x-shopper::link
                    :href="route('shopper.orders.returns')"
                    wire:navigate
                    class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                >
                    Proses
                    <x-untitledui-arrow-narrow-right class="size-3.5" />
                </x-shopper::link>
            </div>
        </x-shopper::card>

        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Lunas &gt; 24 Jam</span>
                    <x-filament::badge size="sm" :color="$overdueShipments > 0 ? 'danger' : 'success'">
                        {{ $overdueShipments > 0 ? 'Terlambat' : 'Tepat Waktu' }}
                    </x-filament::badge>
                </div>
            </x-slot:title>

            <p class="font-heading text-2xl font-bold text-gray-900 dark:text-white om-tabular">
                {{ $overdueShipments }}
            </p>

            <div class="mt-2 flex items-center justify-between">
                <span class="text-xs text-gray-500 dark:text-gray-400">Order belum dikirim</span>
                <x-shopper::link
                    :href="route('shopper.orders.index')"
                    wire:navigate
                    class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                >
                    Cek
                    <x-untitledui-arrow-narrow-right class="size-3.5" />
                </x-shopper::link>
            </div>
        </x-shopper::card>
    </div>

    @if ($providerQuotas !== [])
        <x-shopper::card>
            <div class="flex flex-wrap items-center gap-2.5 text-xs">
                <span class="font-semibold text-gray-700 dark:text-gray-200">Status Integrasi Eksternal:</span>
                @foreach ($providerQuotas as $quota)
                    <x-filament::badge size="sm" :color="$quota['exhausted'] ? 'danger' : 'success'">
                        {{ $quota['label'] }}:
                        @if ($quota['limit'] !== null)
                            {{ $quota['used'] }}/{{ $quota['limit'] }}
                        @else
                            {{ $quota['exhausted'] ? 'Terbatas' : 'Normal' }}
                        @endif
                    </x-filament::badge>
                @endforeach
            </div>
        </x-shopper::card>
    @endif
</div>
