<x-shopper::container class="py-5">
    <x-shopper::heading title="Verifikasi Transfer Bank Manual" />
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Antrean verifikasi bukti transfer dari pelanggan oleh tim Finance.</p>

    <div class="mt-4 flex gap-2">
        @foreach (['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'all' => 'Semua'] as $key => $label)
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
                        <th class="py-2 pr-3">Pengirim</th>
                        <th class="py-2 pr-3">Nominal</th>
                        <th class="py-2 pr-3">Bukti</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($proofs as $proof)
                        <tr>
                            <td class="py-2 pr-3 font-medium">
                                @if ($proof->order)
                                    <a href="{{ route('shopper.orders.detail', $proof->order) }}" class="text-primary-600 underline">#{{ $proof->order->number }}</a>
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $proof->sender_bank ?: '-' }} · {{ $proof->sender_name ?: ($proof->uploader?->full_name ?? '-') }}</td>
                            <td class="py-2 pr-3">{{ $proof->transfer_amount ? shopper_money_format((int) $proof->transfer_amount, (string) ($proof->order?->currency_code ?? 'IDR')) : '-' }}</td>
                            <td class="py-2 pr-3">
                                @if ($proof->order)
                                    <a href="{{ route('shopper.orders.payment-proofs.show', ['order' => $proof->order->id, 'proof' => $proof->id]) }}" target="_blank" class="text-primary-600 underline">{{ $proof->original_name }}</a>
                                @endif
                            </td>
                            <td class="py-2 pr-3 font-medium">{{ $proof->status }}</td>
                            <td class="py-2">
                                @if ($proof->status === 'pending')
                                    <div class="flex gap-2">
                                        <x-filament::button wire:click="approve({{ $proof->id }})" size="xs" color="success">Setujui</x-filament::button>
                                        <x-filament::button wire:click="reject({{ $proof->id }})" size="xs" color="danger">Tolak</x-filament::button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">Tidak ada bukti transfer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $proofs->links() }}</div>
    </div>
</x-shopper::container>
