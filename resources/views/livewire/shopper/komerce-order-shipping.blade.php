<div>
    <x-shopper::card>
        <x-slot:title>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <x-heroicon-o-truck class="size-5 text-gray-500 dark:text-gray-400 shrink-0" />
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">
                        Pengiriman RajaOngkir / Komerce
                    </span>
                    @if ($canPrintAnyLabel && ! $hasUnprocessedShipment)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Terkirim ke Komerce
                        </span>
                    @elseif ($canPrintAnyLabel && $hasUnprocessedShipment)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                            Sebagian Terdaftar
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                            Belum Terdaftar
                        </span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($order->payment_status !== \Shopper\Core\Enum\PaymentStatus::Paid)
                        <x-filament::button
                            type="button"
                            wire:click="markPaidAndProcessDelivery"
                            wire:loading.attr="disabled"
                            color="success"
                            icon="heroicon-o-check-circle"
                            size="xs"
                        >
                            <span wire:loading.remove wire:target="markPaidAndProcessDelivery">Sinkronkan Bayar & Pickup</span>
                            <span wire:loading wire:target="markPaidAndProcessDelivery">Menyelaraskan…</span>
                        </x-filament::button>
                    @endif

                    @if ($komerceEnabled && ($orderIsPaid ?? false) && $hasUnprocessedShipment)
                        <x-filament::button
                            type="button"
                            wire:click="processAllDeliveryOrders"
                            wire:loading.attr="disabled"
                            color="primary"
                            icon="heroicon-o-paper-airplane"
                            size="xs"
                        >
                            <span wire:loading.remove wire:target="processAllDeliveryOrders">
                                {{ ($needsPickup ?? false) ? 'Request Pickup Komerce' : 'Daftarkan & Pickup Komerce' }}
                            </span>
                            <span wire:loading wire:target="processAllDeliveryOrders">Memproses…</span>
                        </x-filament::button>
                    @endif

                    @if ($hasTrackableShipment && $komerceEnabled)
                        <x-filament::button
                            type="button"
                            wire:click="refreshTracking"
                            wire:loading.attr="disabled"
                            color="gray"
                            icon="heroicon-o-arrow-path"
                            size="xs"
                        >
                            <span wire:loading.remove wire:target="refreshTracking">Lacak Status Kurir</span>
                            <span wire:loading wire:target="refreshTracking">Memperbarui…</span>
                        </x-filament::button>
                    @endif

                    @if ($canPrintAnyLabel && $komerceEnabled)
                        <x-filament::button
                            tag="a"
                            href="{{ route('shopper.orders.fulfillment.print-label', $order) }}"
                            target="_blank"
                            icon="heroicon-o-printer"
                            color="primary"
                            size="xs"
                        >
                            Cetak Semua Stiker Resi
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-slot:title>

        <div class="space-y-4">
            {{-- Alerts --}}
            @unless ($komerceEnabled)
                <div class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-300">
                    Integrasi Komerce belum aktif. Pastikan API key terpasang di file .env.
                </div>
            @endunless

            @if ($successMessage)
                <div class="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300 font-medium" role="status">
                    {{ $successMessage }}
                </div>
            @endif

            @if ($overrideError)
                <div class="rounded-lg bg-rose-50 p-3 text-xs text-rose-900 dark:bg-rose-500/10 dark:text-rose-300 font-medium" role="alert">
                    {{ $overrideError }}
                </div>
            @endif

            {{-- Shipments List --}}
            @forelse ($shipments as $shipment)
                <div class="space-y-3 pt-2 {{ ! $loop->first ? 'border-t border-gray-100 dark:border-gray-800' : '' }}">
                    {{-- Row 1: Warehouse, Courier, Ongkir, Status & Actions --}}
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5">
                        <div class="flex items-center gap-2 flex-wrap text-xs">
                            <span class="font-semibold text-gray-900 dark:text-white">
                                {{ $shipment['shipper_name'] }}
                            </span>
                            <span class="text-gray-300 dark:text-gray-600">•</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                {{ $shipment['carrier'] }} ({{ $shipment['service'] }})
                            </span>
                            <span class="text-gray-300 dark:text-gray-600">•</span>
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">
                                Rp {{ number_format($shipment['cost'], 0, ',', '.') }}
                            </span>
                            <span class="text-gray-300 dark:text-gray-600">•</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                {{ $shipment['status_label'] }}
                            </span>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($shipment['can_print_label'] && $komerceEnabled)
                                <x-filament::button
                                    tag="a"
                                    href="{{ route('shopper.orders.fulfillment.print-label', ['order' => $order, 'shipment' => $shipment['id']]) }}"
                                    target="_blank"
                                    icon="heroicon-o-printer"
                                    color="primary"
                                    size="xs"
                                >
                                    Cetak Stiker
                                </x-filament::button>
                            @endif

                            @if (($shipment['awb'] || $shipment['tracking_number']) && $komerceEnabled)
                                <x-filament::button
                                    type="button"
                                    wire:click="refreshTracking({{ $shipment['id'] }})"
                                    wire:loading.attr="disabled"
                                    icon="heroicon-o-magnifying-glass"
                                    size="xs"
                                    color="gray"
                                >
                                    <span wire:loading.remove wire:target="refreshTracking({{ $shipment['id'] }})">Lacak</span>
                                    <span wire:loading wire:target="refreshTracking({{ $shipment['id'] }})">Melacak…</span>
                                </x-filament::button>
                            @endif

                            @if ($komerceEnabled && ($orderIsPaid ?? false) && ($shipment['needs_pickup'] ?? false))
                                <x-filament::button
                                    type="button"
                                    wire:click="requestPickup({{ $shipment['id'] }})"
                                    wire:loading.attr="disabled"
                                    icon="heroicon-o-truck"
                                    size="xs"
                                    color="primary"
                                >
                                    Request Pickup
                                </x-filament::button>
                            @elseif ($komerceEnabled && ($orderIsPaid ?? false) && ! $shipment['can_print_label'] && ! ($shipment['awb'] || $shipment['tracking_number']))
                                <x-filament::button
                                    type="button"
                                    wire:click="processDeliveryOrder({{ $shipment['id'] }})"
                                    wire:loading.attr="disabled"
                                    icon="heroicon-o-paper-airplane"
                                    size="xs"
                                    color="primary"
                                >
                                    Daftarkan & Pickup
                                </x-filament::button>
                            @endif
                        </div>
                    </div>

                    {{-- Row 2: Clean Details Strip (Soft background, NO HARSH BORDERS) --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-gray-50 dark:bg-gray-800/40 rounded-lg p-3 text-xs">
                        <div>
                            <span class="text-gray-500 dark:text-gray-400 block text-[11px]">No. Resi (AWB)</span>
                            <span class="font-mono font-bold text-gray-900 dark:text-white mt-0.5 block select-all">
                                {{ $shipment['awb'] ?? ($shipment['tracking_number'] ?? (($shipment['needs_pickup'] ?? false) ? 'Menunggu pickup' : 'Belum terbit')) }}
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400 block text-[11px]">ID Order Komerce</span>
                            <span class="font-mono font-medium text-gray-700 dark:text-gray-300 mt-0.5 block select-all">
                                {{ $shipment['delivery_order_no'] ?? 'Belum terdaftar' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400 block text-[11px]">Item Produk</span>
                            <div class="mt-0.5 flex flex-wrap gap-1">
                                @foreach ($shipment['lines'] as $line)
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        {{ $line['name'] }} <span class="text-gray-500">×{{ $line['qty'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Row 3: Clean Simulator Bar (Soft background, NO BORDER) --}}
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 bg-sky-50/60 dark:bg-sky-950/20 rounded-lg p-3 text-xs">
                        <div class="space-y-0.5">
                            <span class="font-semibold text-sky-900 dark:text-sky-200">
                                Simulasi Kurir (Sandbox Testing)
                            </span>
                            <p class="text-[11px] text-sky-700/80 dark:text-sky-300/80">
                                Uji status kurir lapangan hingga pesanan otomatis Completed.
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            @if (! ($shipment['awb'] || $shipment['tracking_number']))
                                <x-filament::button
                                    type="button"
                                    wire:click="simulateGenerateAwb({{ $shipment['id'] }})"
                                    wire:loading.attr="disabled"
                                    color="info"
                                    size="xs"
                                >
                                    Terbitkan Resi Simulasi
                                </x-filament::button>
                            @else
                                @if (in_array($shipment['status'], ['labeled', 'pending', 'ready'], true))
                                    <x-filament::button
                                        type="button"
                                        wire:click="simulateScanPickup({{ $shipment['id'] }})"
                                        wire:loading.attr="disabled"
                                        color="warning"
                                        size="xs"
                                    >
                                        1. Kurir Scan Jemput (Shipped)
                                    </x-filament::button>
                                @endif

                                @if (in_array($shipment['status'], ['picked_up', 'in_transit', 'shipped'], true))
                                    <x-filament::button
                                        type="button"
                                        wire:click="simulateScanDelivered({{ $shipment['id'] }})"
                                        wire:loading.attr="disabled"
                                        color="success"
                                        size="xs"
                                    >
                                        2. Kurir Scan Selesai Antar (Delivered)
                                    </x-filament::button>
                                @endif

                                @if ($shipment['status'] === 'delivered')
                                    <span class="inline-flex items-center gap-1 font-semibold text-emerald-700 dark:text-emerald-400">
                                        <x-heroicon-s-check-circle class="size-4 text-emerald-600" />
                                        Kurir Telah Selesai Antar (Delivered)
                                    </span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada data pengiriman untuk pesanan ini.</p>
            @endforelse

            {{-- Footer Note --}}
            @if (count($shipments) > 0 && count($overridableShipments) === 0)
                <p class="text-[11px] text-gray-400 dark:text-gray-500 pt-1">
                    Gudang pengirim terkunci karena nomor resi telah diterbitkan.
                </p>
            @endif
        </div>
    </x-shopper::card>

    {{-- Override Gudang Form (if applicable) --}}
    @if (count($overridableShipments) > 0)
        <div class="mt-4">
            <x-shopper::card>
                <div class="space-y-3 text-xs">
                    <div class="flex items-start gap-2">
                        <x-heroicon-o-arrows-right-left class="size-4 text-primary-500 mt-0.5" />
                        <div>
                            <p class="text-xs font-semibold text-gray-900 dark:text-white">Pindah gudang pengirim</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">Hanya sebelum paket didaftarkan ke Komerce. Setelah pickup/resi terbit, gudang terkunci.</p>
                        </div>
                    </div>

                    <form wire:submit="applyOverride" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block font-medium text-gray-700 dark:text-gray-300" for="shipment_line_id">Produk</label>
                                <select id="shipment_line_id" wire:model.live="shipment_line_id" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                    @foreach ($lineOptions as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-medium text-gray-700 dark:text-gray-300" for="qty">Qty</label>
                                <input id="qty" type="number" min="1" wire:model="qty" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="mb-1 block font-medium text-gray-700 dark:text-gray-300" for="from_inventory_id">Gudang Asal</label>
                                <select id="from_inventory_id" wire:model="from_inventory_id" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                    @foreach ($inventories as $inventory)
                                        <option value="{{ $inventory['id'] }}">{{ $inventory['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1 block font-medium text-gray-700 dark:text-gray-300" for="to_inventory_id">Gudang Tujuan</label>
                                <select id="to_inventory_id" wire:model="to_inventory_id" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                    @foreach ($inventories as $inventory)
                                        <option value="{{ $inventory['id'] }}">{{ $inventory['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if ($overrideError)
                            <p class="text-xs text-rose-600 dark:text-rose-400 mt-2">{{ $overrideError }}</p>
                        @endif

                        <x-filament::button type="submit" size="xs" color="gray" class="mt-2">
                            Pindahkan Stok
                        </x-filament::button>
                    </form>
                </div>
            </x-shopper::card>
        </div>
    @endif
</div>
