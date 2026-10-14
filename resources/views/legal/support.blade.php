@extends('legal.layout')

@section('title', 'Bantuan')

@section('content')
    @if ($draft)
        <p class="draft">Draf: halaman ini hanya tampil di lingkungan pengembangan sampai teks hukum disetujui.</p>
    @endif

    <h1>Bantuan {{ config('app.name') }}</h1>
    <p class="muted">Ada pertanyaan soal pesanan, pembayaran, pengiriman, atau akunmu? Hubungi kami.</p>

    <h2>Hubungi kami</h2>
    <ul>
        @if ($whatsapp)
            <li>WhatsApp: <a href="https://wa.me/{{ $whatsapp }}">+{{ $whatsapp }}</a></li>
        @endif
        @if ($email)
            <li>Email: <a href="mailto:{{ $email }}">{{ $email }}</a></li>
        @endif
    </ul>
    <p>Sebutkan nomor pesananmu kalau menanyakan pesanan, supaya kami bisa menelusurinya.</p>

    <h2>Pertanyaan umum</h2>
    <p><strong>Bagaimana cara membayar pesanan?</strong>
Pilih virtual account bank atau QRIS saat checkout. Nomor virtual account atau kode QR muncul di detail pesanan. Bayar sebelum batas waktu yang tertera; pesanan yang belum dibayar sampai batas itu dibatalkan otomatis.</p>
    <p><strong>Bagaimana cara kerja pengiriman?</strong>
Kamu memilih kurir dan layanan saat checkout. Setelah pesanan dikemas, nomor resi muncul di detail pesanan dan kamu bisa melacak paketnya dari sana.</p>
    <p><strong>Lupa kata sandi?</strong>
Di layar Masuk, ketuk Lupa kata sandi, isi emailmu, lalu buka tautan yang kami kirim.</p>
    <p><strong>Ingin menghapus akun?</strong>
Lihat <a href="{{ route('legal.account-deletion') }}">cara menghapus akun</a>.</p>
@endsection
