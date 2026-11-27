<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Label Pengiriman Massal</title>
    <style>
        @page { size: 100mm 150mm; margin: 0; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 8mm; background: #fff; color: #000; font-size: 11px; }
        .label-container { border: 2px solid #000; padding: 8px; max-width: 100mm; margin: 0 auto 16px; page-break-after: always; box-sizing: border-box; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 6px; }
        .barcode-section { text-align: center; border-bottom: 2px solid #000; padding: 8px 0; margin-bottom: 8px; }
        .address-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 10px; }
        th, td { border: 1px solid #000; padding: 3px 5px; text-align: left; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="no-print" style="text-align:center; margin-bottom:12px;">
    <button onclick="window.print()" style="padding:8px 16px; background:#000; color:#fff; border:none; border-radius:4px; cursor:pointer;">Cetak Semua Label ({{ count($labels) }})</button>
</div>
@foreach ($labels as $entry)
    @php($order = $entry['order'])
    @php($shipment = $entry['shipment'])
    @php($barcodeValue = trim((string) ($shipment['awb'] ?: $shipment['tracking_number'] ?: '')))
    @continue($barcodeValue === '')
    <div class="label-container">
        <div class="header">
            <div style="font-size:16px; font-weight:900;">{{ $shipment['carrier'] }}</div>
            <div style="font-size:13px; font-weight:900; border:2px solid #000; padding:2px 6px;">{{ $shipment['service'] }}</div>
        </div>
        <div class="barcode-section">
            <div style="max-width:82mm; margin:0 auto 4px;">{!! \App\Support\Code128Svg::render($barcodeValue, 42) !!}</div>
            <div style="font-family:monospace; font-size:15px; font-weight:800;">{{ $barcodeValue }}</div>
        </div>
        @if (($shipment['cod_amount'] ?? 0) > 0)
            <div style="border:2px solid #000; padding:4px; font-weight:900; text-align:center; margin-bottom:6px;">COD: {{ shopper_money_format((int) $shipment['cod_amount'], (string) $order->currency_code) }}</div>
        @endif
        <div class="address-grid">
            <div style="border-right:1px solid #000; padding-right:6px;">
                <strong>PENGIRIM:</strong>
                <div><strong>{{ $shipment['shipper_name'] }}</strong></div>
                <div>{{ $shipment['shipper_address'] }}</div>
            </div>
            <div>
                <strong>PENERIMA:</strong>
                <div><strong>{{ $shipment['receiver_name'] }}</strong></div>
                <div>{{ $shipment['receiver_address'] }} ({{ $shipment['receiver_phone'] }})</div>
            </div>
        </div>
        <table>
            <thead><tr><th>Produk</th><th style="width:36px; text-align:center;">Qty</th></tr></thead>
            <tbody>
                @foreach ($shipment['lines'] as $line)
                    <tr><td>{{ $line['name'] }}</td><td style="text-align:center; font-weight:bold;">{{ $line['qty'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
</body>
</html>
