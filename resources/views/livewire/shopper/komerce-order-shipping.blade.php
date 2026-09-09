<div>
    <x-shopper::card>
        <x-slot:title>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between py-1">
                <div class="flex items-center gap-3.5 flex-wrap">
                    <div class="flex items-center justify-center size-10 rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-400 shrink-0 shadow-2xs">
                        <x-heroicon-o-truck class="size-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="text-base font-bold text-gray-900 dark:text-white">
                                Pengiriman RajaOngkir / Komerce
                            </span>
                            @if ($canPrintAnyLabel && ! $hasUnprocessedShipment)
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-400">
                                    <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                    Terkirim ke Komerce
                                </span>
                            @elseif ($canPrintAnyLabel && $hasUnprocessedShipment)
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-400">
                                    <span class="size-2 rounded-full bg-sky-500"></span>
                                    Sebagian Terdaftar
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400">
                                    <span class="size-2 rounded-full bg-amber-500"></span>
                                    Belum Terdaftar
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Multi-gudang fulfillment, cetak resi thermal, & pelacakan kurir real-time.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    @if ($order->payment_status !== \Shopper\Core\Enum\PaymentStatus::Paid)
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

                    @if ($hasTrackableShipment && $komerceEnabled)
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
            @unless ($komerceEnabled)
                <div class="rounded-xl bg-amber-50 p-4 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-300 flex items-center gap-3">
                    <x-heroicon-m-exclamation-triangle class="size-5 text-amber-600 shrink-0" />
                    <span>Integrasi Komerce belum aktif. Pastikan API key terpasang di file <code>.env</code>.</span>
                </div>
            @endunless

            @if ($successMessage)
                <div class="rounded-xl bg-emerald-50 p-4 text-xs text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300 font-medium flex items-center gap-3" role="status">
                    <x-heroicon-m-check-circle class="size-5 text-emerald-600 shrink-0" />
                    <span>{{ $successMessage }}</span>
                </div>
            @endif

            @if ($overrideError)
                <div class="rounded-xl bg-rose-50 p-4 text-xs text-rose-900 dark:bg-rose-500/10 dark:text-rose-300 font-medium flex items-center gap-3" role="alert">
                    <x-heroicon-m-x-circle class="size-5 text-rose-600 shrink-0" />
                    <span>{{ $overrideError }}</span>
                </div>
            @endif

            {{-- Shipments List --}}
            @forelse ($shipments as $shipment)
                @php
                    $status = $shipment['status'] ?? 'pending';
                    $statusClasses = match ($status) {
                        'delivered' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
                        'picked_up', 'in_transit', 'shipped' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300',
                        'labeled' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-300',
                        'cancelled' => 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-300',
                        default => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
                    };
                @endphp

                <div class="space-y-6 {{ ! $loop->first ? 'pt-6 border-t border-gray-100 dark:border-gray-800' : '' }}">
                    {{-- Precision Shipment Container (No Harsh Borders) --}}
                    <div class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow-2xs space-y-5">
                        
                        {{-- 1. STRIP RINGKASAN 4-KOLOM PRESISI (Ekspedisi, Resi, Status, Ongkir) --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-gray-50/90 dark:bg-gray-700/40">
                            {{-- Kolom 1: Ekspedisi & Layanan --}}
                            <div>
                                <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider block">
                                    Ekspedisi Kurir
                                </span>
                                <div class="flex items-center gap-2.5 mt-1.5">
                                    <div class="flex items-center justify-center w-12 h-7 bg-white dark:bg-white rounded p-0.5 shrink-0 overflow-hidden shadow-2xs">
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
                                        <span class="text-[11px] font-semibold text-rose-600 dark:text-rose-400 block leading-tight mt-0.5">
                                            {{ $shipment['service'] }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Kolom 2: Nomor Resi (AWB) dengan 1-Click Copy --}}
                            <div x-data="{ copied: false }">
                                <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider block">
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
                                                <x-heroicon-m-check class="size-4 text-emerald-600" />
                                            </template>
                                        </button>
                                    @endif
                                </div>
                            </div>

                            {{-- Kolom 3: Status Pengiriman --}}
                            <div>
                                <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider block">
                                    Status Pengiriman
                                </span>
                                <div class="mt-1.5">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusClasses }}">
                                        @if ($status === 'delivered')
                                            <x-heroicon-m-check-circle class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        @elseif (in_array($status, ['picked_up', 'in_transit', 'shipped'], true))
                                            <x-heroicon-m-truck class="size-3.5 shrink-0 text-sky-600 dark:text-sky-400" />
                                        @elseif ($status === 'labeled')
                                            <x-heroicon-m-qr-code class="size-3.5 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                        @elseif ($status === 'cancelled')
                                            <x-heroicon-m-x-circle class="size-3.5 shrink-0 text-rose-600 dark:text-rose-400" />
                                        @else
                                            <x-heroicon-m-clock class="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                                        @endif
                                        {{ $shipment['status_label'] }}
                                    </span>
                                </div>
                            </div>

                            {{-- Kolom 4: Biaya Ongkir --}}
                            <div>
                                <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider block">
                                    Biaya Ongkos Kirim
                                </span>
                                <div class="mt-1.5">
                                    <span class="text-sm font-bold text-emerald-700 dark:text-emerald-400 leading-normal block">
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
                                            <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 block">Gudang Asal Pengirim</span>
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
                                            <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 block">Tujuan Pengiriman</span>
                                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                                <span class="text-xs font-bold text-gray-900 dark:text-white">{{ $shipment['receiver_name'] }}</span>
                                                @if (! empty($shipment['receiver_phone']) && $shipment['receiver_phone'] !== '-')
                                                    <span class="text-[11px] text-gray-500 dark:text-gray-400 font-mono flex items-center gap-1">
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
                                            <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                                <x-heroicon-s-check-circle class="size-3.5 text-emerald-500" />
                                                Terdaftar
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Daftar Barang (Tanpa Border) --}}
                                <div class="space-y-1.5">
                                    <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 block">
                                        Item dalam Paket ({{ count($shipment['lines']) }}):
                                    </span>
                                    @foreach ($shipment['lines'] as $line)
                                        <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-gray-50/90 dark:bg-gray-700/40 text-xs">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <x-heroicon-o-cube class="size-3.5 text-gray-400 shrink-0" />
                                                <span class="font-medium text-gray-900 dark:text-white truncate">{{ $line['name'] }}</span>
                                            </div>
                                            <span class="font-bold text-primary-700 dark:text-primary-400 bg-primary-50 dark:bg-primary-950/60 px-2 py-0.5 rounded text-[11px] shrink-0 ml-2">
                                                × {{ $line['qty'] }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- Tombol Aksi Pengiriman --}}
                                <div class="flex items-center gap-2 pt-1 flex-wrap">
                                    @if ($shipment['can_print_label'] && $komerceEnabled)
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

                                    @if (($shipment['awb'] || $shipment['tracking_number']) && $komerceEnabled)
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
                            <div class="p-4 rounded-xl bg-sky-50/70 dark:bg-sky-950/30 text-xs">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex items-center justify-center size-9 rounded-lg bg-white dark:bg-sky-900/60 text-sky-600 dark:text-sky-300 shadow-2xs shrink-0">
                                            <x-heroicon-o-beaker class="size-4.5" />
                                        </div>
                                        <div>
                                            <span class="text-xs font-bold text-sky-950 dark:text-sky-100 block">
                                                Simulasi Kurir Lapangan (Testing)
                                            </span>
                                            <p class="text-xs text-sky-700/90 dark:text-sky-300/80 mt-0.5">
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
                                                <span class="inline-flex items-center gap-1.5 font-bold text-xs text-emerald-800 dark:text-emerald-300 bg-emerald-100/90 dark:bg-emerald-950/60 px-3 py-1.5 rounded-lg">
                                                    <x-heroicon-s-check-circle class="size-4 text-emerald-600 shrink-0" />
                                                    Paket Selesai Diantar (Delivered)
                                                </span>
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

    {{-- Override Gudang Form (if applicable) --}}
    @if (count($overridableShipments) > 0)
        <div class="mt-5">
            <x-shopper::card>
                <div class="space-y-4 text-xs">
                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center size-8 rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-400 shrink-0 mt-0.5">
                            <x-heroicon-o-arrows-right-left class="size-4" />
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Pindah Gudang Pengirim (Re-alokasi Stok)</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                Dapat dipindahkan sebelum paket didaftarkan ke Komerce. Setelah request pickup/resi terbit, gudang pengirim otomatis terkunci.
                            </p>
                        </div>
                    </div>

                    <form wire:submit="applyOverride" class="space-y-4 pt-1">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block font-semibold text-gray-700 dark:text-gray-300 text-xs" for="shipment_line_id">Pilih Produk</label>
                                <select id="shipment_line_id" wire:model.live="shipment_line_id" class="w-full rounded-lg border-gray-300 text-xs shadow-2xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                    @foreach ($lineOptions as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block font-semibold text-gray-700 dark:text-gray-300 text-xs" for="qty">Jumlah (Qty)</label>
                                <input id="qty" type="number" min="1" wire:model="qty" class="w-full rounded-lg border-gray-300 text-xs shadow-2xs dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="mb-1.5 block font-semibold text-gray-700 dark:text-gray-300 text-xs" for="from_inventory_id">Gudang Asal</label>
                                <select id="from_inventory_id" wire:model="from_inventory_id" class="w-full rounded-lg border-gray-300 text-xs shadow-2xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                    @foreach ($inventories as $inventory)
                                        <option value="{{ $inventory['id'] }}">{{ $inventory['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block font-semibold text-gray-700 dark:text-gray-300 text-xs" for="to_inventory_id">Gudang Tujuan</label>
                                <select id="to_inventory_id" wire:model="to_inventory_id" class="w-full rounded-lg border-gray-300 text-xs shadow-2xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                    @foreach ($inventories as $inventory)
                                        <option value="{{ $inventory['id'] }}">{{ $inventory['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if ($overrideError)
                            <p class="text-xs text-rose-600 dark:text-rose-400 mt-2 flex items-center gap-1.5 font-medium">
                                <x-heroicon-m-exclamation-circle class="size-4" />
                                <span>{{ $overrideError }}</span>
                            </p>
                        @endif

                        <div class="pt-2">
                            <x-filament::button type="submit" size="sm" color="gray" icon="heroicon-o-arrow-path-rounded-square">
                                Pindahkan Stok Gudang
                            </x-filament::button>
                        </div>
                    </form>
                </div>
            </x-shopper::card>
        </div>
    @endif
</div>
