<div>
    @if ($canOverride)
        <div class="py-4">
            <x-shopper::card>
                <div class="space-y-4 text-xs">
                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center size-8 rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-400 shrink-0 mt-0.5">
                            <x-heroicon-o-arrows-right-left class="size-4" />
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Pindah Gudang Pengirim (Re-alokasi Stok)</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                Dapat dipindahkan sebelum paket didaftarkan ke kurir. Setelah pickup diminta atau resi terbit, gudang pengirim otomatis terkunci.
                            </p>
                        </div>
                    </div>

                    @if ($successMessage)
                        <p class="text-xs font-medium text-emerald-700 dark:text-emerald-400" role="status">{{ $successMessage }}</p>
                    @endif

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
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block font-semibold text-gray-700 dark:text-gray-300 text-xs" for="override_reason">Alasan Override (Opsional)</label>
                                <textarea id="override_reason" rows="2" wire:model="reason" placeholder="Contoh: Stok gudang asal menipis / dekat alamat tujuan" class="w-full rounded-lg border-gray-300 text-xs shadow-2xs dark:border-gray-700 dark:bg-gray-900 dark:text-white"></textarea>
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

                    @if ($overrideLogs->isNotEmpty())
                        <div class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10">
                            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Riwayat Override Gudang</p>
                            <ul class="mt-2 space-y-1.5 text-xs text-gray-500 dark:text-gray-400">
                                @foreach ($overrideLogs as $log)
                                    <li>
                                        {{ $log->created_at?->format('d M Y H:i') }} ·
                                        {{ $log->fromInventory?->name ?? '?' }} &rarr; {{ $log->toInventory?->name ?? '?' }}
                                        (Qty {{ (int) data_get($log->payload, 'move.qty', 0) }})
                                        @if (data_get($log->payload, 'reason'))
                                            · "{{ data_get($log->payload, 'reason') }}"
                                        @endif
                                        @if ($log->actor)
                                            oleh {{ $log->actor->full_name }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </x-shopper::card>
        </div>
    @endif
</div>
