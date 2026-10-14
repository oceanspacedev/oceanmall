@extends('legal.layout')

@section('title', 'Hapus akun')

@section('content')
    <h1>Hapus akun {{ config('app.name') }}</h1>
    <p class="muted">Kamu bisa menghapus akunmu sendiri, kapan saja, tanpa perlu menghubungi kami.</p>

    <h2>Di aplikasi {{ config('app.name') }} (iOS dan Android)</h2>
    <ol>
        <li>Buka tab <strong>Akun</strong> dan masuk ke akunmu.</li>
        <li>Pilih <strong>Profil &amp; keamanan</strong>, lalu <strong>Hapus akun</strong>.</li>
        <li>Masukkan kata sandimu untuk mengonfirmasi, lalu pilih <strong>Hapus akun</strong>.</li>
    </ol>

    <h2>Di situs web</h2>
    <ol>
        <li>Masuk ke akunmu di <a href="{{ url('/') }}">{{ url('/') }}</a>.</li>
        <li>Buka halaman <a href="{{ url('/settings/profile') }}">Profil</a>, lalu pilih <strong>Hapus akun</strong>.</li>
        <li>Masukkan kata sandimu untuk mengonfirmasi.</li>
    </ol>

    <h2>Yang dihapus</h2>
    <p>Penghapusan langsung dan tidak bisa dibatalkan. Yang ikut dihapus: akun dan datamu (nama, email, nomor HP), alamat tersimpan, notifikasi, ulasan produk yang pernah kamu tulis, keranjang, dan semua sesi masuk di semua perangkatmu.</p>

    <h2>Yang tetap disimpan</h2>
    <p>Riwayat pesanan tidak ikut dihapus. Nama penerima, nomor HP, alamat pengiriman, dan nilai pesanan tetap kami simpan sebagai catatan transaksi, tetapi tidak lagi terhubung ke akunmu. Catatan itu diperlukan untuk pesanan yang masih berjalan, urusan pajak, dan penyelesaian sengketa. Rincian ada di <a href="{{ route('legal.show', 'kebijakan-privasi') }}">Kebijakan Privasi</a>.</p>

    @if ($email || $whatsapp)
        <h2>Tidak bisa masuk ke akunmu?</h2>
        <p>Hubungi kami dan sebutkan email akunmu:</p>
        <ul>
            @if ($email)
                <li>Email: <a href="mailto:{{ $email }}">{{ $email }}</a></li>
            @endif
            @if ($whatsapp)
                <li>WhatsApp: <a href="https://wa.me/{{ $whatsapp }}">+{{ $whatsapp }}</a></li>
            @endif
        </ul>
    @endif
@endsection
