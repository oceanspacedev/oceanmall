<div class="rounded-xl bg-white p-5 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-4">
    <div>
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Catatan Internal & Audit Order</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">Hanya terlihat oleh tim internal (tidak dikirim ke storefront pelanggan).</p>
    </div>

    @if ($canWrite)
        <form wire:submit="addNote" class="space-y-2">
            <textarea
                wire:model="body"
                rows="2"
                placeholder="Tulis catatan koordinasi CS / Finance / Gudang…"
                class="w-full rounded-lg border-gray-300 text-xs shadow-xs focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white"
            ></textarea>
            @error('body')
                <p class="text-xs text-rose-600">{{ $message }}</p>
            @enderror
            <div class="flex justify-end">
                <x-filament::button type="submit" size="xs" color="primary">
                    Simpan Catatan
                </x-filament::button>
            </div>
        </form>
    @endif

    <div class="space-y-2 max-h-72 overflow-y-auto">
        @forelse ($entries as $entry)
            <div class="rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800/60">
                <div class="flex items-center justify-between gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-gray-700 dark:text-gray-300">
                        {{ $entry->author?->full_name ?: $entry->author?->email ?: 'Sistem' }}
                        @if ($entry->type === \App\Models\OrderNote::TYPE_AUDIT)
                            · <span class="text-amber-600 dark:text-amber-400">Audit</span>
                        @endif
                    </span>
                    <span>{{ $entry->created_at?->format('d M Y H:i') }}</span>
                </div>
                <p class="mt-1 text-gray-800 dark:text-gray-200 whitespace-pre-line">{{ $entry->body }}</p>
            </div>
        @empty
            @if ($order->notes)
                <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800/60 dark:text-gray-200">
                    {{ $order->notes }}
                </div>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada catatan internal.</p>
            @endif
        @endforelse
    </div>
</div>
