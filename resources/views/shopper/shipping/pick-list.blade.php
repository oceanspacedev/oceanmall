<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Gudang</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #111827; font-size: 13px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #4b5563; font-size: 12px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="no-print" style="margin-bottom: 12px;">
    <button onclick="window.print()" style="padding: 8px 14px; background:#111827; color:#fff; border:none; border-radius:4px; cursor:pointer;">Cetak Pick List</button>
</div>
<h1>Pick List Pengambilan Barang Gudang</h1>
<div class="meta">Dicetak: {{ $generatedAt->format('d M Y H:i') }} · Total Pesanan: {{ $orders->count() }}</div>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>SKU</th>
            <th>Nama Produk</th>
            <th style="text-align:center;">Total Qty</th>
            <th>Nomor Pesanan</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($items as $idx => $item)
            <tr>
                <td>{{ $idx + 1 }}</td>
                <td>{{ $item['sku'] }}</td>
                <td>{{ $item['name'] }}</td>
                <td style="text-align:center; font-weight:bold;">{{ $item['qty'] }}</td>
                <td>{{ implode(', ', array_unique($item['orders'])) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;">Tidak ada item dipilih.</td></tr>
        @endforelse
    </tbody>
</table>
</body>
</html>
