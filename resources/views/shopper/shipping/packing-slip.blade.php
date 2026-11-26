<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packing Slip - {{ $order->number }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #111827; font-size: 13px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom: 12px;">
    <button onclick="window.print()" style="padding: 8px 14px; background:#111827; color:#fff; border:none; border-radius:4px; cursor:pointer;">Cetak Packing Slip</button>
</div>
<div class="header">
    <div>
        <h2 style="margin:0;">{{ shopper_setting('name') ?: config('app.name', 'OceanMall') }}</h2>
        <div>PACKING SLIP PENGEMASAN</div>
    </div>
    <div style="text-align:right;">
        <div><strong>Order:</strong> #{{ $order->number }}</div>
        <div><strong>Tanggal:</strong> {{ $order->created_at?->format('d M Y H:i') }}</div>
    </div>
</div>
<p><strong>Penerima:</strong> {{ $order->shippingAddress?->full_name ?: ($order->customer?->full_name ?? 'Pelanggan') }} — {{ $order->shippingAddress?->street_address }}, {{ $order->shippingAddress?->city }} {{ $order->shippingAddress?->postal_code }} ({{ $order->shippingAddress?->phone_number ?? '-' }})</p>
<table>
    <thead>
        <tr>
            <th>Item</th>
            <th>SKU</th>
            <th style="text-align:center;">Qty</th>
            <th>Check</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->name }}</td>
                <td>{{ $item->sku ?: '-' }}</td>
                <td style="text-align:center; font-weight:bold;">{{ $item->quantity }}</td>
                <td style="width:60px;">[  ]</td>
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
