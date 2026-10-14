<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; --navy: #0a2a6b; --text: #1b1f27; --muted: #5b6472; --line: #e3e6eb; --warn-bg: #fff4e0; --warn: #8a5300; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--text); font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        header { background: var(--navy); color: #fff; padding: 16px; }
        header a { color: #fff; font-weight: 800; font-size: 18px; text-decoration: none; }
        main { max-width: 720px; margin: 0 auto; padding: 24px 16px 56px; }
        h1 { font-size: 26px; line-height: 1.25; margin: 0 0 4px; }
        h2 { font-size: 18px; margin: 32px 0 4px; }
        p { margin: 8px 0; white-space: pre-line; }
        .muted { color: var(--muted); font-size: 14px; }
        .draft { background: var(--warn-bg); color: var(--warn); padding: 10px 14px; border-radius: 6px; margin: 0 0 20px; font-size: 14px; }
        ol, ul { padding-left: 22px; }
        li { margin: 6px 0; }
        a { color: var(--navy); }
        footer { border-top: 1px solid var(--line); padding: 16px; text-align: center; color: var(--muted); font-size: 13px; }
        footer a { margin: 0 8px; }
    </style>
</head>
<body>
    <header><a href="{{ url('/') }}">{{ config('app.name') }}</a></header>
    <main>
        @yield('content')
    </main>
    <footer>
        <a href="{{ route('legal.show', 'kebijakan-privasi') }}">Kebijakan Privasi</a>
        <a href="{{ route('legal.show', 'syarat-ketentuan') }}">Syarat &amp; Ketentuan</a>
        <a href="{{ route('legal.support') }}">Bantuan</a>
        <a href="{{ route('legal.account-deletion') }}">Hapus akun</a>
    </footer>
</body>
</html>
