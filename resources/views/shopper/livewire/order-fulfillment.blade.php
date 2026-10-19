{{--
    Rendered by App\Livewire\Shopper\OrderFulfillment only when Komerce Delivery is on.
    Shopper's own fulfillment view is included untouched (its "create shipping label"
    button is suppressed via OrderFulfillment::hasUnfulfilledItems()); this wrapper only
    puts the RajaOngkir AWB / sticker bar above it.
--}}
<div>
    <div class="mb-5 flex flex-col gap-3 rounded-xl bg-gray-50/80 p-4 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-800/50">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            @if ($rajaOngkirAwbs)
                <p class="font-medium">
                    Resi RajaOngkir:
                    <span class="font-bold text-gray-900 dark:text-white">{{ implode(', ', $rajaOngkirAwbs) }}</span>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Shopper hanya mencatat AWB resmi. Nomor resi tidak diketik manual.</p>
            @elseif ($hasPickupRequested)
                <p>Pickup kurir sudah dijadwalkan (<span class="font-semibold text-amber-600 dark:text-amber-400">Sedang Dipacking</span>). Menunggu nomor resi diterbitkan kurir.</p>
            @elseif ($orderIsPaid)
                <p>Pembayaran lunas. Terbitkan AWB dan stiker dari RajaOngkir — bukan form resi Shopper.</p>
            @else
                <p>Menunggu pelunasan. Setelah lunas, resi RajaOngkir terbit otomatis.</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 shrink-0">
            @if ($canPrintRajaOngkirLabel)
                <x-filament::button
                    tag="a"
                    :href="$printRajaOngkirRoute"
                    target="_blank"
                    icon="heroicon-o-printer"
                    color="primary"
                >
                    Cetak Stiker RajaOngkir
                </x-filament::button>
            @elseif ($hasPickupRequested)
                <x-filament::button
                    wire:click="openShippingLabel"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-arrow-path"
                    color="gray"
                >
                    <span wire:loading.remove wire:target="openShippingLabel">Cek / Perbarui Resi Kurir</span>
                    <span wire:loading wire:target="openShippingLabel">Memeriksa…</span>
                </x-filament::button>
            @elseif ($orderIsPaid)
                <x-filament::button
                    wire:click="openShippingLabel"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-paper-airplane"
                    color="primary"
                >
                    <span wire:loading.remove wire:target="openShippingLabel">Terbitkan Resi RajaOngkir</span>
                    <span wire:loading wire:target="openShippingLabel">Menerbitkan…</span>
                </x-filament::button>
            @endif
        </div>
    </div>

    @include('shopper::livewire.components.orders.fulfillment')
</div>
