{{--
    Rendered by App\Livewire\Shopper\OrderFulfillment only for orders whose
    shipments a provider fulfils (App\Shipping\Contracts\FulfillsShipments).
    Shopper's own fulfillment view is included untouched (its "create shipping
    label" button is suppressed via OrderFulfillment::hasUnfulfilledItems());
    this wrapper only puts the provider AWB / sticker bar above it.
--}}
<div>
    <div class="mb-5 flex flex-col gap-3 rounded-xl bg-gray-50/80 p-4 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-800/50">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            @if ($providerAwbs)
                <p class="font-medium">
                    Resi {{ $providerName }}:
                    <span class="font-bold text-gray-900 dark:text-white">{{ implode(', ', $providerAwbs) }}</span>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Shopper hanya mencatat AWB resmi. Nomor resi tidak diketik manual.</p>
            @elseif ($hasPickupRequested)
                <p>Pickup kurir sudah dijadwalkan (<span class="font-semibold text-amber-600 dark:text-amber-400">Sedang Dipacking</span>). Menunggu nomor resi diterbitkan kurir.</p>
            @elseif ($orderIsPaid)
                <p>{{ ($isCodOrder ?? false) ? 'Pesanan COD siap diproses.' : 'Pembayaran lunas.' }} Terbitkan AWB dan stiker dari {{ $providerName }} — bukan form resi Shopper.</p>
            @else
                <p>Menunggu pelunasan. Setelah lunas, resi {{ $providerName }} terbit otomatis.</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 shrink-0">
            @if ($canPrintProviderLabel)
                <x-filament::button
                    tag="a"
                    :href="$printProviderLabelRoute"
                    target="_blank"
                    icon="heroicon-o-printer"
                    color="primary"
                >
                    Cetak Stiker {{ $providerName }}
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
                    <span wire:loading.remove wire:target="openShippingLabel">Terbitkan Resi {{ $providerName }}</span>
                    <span wire:loading wire:target="openShippingLabel">Menerbitkan…</span>
                </x-filament::button>
            @endif
        </div>
    </div>

    @if (! empty($fulfillmentErrors ?? []))
        <div class="mb-4 rounded-xl border border-danger-300 bg-danger-50 p-3 text-xs text-danger-800 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-200">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <p class="font-semibold">Kendala Fulfillment {{ $providerName }}</p>
                    <p class="mt-0.5">{{ implode(' | ', $fulfillmentErrors) }}</p>
                </div>
                @if ($orderIsPaid)
                    <x-filament::button wire:click="openShippingLabel" size="xs" color="danger">
                        Coba Ulang
                    </x-filament::button>
                @endif
            </div>
        </div>
    @endif

    @if (! empty($shipmentCards ?? []))
        <div class="mb-4 space-y-2">
            @foreach ($shipmentCards as $card)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-white/10 dark:bg-gray-900">
                    <div class="space-y-0.5">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $card['carrier_name_display'] ?: $card['carrier'] }} ({{ $card['service'] }})</span>
                        <span class="text-gray-500"> · {{ $card['status_label'] }}</span>
                        @if ($card['delivery_order_no'])
                            <span class="text-gray-500"> · Ref: <code>{{ $card['delivery_order_no'] }}</code></span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($card['can_track'] ?? false)
                            <x-filament::button wire:click="refreshShipmentTracking({{ $card['id'] }})" size="xs" color="gray">
                                Lacak
                            </x-filament::button>
                        @endif
                        @if ($card['can_reship'] ?? false)
                            <x-filament::button wire:click="reshipShipment({{ $card['id'] }})" size="xs" color="warning">
                                Kirim Ulang
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @include('shopper::livewire.components.orders.fulfillment')
</div>
