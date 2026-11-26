<div class="space-y-5">
    @if ($disputes->isNotEmpty())
        <x-shopper::card>
            <x-slot:title>
                <div class="flex items-center justify-between gap-2">
                    <x-shopper::section-heading
                        title="Antrean Sengketa Pembayaran"
                        description="Perlu peninjauan tim Finance / CS."
                    />
                    <x-filament::badge color="danger">
                        {{ $disputes->where('status', 'open')->count() }} terbuka
                    </x-filament::badge>
                </div>
            </x-slot:title>

            <div class="space-y-3">
                @foreach ($disputes as $dispute)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 p-3 text-xs ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-white/10">
                        <div class="space-y-1">
                            <p class="font-semibold text-gray-900 dark:text-white">
                                {{ strtoupper(str_replace('_', ' ', $dispute->type)) }} · {{ $dispute->driver }}
                                <span class="font-mono text-gray-500">({{ $dispute->reference }})</span>
                            </p>
                            <div class="flex flex-wrap items-center gap-2 text-gray-600 dark:text-gray-300">
                                <span>Nominal: <strong>{{ shopper_money_format((int) ($dispute->amount ?? 0), (string) $dispute->currency_code) }}</strong></span>
                                <x-filament::badge size="sm" :color="$dispute->status === 'open' ? 'danger' : 'gray'">
                                    {{ $dispute->status }}
                                </x-filament::badge>
                                @if ($dispute->reason)
                                    <span>· {{ $dispute->reason }}</span>
                                @endif
                            </div>
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
        </x-shopper::card>
    @endif

    @if ($proofs->isNotEmpty())
        <x-shopper::card
            title="Bukti Transfer Bank Manual"
            description="Verifikasi lampiran mutasi transfer pelanggan."
        >
            <div class="space-y-3">
                @foreach ($proofs as $proof)
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-gray-900 dark:text-white">
                                        #{{ $proof->id }} · {{ $proof->sender_bank ?: 'Bank' }} — {{ $proof->sender_name ?: 'Pelanggan' }}
                                    </span>
                                    @if ($proof->transfer_amount)
                                        <span class="font-medium text-gray-700 dark:text-gray-200">
                                            ({{ shopper_money_format((int) $proof->transfer_amount, (string) $order->currency_code) }})
                                        </span>
                                    @endif
                                    <x-filament::badge
                                        size="sm"
                                        :color="match ($proof->status) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' }"
                                    >
                                        {{ $proof->status }}
                                    </x-filament::badge>
                                </div>
                                <p class="text-gray-500 dark:text-gray-400">
                                    Diunggah {{ $proof->created_at?->format('d M Y H:i') }}
                                </p>
                            </div>

                            <x-filament::button
                                tag="a"
                                :href="route('shopper.orders.payment-proofs.show', ['order' => $order->id, 'proof' => $proof->id])"
                                target="_blank"
                                size="xs"
                                color="gray"
                                icon="heroicon-o-eye"
                            >
                                Lihat Bukti ({{ $proof->original_name }})
                            </x-filament::button>
                        </div>

                        @if ($proof->status === 'pending')
                            <div class="mt-3 grid grid-cols-1 gap-2 border-t border-gray-100 pt-3 sm:grid-cols-2 dark:border-white/10">
                                <div class="flex items-center gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model="proofBankRef" placeholder="Ref mutasi bank (opsional)" />
                                        </x-filament::input.wrapper>
                                    </div>
                                    <x-filament::button wire:click="approveProof({{ $proof->id }})" size="xs" color="success">
                                        Setujui &amp; Lunas
                                    </x-filament::button>
                                </div>

                                <div class="flex items-center gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model="proofRejectReason" placeholder="Alasan penolakan" />
                                        </x-filament::input.wrapper>
                                    </div>
                                    <x-filament::button wire:click="rejectProof({{ $proof->id }})" size="xs" color="danger">
                                        Tolak
                                    </x-filament::button>
                                </div>
                            </div>
                        @elseif ($proof->verification_notes)
                            <p class="mt-2 text-gray-600 dark:text-gray-300">Catatan verifikasi: {{ $proof->verification_notes }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-shopper::card>
    @endif

    @if ($returns->isNotEmpty())
        <x-shopper::card
            title="Permintaan Retur (RMA)"
            description="Persetujuan pengembalian barang dan restock gudang."
        >
            <div class="space-y-3">
                @foreach ($returns as $ret)
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-gray-900 dark:text-white">RMA #{{ $ret->id }} · {{ $ret->reason }}</span>
                                    <x-filament::badge
                                        size="sm"
                                        :color="match ($ret->status) { 'approved' => 'info', 'restocked', 'refunded' => 'success', 'rejected' => 'danger', default => 'warning' }"
                                    >
                                        {{ $ret->status }}
                                    </x-filament::badge>
                                </div>
                                @if ($ret->description)
                                    <p class="text-gray-600 dark:text-gray-300">{{ $ret->description }}</p>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($ret->status === 'requested')
                                    <x-filament::button wire:click="approveReturn({{ $ret->id }})" size="xs" color="primary">
                                        Setujui
                                    </x-filament::button>
                                    <x-filament::button wire:click="rejectReturn({{ $ret->id }})" size="xs" color="danger">
                                        Tolak
                                    </x-filament::button>
                                @elseif ($ret->status === 'approved' && ! $ret->restocked_at)
                                    <x-filament::button wire:click="restockReturn({{ $ret->id }})" size="xs" color="success">
                                        Barang Diterima &amp; Restock
                                    </x-filament::button>
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
        </x-shopper::card>
    @endif

    <x-shopper::card>
        <x-slot:title>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-shopper::section-heading
                    title="Buku Besar Refund"
                    description="Catatan pengembalian dana pelanggan."
                />
                <x-filament::badge :color="$refundedTotal > 0 ? 'warning' : 'gray'">
                    Total Refund: {{ shopper_money_format($refundedTotal, (string) $order->currency_code) }}
                </x-filament::badge>
            </div>
        </x-slot:title>

        <div class="space-y-4">
            @if ($refunds->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($refunds as $refund)
                        @php
                            $refundStatus = $refund->status instanceof \BackedEnum ? $refund->status->value : (string) $refund->status;
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2.5 text-xs ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-white/10">
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white om-tabular">
                                    {{ shopper_money_format((int) $refund->amount, (string) $refund->currency) }}
                                    <span class="font-normal text-gray-500">· {{ $refund->method ?: 'manual' }} ({{ $refund->reference ?: '-' }})</span>
                                </p>
                                <p class="mt-0.5 text-gray-600 dark:text-gray-300">{{ $refund->reason }}</p>
                            </div>
                            <x-filament::badge size="sm" :color="$refundStatus === 'refunded' ? 'success' : 'gray'">
                                {{ $refundStatus }}
                            </x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($refundedTotal < (int) $order->price_amount && in_array($order->payment_status?->value, ['paid', 'partially_refunded'], true))
                <form wire:submit="recordRefund" class="space-y-3 border-t border-gray-200 pt-4 dark:border-white/10">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Nominal Refund</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" min="1" wire:model="refundAmount" placeholder="Nominal" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Metode Refund</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="refundMethod">
                                    <option value="manual_bank_transfer">Transfer Bank Manual</option>
                                    <option value="gateway">API Gateway (jika didukung)</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Referensi Transfer</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="refundReference" placeholder="Ref transfer / gateway" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Alasan Refund</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="refundReason" placeholder="Alasan wajib refund" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="flex justify-end">
                        <x-filament::button type="submit" size="sm" color="warning" icon="heroicon-o-receipt-refund">
                            Catat Refund
                        </x-filament::button>
                    </div>
                </form>
            @elseif ($refunds->isEmpty())
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Belum ada catatan refund untuk pesanan ini.
                </p>
            @endif
        </div>
    </x-shopper::card>
</div>
