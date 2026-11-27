<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gagal Mencetak Label Pengiriman{{ $order ? ' - #'.$order->number : '' }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 24px;
            background: #f8fafc;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .card {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
            box-sizing: border-box;
        }
        .badge {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            color: #b91c1c;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 9999px;
            padding: 4px 10px;
            margin-bottom: 14px;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }
        .order-ref {
            margin: 0 0 14px;
            font-size: 13px;
            color: #475569;
        }
        .alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 14px;
            font-size: 14px;
            line-height: 1.5;
            color: #7f1d1d;
            margin-bottom: 20px;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .btn-primary {
            background: #0f172a;
            color: #ffffff;
        }
        .btn-primary:hover {
            background: #1e293b;
        }
        .btn-secondary {
            background: #ffffff;
            color: #334155;
            border-color: #cbd5e1;
        }
        .btn-secondary:hover {
            background: #f1f5f9;
        }
    </style>
</head>
<body>
    <main class="card" role="alert">
        <span class="badge">Label Pengiriman Tidak Tersedia</span>
        <h1>Gagal Mencetak Stiker Resi</h1>
        @if ($order)
            <p class="order-ref">Pesanan <strong>#{{ $order->number }}</strong></p>
        @endif

        <div class="alert">
            {{ $message }}
        </div>

        <div class="actions">
            <a href="{{ $orderUrl }}" class="btn btn-primary">{{ $order ? 'Kembali ke Detail Pesanan' : 'Kembali ke Daftar Pesanan' }}</a>
            <button type="button" class="btn btn-secondary" onclick="window.close()">Tutup Tab</button>
        </div>
    </main>
</body>
</html>
