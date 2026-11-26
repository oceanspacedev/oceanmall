<div>
    <x-shopper::card>
        <x-slot:title>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between py-1">
                <div class="flex items-center gap-3.5 flex-wrap">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 ring-1 ring-primary-200 dark:bg-primary-500/15 dark:text-primary-400 dark:ring-primary-500/30">
                        <x-heroicon-o-truck class="size-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="text-base font-bold text-gray-900 dark:text-white">
                                Pengiriman RajaOngkir / Komerce
                            </span>
                            @if ($canPrintAnyLabel && ! $hasUnprocessedShipment)
                                <x-filament::badge color="success" size="sm">Terkirim ke Komerce</x-filament::badge>
                            @elseif ($canPrintAnyLabel && $hasUnprocessedShipment)
                                <x-filament::badge color="info" size="sm">Sebagian Terdaftar</x-filament::badge>
                            @else
                                <x-filament::badge color="warning" size="sm">Belum Terdaftar</x-filament::badge>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Multi-gudang fulfillment, cetak resi thermal, & pelacakan kurir real-time.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    @if ($canSyncPayment)
                        <x-filament::button
                            type="button"
                            wire:click="markPaidAndProcessDelivery"
                            wire:loading.attr="disabled"
                            color="success"
                            icon="heroicon-o-check-circle"
                            size="sm"
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
                            size="sm"
                        >
                            <span wire:loading.remove wire:target="processAllDeliveryOrders">
                                {{ ($needsPickup ?? false) ? 'Request Pickup Komerce' : 'Daftarkan & Pickup Komerce' }}
                            </span>
                            <span wire:loading wire:target="processAllDeliveryOrders">Memproses…</span>
                        </x-filament::button>
                    @endif

                    @if ($hasTrackableShipment && ($canTrackShipping ?? false))
                        <x-filament::button
                            type="button"
                            wire:click="refreshTracking"
                            wire:loading.attr="disabled"
                            color="gray"
                            icon="heroicon-o-arrow-path"
                            size="sm"
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
                            size="sm"
                        >
                            Cetak Semua Stiker Resi
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-slot:title>

        <div class="space-y-6 pt-2">
            {{-- Alerts --}}
            @if ($successMessage)
                <x-shopper::alert color="success" icon="heroicon-m-check-circle" :message="$successMessage" />
            @endif

            @if ($overrideError)
                <x-shopper::alert color="danger" icon="heroicon-m-x-circle" :message="$overrideError" />
            @endif

            {{-- Shipments List --}}
            @forelse ($shipments as $shipment)
                @php
                    $status = $shipment['status'] ?? 'pending';
                    $statusColor = match ($status) {
                        'delivered' => 'success',
                        'picked_up', 'in_transit', 'shipped' => 'info',
                        'labeled' => 'primary',
                        'cancelled' => 'danger',
                        default => 'warning',
                    };
                @endphp

                <div class="space-y-6 {{ ! $loop->first ? 'pt-6 border-t border-gray-100 dark:border-gray-800' : '' }}">
                    <div class="rounded-xl bg-white p-5 ring-1 ring-gray-200 space-y-5 dark:bg-gray-900 dark:ring-white/10">
                        
                        {{-- 1. STRIP RINGKASAN 4-KOLOM PRESISI (Ekspedisi, Resi, Status, Ongkir) --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-gray-50/90 dark:bg-gray-700/40">
                            {{-- Kolom 1: Ekspedisi & Layanan --}}
                            <div>
                                <span class="block text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    Ekspedisi Kurir
                                </span>
                                <div class="flex items-center gap-2.5 mt-1.5">
                                    <div class="flex h-7 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-white p-0.5 ring-1 ring-gray-200 dark:bg-white dark:ring-white/10">
                                        @if (! empty($shipment['carrier_logo']))
                                            <img
                                                src="{{ $shipment['carrier_logo'] }}"
                                                alt="{{ $shipment['carrier_name_display'] ?? $shipment['carrier'] }}"
                                                class="max-h-full max-w-full object-contain"
                                                loading="lazy"
                                                onerror="this.onerror=null; this.src='{{ asset('images/couriers/'.($shipment['carrier_code'] ?? 'jnt').'.svg') }}';"
                                            />
                                        @else
                                            <x-heroicon-o-truck class="size-4 text-gray-400" />
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        <span class="text-xs font-bold text-gray-900 dark:text-white truncate block leading-tight">
                                            {{ $shipment['carrier_name_display'] ?? $shipment['carrier'] }}
                                        </span>
                                        <span class="mt-0.5 block text-xs font-semibold leading-tight text-primary-600 dark:text-primary-400">
                                            {{ $shipment['service'] }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Kolom 2: Nomor Resi (AWB) dengan 1-Click Copy --}}
                            <div x-data="{ copied: false }">
                                <span class="block text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    Nomor Resi (AWB)
                                </span>
                                <div class="flex items-center gap-2 mt-1.5">
                                    <span class="font-mono font-bold text-xs sm:text-sm text-gray-900 dark:text-white tracking-wider truncate">
                                        {{ $shipment['awb'] ?? ($shipment['tracking_number'] ?? (($shipment['needs_pickup'] ?? false) ? 'Menunggu pickup' : 'Belum terbit')) }}
                                    </span>
                                    @if ($shipment['awb'] || $shipment['tracking_number'])
                                        <button
                                            type="button"
                                            @click="navigator.clipboard.writeText('{{ $shipment['awb'] ?: $shipment['tracking_number'] }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                            class="inline-flex items-center p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-500 transition cursor-pointer shrink-0"
                                            title="Salin Nomor Resi"
                                        >
                                            <template x-if="!copied">
                                                <x-heroicon-m-clipboard-document class="size-4 text-gray-400 hover:text-gray-600" />
                                            </template>
                                            <template x-if="copied">
                                                <x-heroicon-m-check class="size-4 text-success-600 dark:text-success-400" />
                                            </template>
                                        </button>
                                    @endif
                                </div>
                            </div>

                            {{-- Kolom 3: Status Pengiriman --}}
                            <div>
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider block">
                                    Status Pengiriman
                                </span>
                                <div class="mt-1.5">
                                    <x-filament::badge :color="$statusColor" size="sm">
                                        {{ $shipment['status_label'] }}
                                    </x-filament::badge>
                                </div>
                            </div>

                            {{-- Kolom 4: Biaya Ongkir --}}
                            <div>
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider block">
                                    Biaya Ongkos Kirim
                                </span>
                                <div class="mt-1.5">
                                    <span class="text-sm font-bold text-success-600 dark:text-success-400 leading-normal block om-tabular">
                                        Rp {{ number_format($shipment['cost'], 0, ',', '.') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {{-- 2. DETAIL GRID 2-KOLOM (Rute Kiri vs Barang & Aksi Kanan) --}}
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-1">
                            {{-- KOLOM KIRI: Rute Pengiriman (Asal -> Tujuan) --}}
                            <div class="space-y-4">
                                <span class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider block">
                                    Rute Pengiriman
                                </span>

                                <div class="space-y-3">
                                    {{-- Gudang Asal --}}
                                    <div class="flex gap-3">
                                        <div class="flex flex-col items-center">
                                            <span class="flex items-center justify-center size-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 shrink-0">
                                                <x-heroicon-o-building-storefront class="size-4" />
                                            </span>
                                            <span class="w-0.5 flex-1 bg-gray-200 dark:bg-gray-700 my-1 min-h-[20px]"></span>
                                        </div>
                                        <div class="pb-1 min-w-0">
                                            <span class="block text-xs font-medium text-gray-400 dark:text-gray-500">Gudang Asal Pengirim</span>
                                            <span class="text-xs font-bold text-gray-900 dark:text-white block mt-0.5">{{ $shipment['shipper_name'] }}</span>
                                            @if ($shipment['shipper_address'])
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">{{ $shipment['shipper_address'] }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Tujuan Penerima --}}
                                    <div class="flex gap-3">
                                        <div class="flex flex-col items-center">
                                            <span class="flex items-center justify-center size-8 rounded-lg bg-primary-50 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 shrink-0">
                                                <x-heroicon-o-map-pin class="size-4" />
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <span class="block text-xs font-medium text-gray-400 dark:text-gray-500">Tujuan Pengiriman</span>
                                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                                <span class="text-xs font-bold text-gray-900 dark:text-white">{{ $shipment['receiver_name'] }}</span>
                                                @if (! empty($shipment['receiver_phone']) && $shipment['receiver_phone'] !== '-')
                                                    <span class="flex items-center gap-1 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                        <x-heroicon-m-phone class="size-3 text-gray-400" />
                                                        {{ $shipment['receiver_phone'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            @if ($shipment['receiver_address'] && $shipment['receiver_address'] !== '-')
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">{{ $shipment['receiver_address'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- KOLOM KANAN: Barang, ID Order Komerce & Aksi --}}
                            <div class="space-y-4">
                                <span class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider block">
                                    Barang & Aksi Pengiriman
                                </span>

                                {{-- ID Order Komerce --}}
                                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50/90 dark:bg-gray-700/40 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">ID Order Komerce</span>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-bold text-gray-900 dark:text-white">
                                            {{ $shipment['delivery_order_no'] ?? 'Belum terdaftar' }}
                                        </span>
                                        @if ($shipment['delivery_order_no'])
                                            <x-filament::badge color="success" size="sm" icon="heroicon-s-check-circle">
                                                Terdaftar
                                            </x-filament::badge>
                                        @endif
                                    </div>
                                </div>

                                {{-- Daftar Barang (Tanpa Border) --}}
                                <div class="space-y-1.5">
                                    <span class="block text-xs font-medium text-gray-400 dark:text-gray-500">
                                        Item dalam Paket ({{ count($shipment['lines']) }}):
                                    </span>
                                    @foreach ($shipment['lines'] as $line)
                                        <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-gray-50/90 dark:bg-gray-700/40 text-xs">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <x-heroicon-o-cube class="size-3.5 text-gray-400 shrink-0" />
                                                <span class="font-medium text-gray-900 dark:text-white truncate">{{ $line['name'] }}</span>
                                            </div>
                                            <x-filament::badge color="primary" size="sm" class="ml-2 shrink-0">
                                                × {{ $line['qty'] }}
                                            </x-filament::badge>
                                        </div>
                                    @endforeach
                                </div>

                                @if (! empty($shipment['fulfillment_error']))
                                    <x-shopper::alert color="danger" icon="heroicon-o-exclamation-triangle" title="Kendala Fulfillment" :message="$shipment['fulfillment_error']" />
                                @endif

                                {{-- Tombol Aksi Pengiriman --}}
                                <div class="flex items-center gap-2 pt-1 flex-wrap">
                                    @if ($shipment['can_print_label'] && ($komerceEnabled || ($shipment['awb'] || $shipment['tracking_number'])))
                                        <x-filament::button
                                            tag="a"
                                            href="{{ route('shopper.orders.fulfillment.print-label', ['order' => $order, 'shipment' => $shipment['id']]) }}"
                                            target="_blank"
                                            icon="heroicon-o-printer"
                                            color="primary"
                                            size="sm"
                                        >
                                            Cetak Stiker Resi
                                        </x-filament::button>
                                    @endif

                                    @if (($shipment['awb'] || $shipment['tracking_number']) && ($canTrackShipping ?? false))
                                        <x-filament::button
                                            type="button"
                                            wire:click="refreshTracking({{ $shipment['id'] }})"
                                            wire:loading.attr="disabled"
                                            icon="heroicon-o-magnifying-glass"
                                            size="sm"
                                            color="gray"
                                        >
                                            <span wire:loading.remove wire:target="refreshTracking({{ $shipment['id'] }})">Lacak Paket</span>
                                            <span wire:loading wire:target="refreshTracking({{ $shipment['id'] }})">Melacak…</span>
                                        </x-filament::button>
                                    @endif

                                    @if ($shipment['can_reship'] ?? false)
                                        <x-filament::button
                                            type="button"
                                            wire:click="reshipShipment({{ $shipment['id'] }})"
                                            wire:loading.attr="disabled"
                                            icon="heroicon-o-arrow-uturn-right"
                                            size="sm"
                                            color="warning"
                                        >
                                            Kirim Ulang
                                        </x-filament::button>
                                    @endif

                                    @if ($komerceEnabled && ($orderIsPaid ?? false) && ($shipment['needs_pickup'] ?? false))
                                        <x-filament::button
                                            type="button"
                                            wire:click="requestPickup({{ $shipment['id'] }})"
                                            wire:loading.attr="disabled"
                                            icon="heroicon-o-truck"
                                            size="sm"
                                            color="primary"
                                        >
                                            Request Pickup Kurir
                                        </x-filament::button>
                                    @elseif ($komerceEnabled && ($orderIsPaid ?? false) && ! $shipment['can_print_label'] && ! ($shipment['awb'] || $shipment['tracking_number']))
                                        <x-filament::button
                                            type="button"
                                            wire:click="processDeliveryOrder({{ $shipment['id'] }})"
                                            wire:loading.attr="disabled"
                                            icon="heroicon-o-paper-airplane"
                                            size="sm"
                                            color="primary"
                                        >
                                            Daftarkan & Pickup Komerce
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- 3. BARIS SIMULASI KURIR LAPANGAN (SANDBOX) JIKA AKTIF --}}
                        @if ($canSimulateCourier ?? false)
                            <div class="rounded-xl bg-gray-50 p-4 text-xs ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-white/10">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-info-600 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-info-400 dark:ring-white/10">
                                            <x-heroicon-o-beaker class="size-4.5" />
                                        </div>
                                        <div>
                                            <span class="block text-xs font-bold text-gray-900 dark:text-white">
                                                Simulasi Kurir Lapangan (Testing)
                                            </span>
                                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">
                                                Uji status kurir untuk memeriksa auto-complete status pesanan.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2 flex-wrap">
                                        @if (! ($shipment['awb'] || $shipment['tracking_number']))
                                            <x-filament::button
                                                type="button"
                                                wire:click="simulateGenerateAwb({{ $shipment['id'] }})"
                                                wire:loading.attr="disabled"
                                                color="info"
                                                size="sm"
                                                icon="heroicon-o-document-plus"
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
                                                    size="sm"
                                                    icon="heroicon-o-truck"
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
                                                    size="sm"
                                                    icon="heroicon-o-check-badge"
                                                >
                                                    2. Kurir Scan Selesai Antar (Delivered)
                                                </x-filament::button>
                                            @endif

                                            @if ($shipment['status'] === 'delivered')
                                                <x-filament::badge color="success" icon="heroicon-s-check-circle">
                                                    Paket Selesai Diantar (Delivered)
                                                </x-filament::badge>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                    </div>
                </div>
            @empty
                <div class="rounded-xl bg-gray-50/60 dark:bg-gray-800/30 p-10 text-center">
                    <x-heroicon-o-truck class="size-10 text-gray-400 mx-auto mb-2" />
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Belum ada data pengiriman untuk pesanan ini.</p>
                </div>
            @endforelse

            {{-- Footer Note --}}
            @if (count($shipments) > 0 && count($overridableShipments) === 0)
                <p class="text-xs text-gray-400 dark:text-gray-500 pt-2 flex items-center gap-1.5">
                    <x-heroicon-m-lock-closed class="size-3.5 text-gray-400" />
                    <span>Gudang pengirim terkunci karena paket telah terdaftar atau nomor resi telah diterbitkan.</span>
                </p>
            @endif
        </div>
    </x-shopper::card>

</div>
