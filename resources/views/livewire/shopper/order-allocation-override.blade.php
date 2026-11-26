<div>
    @if ($canOverride)
        <div class="py-4">
            <x-shopper::card
                title="Pindah Gudang Pengirim (Re-alokasi Stok)"
                description="Dapat dipindahkan sebelum paket didaftarkan ke kurir. Setelah pickup diminta atau resi terbit, gudang pengirim otomatis terkunci."
            >
                <div class="space-y-4">
                    @if ($successMessage)
                        <x-shopper::alert
                            color="success"
                            icon="heroicon-o-check-circle"
                            :message="$successMessage"
                        />
                    @endif

                    @if ($overrideError)
                        <x-shopper::alert
                            color="danger"
                            icon="heroicon-o-exclamation-circle"
                            :message="$overrideError"
                        />
                    @endif

                    <form wire:submit="applyOverride" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="shipment_line_id">
                                    Pilih Produk
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select id="shipment_line_id" wire:model.live="shipment_line_id">
                                        @foreach ($lineOptions as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="qty">
                                    Jumlah (Qty)
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="qty" type="number" min="1" wire:model="qty" />
                                </x-filament::input.wrapper>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="from_inventory_id">
                                    Gudang Asal
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select id="from_inventory_id" wire:model="from_inventory_id">
                                        @foreach ($inventories as $inventory)
                                            <option value="{{ $inventory['id'] }}">{{ $inventory['name'] }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="to_inventory_id">
                                    Gudang Tujuan
                                </label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select id="to_inventory_id" wire:model="to_inventory_id">
                                        @foreach ($inventories as $inventory)
                                            <option value="{{ $inventory['id'] }}">{{ $inventory['name'] }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300" for="override_reason">
                                    Alasan Override (Opsional)
                                </label>
                                <x-shopper::forms.textarea
                                    id="override_reason"
                                    rows="2"
                                    wire:model="reason"
                                    placeholder="Contoh: Stok gudang asal menipis / dekat alamat tujuan"
                                />
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-filament::button
                                type="submit"
                                size="sm"
                                color="gray"
                                icon="heroicon-o-arrow-path-rounded-square"
                            >
                                Pindahkan Stok Gudang
                            </x-filament::button>
                        </div>
                    </form>

                    @if ($overrideLogs->isNotEmpty())
                        <div class="border-t border-gray-200 pt-4 dark:border-white/10">
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">Riwayat Override Gudang</p>
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
