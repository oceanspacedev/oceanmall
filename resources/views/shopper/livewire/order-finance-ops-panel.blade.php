<div class="space-y-5">
    @if ($disputes->isNotEmpty())
        <div class="rounded-xl border border-rose-300 bg-rose-50/90 p-4 dark:border-rose-800 dark:bg-rose-950/30">
            <h3 class="text-sm font-semibold text-rose-900 dark:text-rose-200">Antrean Sengketa Pembayaran ({{ $disputes->where('status', 'open')->count() }} terbuka)</h3>
            <div class="mt-3 space-y-2">
                @foreach ($disputes as $dispute)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white p-3 text-xs shadow-2xs dark:bg-gray-900">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ strtoupper(str_replace('_', ' ', $dispute->type)) }} · {{ $dispute->driver }} (Ref: {{ $dispute->reference }})</p>
                            <p class="text-gray-600 dark:text-gray-300">
                                Nominal: {{ shopper_money_format((int) ($dispute->amount ?? 0), (string) $dispute->currency_code) }} · Status: <strong>{{ $dispute->status }}</strong>
                                @if ($dispute->reason) · {{ $dispute->reason }} @endif
                            </p>
                        </div>
                        @if ($dispute->status === 'open')
                            <div class="flex items-center gap-2">
                                <x-filament::button wire:click="resolveDisputeRefund({{ $dispute->id }})" size="xs" color="danger">
                                    Catat Refund Sengketa
                                </x-filament::button>
                                <x-filament::button wire:click="dismissDispute({{ $dispute->id }})" size="xs" color="gray">
                                    Tutup
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($proofs->isNotEmpty())
        <div class="rounded-xl bg-white p-5 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Bukti Transfer Bank Manual</h3>
            <div class="mt-3 space-y-3">
                @foreach ($proofs as $proof)
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white">
                                    #{{ $proof->id }} · {{ $proof->sender_bank ?: 'Bank' }} — {{ $proof->sender_name ?: 'Pelanggan' }}
                                    @if ($proof->transfer_amount)
                                        ({{ shopper_money_format((int) $proof->transfer_amount, (string) $order->currency_code) }})
                                    @endif
                                </p>
                                <p class="text-gray-500">Status: <strong>{{ $proof->status }}</strong> · {{ $proof->created_at?->format('d M Y H:i') }}</p>
                            </div>
                            <a href="{{ route('shopper.orders.payment-proofs.show', ['order' => $order->id, 'proof' => $proof->id]) }}" target="_blank" class="text-primary-600 underline font-medium">
                                Lihat Bukti ({{ $proof->original_name }})
                            </a>
                        </div>
                        @if ($proof->status === 'pending')
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <input type="text" wire:model="proofBankRef" placeholder="Ref mutasi bank (opsional)" class="rounded border-gray-300 text-xs dark:border-white/10 dark:bg-gray-800" />
                                <x-filament::button wire:click="approveProof({{ $proof->id }})" size="xs" color="success">
                                    Setujui & Tandai Lunas
                                </x-filament::button>
                                <input type="text" wire:model="proofRejectReason" placeholder="Alasan penolakan" class="rounded border-gray-300 text-xs dark:border-white/10 dark:bg-gray-800" />
                                <x-filament::button wire:click="rejectProof({{ $proof->id }})" size="xs" color="danger">
                                    Tolak
                                </x-filament::button>
                            </div>
                        @elseif ($proof->verification_notes)
                            <p class="mt-1 text-gray-600 dark:text-gray-300">Catatan verifikasi: {{ $proof->verification_notes }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($returns->isNotEmpty())
        <div class="rounded-xl bg-white p-5 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Permintaan Retur (RMA)</h3>
            <div class="mt-3 space-y-3">
                @foreach ($returns as $ret)
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10 space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white">RMA #{{ $ret->id }} · {{ $ret->reason }} ({{ $ret->status }})</p>
                                @if ($ret->description)
                                    <p class="text-gray-600 dark:text-gray-300">{{ $ret->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($ret->status === 'requested')
                                    <x-filament::button wire:click="approveReturn({{ $ret->id }})" size="xs" color="primary">Setujui</x-filament::button>
                                    <x-filament::button wire:click="rejectReturn({{ $ret->id }})" size="xs" color="danger">Tolak</x-filament::button>
                                @elseif ($ret->status === 'approved' && ! $ret->restocked_at)
                                    <x-filament::button wire:click="restockReturn({{ $ret->id }})" size="xs" color="success">Barang Diterima & Restock</x-filament::button>
                                @endif
                            </div>
                        </div>
                        <ul class="list-disc pl-4 text-gray-600 dark:text-gray-400">
                            @foreach ((array) $ret->items as $item)
                                <li>{{ $item['name'] ?? 'Item' }} × {{ $item['qty'] ?? 1 }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="rounded-xl bg-white p-5 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-3">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Buku Besar Refund</h3>
                <p class="text-xs text-gray-500">Total telah direfund: <strong>{{ shopper_money_format($refundedTotal, (string) $order->currency_code) }}</strong></p>
            </div>
        </div>

        @if ($refunds->isNotEmpty())
            <div class="space-y-2">
                @foreach ($refunds as $refund)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-gray-800/50">
                        <div>
                            <span class="font-semibold text-gray-900 dark:text-white">{{ shopper_money_format((int) $refund->amount, (string) $refund->currency) }}</span>
                            <span class="text-gray-500"> · {{ $refund->method ?: 'manual' }} ({{ $refund->reference ?: '-' }})</span>
                            <p class="text-gray-600 dark:text-gray-300">{{ $refund->reason }}</p>
                        </div>
                        <span class="rounded bg-gray-200 px-2 py-0.5 font-medium dark:bg-white/10">{{ $refund->status instanceof \BackedEnum ? $refund->status->value : $refund->status }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($refundedTotal < (int) $order->price_amount && in_array($order->payment_status?->value, ['paid', 'partially_refunded'], true))
            <form wire:submit="recordRefund" class="grid gap-2 pt-2 sm:grid-cols-4">
                <input type="number" min="1" wire:model="refundAmount" placeholder="Nominal refund" class="rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-gray-800" />
                <select wire:model="refundMethod" class="rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-gray-800">
                    <option value="manual_bank_transfer">Transfer Bank Manual</option>
                    <option value="gateway">API Gateway (jika didukung)</option>
                </select>
                <input type="text" wire:model="refundReference" placeholder="Ref transfer / gateway" class="rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-gray-800" />
                <input type="text" wire:model="refundReason" placeholder="Alasan wajib refund" class="rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-gray-800 sm:col-span-3" />
                <x-filament::button type="submit" size="xs" color="warning">Catat Refund</x-filament::button>
            </form>
        @endif
    </div>
</div>
