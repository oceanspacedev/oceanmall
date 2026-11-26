<div class="space-y-3">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('shopper.orders.payment-proofs') }}" class="block rounded-xl bg-white p-4 ring-1 ring-gray-200 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Bukti Transfer Pending</p>
            <p class="mt-1 text-2xl font-bold {{ $pendingProofs > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">
                {{ $pendingProofs }}
            </p>
            <p class="mt-1 text-[11px] text-primary-600">Buka antrean verifikasi &rarr;</p>
        </a>

        <a href="{{ route('shopper.orders.disputes') }}" class="block rounded-xl bg-white p-4 ring-1 ring-gray-200 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Sengketa Pembayaran Open</p>
            <p class="mt-1 text-2xl font-bold {{ $openDisputes > 0 ? 'text-rose-600' : 'text-gray-900 dark:text-white' }}">
                {{ $openDisputes }}
            </p>
            <p class="mt-1 text-[11px] text-primary-600">Tinjau sengketa dana &rarr;</p>
        </a>

        <a href="{{ route('shopper.orders.returns') }}" class="block rounded-xl bg-white p-4 ring-1 ring-gray-200 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Permintaan Retur / RMA Baru</p>
            <p class="mt-1 text-2xl font-bold {{ $pendingReturns > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">
                {{ $pendingReturns }}
            </p>
            <p class="mt-1 text-[11px] text-primary-600">Proses antrean RMA &rarr;</p>
        </a>

        <a href="{{ route('shopper.orders.index') }}" class="block rounded-xl bg-white p-4 ring-1 ring-gray-200 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Order Lunas Belum Kirim (&gt;24j)</p>
            <p class="mt-1 text-2xl font-bold {{ $overdueShipments > 0 ? 'text-rose-600' : 'text-gray-900 dark:text-white' }}">
                {{ $overdueShipments }}
            </p>
            <p class="mt-1 text-[11px] text-primary-600">Cek daftar pesanan &rarr;</p>
        </a>
    </div>

    @if ($providerQuotas !== [])
        <div class="flex flex-wrap items-center gap-3 rounded-xl bg-gray-50 px-4 py-2.5 text-xs ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10">
            <span class="font-semibold text-gray-700 dark:text-gray-200">Status Integrasi Pengiriman / Pembayaran:</span>
            @foreach ($providerQuotas as $quota)
                <span @class([
                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-medium',
                    'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300' => $quota['exhausted'],
                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' => ! $quota['exhausted'],
                ])>
                    {{ $quota['label'] }}:
                    @if ($quota['limit'] !== null)
                        {{ $quota['used'] }}/{{ $quota['limit'] }}
                    @else
                        {{ $quota['exhausted'] ? 'Terbatas' : 'Normal' }}
                    @endif
                </span>
            @endforeach
        </div>
    @endif
</div>
