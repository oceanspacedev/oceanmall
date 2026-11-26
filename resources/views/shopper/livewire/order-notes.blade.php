<div class="space-y-4">
    <div>
        <h3 class="text-xs leading-4 font-medium tracking-wider text-gray-900 uppercase dark:text-white">
            Catatan Internal &amp; Audit Order
        </h3>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Hanya terlihat oleh tim internal (tidak tampil ke pelanggan).
        </p>
    </div>

    @if ($canWrite)
        <form wire:submit="addNote" class="space-y-2.5">
            <div>
                <label for="order_internal_note" class="sr-only">Catatan internal</label>
                <x-shopper::forms.textarea
                    id="order_internal_note"
                    wire:model="body"
                    rows="2"
                    placeholder="Tulis catatan koordinasi CS / Finance / Gudang…"
                />
                @error('body')
                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex justify-end">
                <x-filament::button type="submit" size="xs" color="primary" icon="heroicon-o-chat-bubble-left-ellipsis">
                    Simpan Catatan
                </x-filament::button>
            </div>
        </form>
    @endif

    <div class="max-h-72 space-y-2 overflow-y-auto">
        @forelse ($entries as $entry)
            <div class="rounded-lg bg-white p-3 text-xs ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex flex-wrap items-center justify-between gap-2 text-gray-500 dark:text-gray-400">
                    <div class="flex items-center gap-1.5">
                        <span class="font-medium text-gray-800 dark:text-gray-200">
                            {{ $entry->author?->full_name ?: $entry->author?->email ?: 'Sistem' }}
                        </span>
                        @if ($entry->type === \App\Models\OrderNote::TYPE_AUDIT)
                            <x-filament::badge size="sm" color="warning">Audit</x-filament::badge>
                        @endif
                    </div>
                    <span>{{ $entry->created_at?->format('d M Y H:i') }}</span>
                </div>
                <p class="mt-1.5 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $entry->body }}</p>
            </div>
        @empty
            @if ($order->notes)
                <div class="rounded-lg bg-white p-3 text-xs text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10">
                    {{ $order->notes }}
                </div>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada catatan internal.</p>
            @endif
        @endforelse
    </div>
</div>
