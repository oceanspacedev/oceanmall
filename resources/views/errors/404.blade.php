<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Halaman Tidak Ditemukan — {{ config('app.name', 'OceanMall') }}</title>
        <style>
            :root {
                color-scheme: light;
            }
            * {
                box-sizing: border-box;
            }
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                background-color: #f8fafc;
                color: #0f172a;
            }
            header {
                background-color: #0a2a6b;
                color: #ffffff;
                padding: 1rem 1.5rem;
            }
            .header-inner {
                max-width: 80rem;
                margin: 0 auto;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
            }
            .brand {
                font-size: 1.25rem;
                font-weight: 800;
                letter-spacing: -0.025em;
                color: #ffffff;
                text-decoration: none;
            }
            .brand span {
                color: #38bdf8;
            }
            .header-link {
                font-size: 0.875rem;
                font-weight: 600;
                color: #e2e8f0;
                text-decoration: none;
            }
            main {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 3rem 1.5rem;
            }
            .card {
                width: 100%;
                max-width: 30rem;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 1rem;
                padding: 2.25rem 1.75rem;
                text-align: center;
                box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
            }
            .code {
                display: inline-block;
                padding: 0.25rem 0.75rem;
                border-radius: 9999px;
                background: #eff6ff;
                color: #0a2a6b;
                font-size: 0.75rem;
                font-weight: 700;
                letter-spacing: 0.05em;
            }
            h1 {
                margin: 1rem 0 0.5rem;
                font-size: 1.375rem;
                font-weight: 700;
                color: #0f172a;
            }
            p {
                margin: 0;
                font-size: 0.875rem;
                line-height: 1.5;
                color: #475569;
            }
            .actions {
                margin-top: 1.75rem;
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.75rem;
            }
            .btn-primary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0.625rem 1.25rem;
                border-radius: 0.5rem;
                background-color: #0a2a6b;
                color: #ffffff;
                font-size: 0.875rem;
                font-weight: 600;
                text-decoration: none;
            }
            .btn-secondary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0.625rem 1.25rem;
                border-radius: 0.5rem;
                border: 1px solid #cbd5e1;
                background-color: #ffffff;
                color: #0f172a;
                font-size: 0.875rem;
                font-weight: 600;
                text-decoration: none;
            }
        </style>
    </head>
    <body>
        <header>
            <div class="header-inner">
                <a href="{{ url('/') }}" class="brand"><span>OCEAN</span>MALL</a>
                <a href="{{ url('/shop') }}" class="header-link">Katalog Produk</a>
            </div>
        </header>
        <main>
            <section class="card" aria-labelledby="not-found-title">
                <span class="code">404</span>
                <h1 id="not-found-title">Halaman atau produk tidak ditemukan</h1>
                <p>Tautan yang kamu buka mungkin sudah dipindahkan, produk tidak tersedia, atau alamat halaman keliru.</p>
                <div class="actions">
                    <a href="{{ url('/') }}" class="btn-primary">Kembali ke Beranda</a>
                    <a href="{{ url('/shop') }}" class="btn-secondary">Lihat Semua Produk</a>
                </div>
            </section>
        </main>
    </body>
</html>
