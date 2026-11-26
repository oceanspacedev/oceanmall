<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $order->invoice_number ?: $order->number }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; color: #111827; margin: 0; padding: 24px; background: #f9fafb; }
        .sheet { max-width: 820px; margin: 0 auto; background: #fff; padding: 32px; border: 1px solid #e5e7eb; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
        .muted { color: #6b7280; font-size: 13px; }
        h1 { margin: 0 0 4px 0; font-size: 22px; }
        h2 { margin: 0 0 8px 0; font-size: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 13px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 10px 8px; text-align: left; }
        th.right, td.right { text-align: right; }
        .totals { width: 320px; margin-left: auto; margin-top: 18px; font-size: 13px; }
        .totals tr td { border-bottom: none; padding: 4px 0; }
        .totals tr.grand td { border-top: 1px solid #111827; padding-top: 8px; font-weight: 700; font-size: 15px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; background: #e0f2fe; color: #0369a1; }
        .print-bar { max-width: 820px; margin: 0 auto 12px auto; display: flex; justify-content: flex-end; gap: 8px; }
        .btn { background: #111827; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { border: none; padding: 0; }
            .print-bar { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-bar">
        <button class="btn" type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>

    <div class="sheet">
        <div class="row">
            <div>
                <h1>{{ $storeName }}</h1>
                @if ($legalName !== $storeName)
                    <div class="muted">{{ $legalName }}</div>
                @endif
                @if ($storeAddress)
                    <div class="muted">{{ $storeAddress }}</div>
                @endif
                @if ($storePhone || $storeEmail)
                    <div class="muted">{{ trim($storePhone.' '.$storeEmail) }}</div>
                @endif
                @if ($isPkp && $companyNpwp)
                    <div class="muted">NPWP PKP: <strong>{{ $companyNpwp }}</strong></div>
                @endif
            </div>
            <div style="text-align: right;">
                <h2>INVOICE</h2>
                <div><strong>{{ $order->invoice_number ?: 'DRAFT / '.$order->number }}</strong></div>
                <div class="muted">Pesanan: {{ $order->number }}</div>
                <div class="muted">Tanggal: {{ \Illuminate\Support\Carbon::parse($order->paid_at ?? $order->created_at ?? now())->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</div>
                <div style="margin-top: 6px;">
                    <span class="badge">{{ strtoupper((string) $order->payment_status->value) }}</span>
                </div>
            </div>
        </div>

        <div class="row" style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
            <div>
                <div class="muted">Ditagihkan / Dikirim Kepada:</div>
                <strong>{{ $order->shippingAddress?->full_name ?: $order->customer?->full_name ?: '-' }}</strong>
                <div class="muted">{{ $order->shippingAddress?->street_address ?: '-' }}</div>
                <div class="muted">{{ trim(implode(', ', array_filter([$order->shippingAddress?->city, $order->shippingAddress?->state, $order->shippingAddress?->postal_code]))) }}</div>
                @if ($order->shippingAddress?->phone_number)
                    <div class="muted">Telp: {{ $order->shippingAddress->phone_number }}</div>
                @endif
            </div>
            <div>
                <div class="muted">Metode Pembayaran:</div>
                <strong>{{ $order->paymentMethod?->title ?: 'Manual' }}</strong>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>SKU</th>
                    <th class="right">Qty</th>
                    <th class="right">Harga Satuan</th>
                    <th class="right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td>{{ $item->sku ?: '-' }}</td>
                        <td class="right">{{ $item->quantity }}</td>
                        <td class="right">Rp {{ number_format((int) $item->unit_price_amount, 0, ',', '.') }}</td>
                        <td class="right">Rp {{ number_format((int) $item->unit_price_amount * (int) $item->quantity, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td class="muted">Subtotal Produk</td>
                <td class="right">Rp {{ number_format($itemsSubtotal, 0, ',', '.') }}</td>
            </tr>
            @if ($discountAmount > 0)
                <tr>
                    <td class="muted">Diskon Promo</td>
                    <td class="right">-Rp {{ number_format($discountAmount, 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr>
                <td class="muted">Ongkos Kirim</td>
                <td class="right">Rp {{ number_format($shippingAmount, 0, ',', '.') }}</td>
            </tr>
            @if ($isPkp)
                <tr>
                    <td class="muted">DPP (Dasar Pengenaan Pajak)</td>
                    <td class="right">Rp {{ number_format($dpp, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="muted">PPN 11% (Inklusif)</td>
                    <td class="right">Rp {{ number_format($ppn, 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>Total Tagihan</td>
                <td class="right">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
            </tr>
            @if ($refundedTotal > 0)
                <tr>
                    <td class="muted">Total Refund Disetujui</td>
                    <td class="right">-Rp {{ number_format($refundedTotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td><strong>Net Dibayar</strong></td>
                    <td class="right"><strong>Rp {{ number_format(max(0, $grandTotal - $refundedTotal), 0, ',', '.') }}</strong></td>
                </tr>
            @endif
        </table>
    </div>
</body>
</html>
