<x-shopper::container class="py-5">
    <x-shopper::heading title="Daftar Retur & RMA" />
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Kelola pengajuan retur pelanggan, penerimaan barang kembali ke stok, dan eskalasi refund.</p>

    <div class="mt-4 flex gap-2">
        @foreach (['requested' => 'Baru', 'approved' => 'Disetujui', 'restocked' => 'Sudah Restock', 'refunded' => 'Selesai Refund', 'rejected' => 'Ditolak', 'all' => 'Semua'] as $key => $label)
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
                        <th class="py-2 pr-3">Alasan</th>
                        <th class="py-2 pr-3">Item</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($returns as $ret)
                        <tr>
                            <td class="py-2 pr-3 font-medium">
                                @if ($ret->order)
                                    <a href="{{ route('shopper.orders.detail', $ret->order) }}" class="text-primary-600 underline">#{{ $ret->order->number }}</a>
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $ret->reason }}</td>
                            <td class="py-2 pr-3">
                                @foreach ((array) $ret->items as $item)
                                    <div>{{ $item['name'] ?? 'Item' }} × {{ $item['qty'] ?? 1 }}</div>
                                @endforeach
                            </td>
                            <td class="py-2 pr-3 font-medium">{{ $ret->status }}</td>
                            <td class="py-2">
                                <div class="flex gap-2">
                                    @if ($ret->status === 'requested')
                                        <x-filament::button wire:click="approve({{ $ret->id }})" size="xs" color="primary">Setujui</x-filament::button>
                                        <x-filament::button wire:click="reject({{ $ret->id }})" size="xs" color="danger">Tolak</x-filament::button>
                                    @elseif ($ret->status === 'approved' && ! $ret->restocked_at)
                                        <x-filament::button wire:click="restock({{ $ret->id }})" size="xs" color="success">Restock</x-filament::button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Tidak ada permintaan retur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $returns->links() }}</div>
    </div>
</x-shopper::container>
