<x-shopper::container class="py-5">
    <x-shopper::heading title="Antrean Sengketa Pembayaran" />
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Dana masuk setelah pesanan dibatalkan, pembayaran ganda, atau selisih nominal gateway.</p>

    <div class="mt-4 flex gap-2">
        @foreach (['open' => 'Terbuka', 'resolved_refunded' => 'Selesai (Refund)', 'dismissed' => 'Ditutup', 'all' => 'Semua'] as $key => $label)
            <x-filament::button wire:click="$set('statusFilter', '{{ $key }}')" size="xs" :color="$statusFilter === $key ? 'primary' : 'gray'">
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    <div class="mt-5 rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-2 pr-3">Order</th>
                        <th class="py-2 pr-3">Jenis</th>
                        <th class="py-2 pr-3">Driver / Ref</th>
                        <th class="py-2 pr-3">Nominal</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($disputes as $dispute)
                        <tr>
                            <td class="py-2 pr-3 font-medium">
                                @if ($dispute->order)
                                    <a href="{{ route('shopper.orders.detail', $dispute->order) }}" class="text-primary-600 underline">#{{ $dispute->order->number }}</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $dispute->type }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $dispute->driver }} / {{ $dispute->reference }}</td>
                            <td class="py-2 pr-3">{{ shopper_money_format((int) ($dispute->amount ?? 0), (string) $dispute->currency_code) }}</td>
                            <td class="py-2 pr-3 font-medium">{{ $dispute->status }}</td>
                            <td class="py-2">
                                @if ($dispute->status === 'open')
                                    <div class="flex gap-2">
                                        <x-filament::button wire:click="resolveWithRefund({{ $dispute->id }})" size="xs" color="danger">Refund</x-filament::button>
                                        <x-filament::button wire:click="dismiss({{ $dispute->id }})" size="xs" color="gray">Tutup</x-filament::button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">Tidak ada sengketa pembayaran pada filter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $disputes->links() }}</div>
    </div>
</x-shopper::container>
