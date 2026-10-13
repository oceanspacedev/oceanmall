# Panduan QA — OceanMall Mobile

Panduan untuk menguji aplikasi mobile (Expo) bersama backend Laravel di mesin lokal dengan kredensial **sandbox** Komerce.

## 1. Menjalankan backend

Dari root repo (`oceanmall/`), jalankan tiga proses ini, masing-masing di terminal sendiri:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

```bash
php artisan queue:listen --tries=1 --timeout=120
```

```bash
php artisan schedule:work
```

- `serve` dengan `--host=0.0.0.0` wajib kalau menguji dari HP fisik.
- `queue:listen` wajib. Tanpanya, order yang sudah dibayar tidak akan pernah dikirim ke kurir.
- `schedule:work` opsional. Tugasnya membatalkan order yang belum dibayar sampai kedaluwarsa, sinkron kurir, dan sejenisnya.

`APP_URL` di `.env` harus `http://localhost:8000`, termasuk port-nya.

## 2. Menjalankan aplikasi

```bash
cd mobile
npm install
npx expo run:ios
```

- **Build pertama** (`expo run:ios` atau `expo run:android`) butuh beberapa menit. Setelah itu cukup `npx expo start -c` lalu buka app OceanMall yang sudah terpasang di simulator.
- **Android emulator:** jalankan `npx expo run:android`. API otomatis diarahkan ke `10.0.2.2:8000`.
- **HP fisik:** isi `EXPO_PUBLIC_API_URL=http://<IP-LAN-laptop>:8000/api/v1` di `mobile/.env`. IP laptop bisa dilihat dengan `ipconfig getifaddr en0`. Setelah itu restart Metro dengan `-c`.
- **iOS + CocoaPods:** kalau `pod install` gagal dengan _Unicode Normalization_, jalankan dulu `export LANG=en_US.UTF-8`.
- **File baru tidak terbaca ("Unable to resolve module"):** Metro tanpa watchman kadang melewatkan file baru. Pasang watchman (`brew install watchman`), atau restart Metro dengan `npx expo start -c`.

## 3. Akun uji

| Peran                    | Email                     | Kata sandi    |
| ------------------------ | ------------------------- | ------------- |
| Customer                 | `customer@oceanmall.test` | `password123` |
| Admin cpanel (`/cpanel`) | `admin@oceanmall.test`    | `password123` |

Akun-akun ini dibuat oleh `StoreConfigSeeder`. Untuk skenario daftar, pakai email baru di domain `@example.com`.

Satu akun hanya bisa login di **satu perangkat**. Login di perangkat lain otomatis mengeluarkan perangkat pertama, dan aplikasi akan kembali ke layar Masuk. Itu perilaku yang disengaja.

## 4. Catatan sandbox

- **Pembayaran:** halaman bayar sandbox Komerce tidak bisa diselesaikan, dan webhook sandbox tidak bisa menjangkau laptop. Untuk menandai order sudah dibayar:
  ```bash
  php artisan komerce:simulate-payment ORD-20261013-000006
  ```
  Setelah itu tekan **Cek status bayar** di aplikasi, atau tarik layar order ke bawah. Perintah ini hanya jalan di `APP_ENV=local` dengan payment host sandbox.
- **Kurir:** pilih **J&T Express · EZ** untuk menguji alur pengiriman. Di sandbox, pickup JNE selalu `failed` (perilaku Komerce, bukan bug aplikasi).
- **Status pengiriman:** buka order di `/cpanel` → tab pengiriman, lalu pakai tombol simulasi (_Generate AWB_, _Scan pickup_, _Scan delivered_). Tombol ini hanya muncul di lokal.
- **Kuota pencarian kecamatan:** pencarian memakai RajaOngkir _production_ dengan kuota sekitar 100 request/hari. Hasilnya di-cache 30 hari, jadi ulangi kata kunci yang sama (mis. `kebayoran baru`, `gambir`) dan hindari mencoba ratusan kata kunci berbeda.

## 5. Skenario uji

Tandai ✅/❌ dan catat nomor order, screenshot, serta jam kejadian kalau gagal.

### Katalog

- [ ] Beranda menampilkan kategori, banner, merek, dan "Promo hari ini" lengkap dengan **gambar**.
- [ ] Promo juga memuat HP bervarian (mis. Realme 13 Pro 5G).
- [ ] Cari "realme" menampilkan hasil. Mengetik cepat tidak memunculkan hasil kata kunci lama.
- [ ] Daftar kategori, koleksi, "Lihat semua", dan pencarian dengan lebih dari 20 produk menampilkan tombol **Muat lebih banyak**.
- [ ] Detail produk bervarian: harga coret mengikuti varian yang dipilih, dan tombol + berhenti di stok varian.
- [ ] Produk dengan stok ≤ 5 menampilkan "Sisa N lagi". Stok 0 menampilkan "Stok habis" dan tombol Keranjang/Beli nonaktif.

### Keranjang

- [ ] **Keranjang** menampilkan pesan "N barang ditambahkan ke keranjang · Lihat keranjang", dan badge bertambah.
- [ ] Total satu produk dalam keranjang maksimal 10. Lebih dari itu muncul pesan "Maksimal 10 per produk".
- [ ] **Beli** dengan qty 2 membuat produk itu tepat ×2 di checkout, tidak dijumlahkan dengan isi keranjang sebelumnya.
- [ ] Isi keranjang sebagai tamu, lalu masuk. Isi keranjang ikut pindah ke akun.

### Akun

- [ ] Daftar akun baru dari tombol Beli langsung lanjut ke Checkout.
- [ ] Setelah masuk atau daftar, tombol Kembali tidak kembali ke layar Masuk/Daftar.
- [ ] Masuk dengan email huruf besar-kecil campur (mis. `Customer@OceanMall.test`) berhasil.
- [ ] Mendaftar dengan email yang sama tapi beda huruf besar-kecil ditolak.
- [ ] Lupa kata sandi menampilkan pesan sukses. Link di email (lihat `storage/logs/laravel.log` karena `MAIL_MAILER=log`) mengarah ke `http://localhost:8000/reset-password/...`.
- [ ] Ganti kata sandi di Profil membuat perangkat lain ikut keluar.
- [ ] Alamat: tambah, ubah, hapus, dan jadikan default.

### Checkout & pembayaran

- [ ] Nama dan HP terisi otomatis dari profil.
- [ ] Hasil pencarian kecamatan tidak tertutup keyboard, dan keyboard menutup setelah kecamatan dipilih.
- [ ] **Simpan alamat & hitung ongkir** menampilkan kurir urut dari yang termurah, dan keranjang **tetap berisi**.
- [ ] Memilih kurir mana pun berhasil dan total berubah menjadi "Termasuk ongkir ...".
- [ ] Kalau terjadi error, pesannya muncul di atas tombol **Buat pesanan**.
- [ ] Metode bayar yang di luar batas nominal tampil abu-abu dengan keterangan minimal/maksimal.
- [ ] Buat pesanan dengan VA BCA, lalu buka halaman order. Nomor VA tampil dan tanggal berlaku mudah dibaca.
- [ ] Tombol **Buat pembayaran baru** hanya muncul kalau pembayaran sudah kedaluwarsa.
- [ ] Jalankan `komerce:simulate-payment`, lalu tekan **Cek status bayar**. Status menjadi Dibayar · Diproses.
- [ ] Dengan kurir J&T EZ, simulasikan AWB, pickup, lalu delivered di cpanel. Di aplikasi, status pengiriman dan nomor AWB ikut berubah, **Lacak** bisa ditekan, dan tombol "Pesanan diterima" muncul setelah ada AWB.

### Lain-lain

- [ ] Notifikasi muncul untuk pesanan baru dan pesanan dibayar. "Tandai semua dibaca" berfungsi.
- [ ] Mode pesawat menampilkan pesan offline. Saat online lagi, data dimuat ulang.

## 6. Batasan yang diketahui

- Keranjang web storefront dan aplikasi belum disatukan. Web membuat keranjang per sesi, sedangkan aplikasi memakai keranjang terbaru yang berisi barang.
- Ongkir dihitung ulang setiap kali halaman Checkout dibuka. Hasil Delivery di-cache 10 menit, hasil Cost 12 jam.
- Label AWB dan tracking kurir sungguhan tidak tersedia di sandbox. Pakai tombol simulasi di cpanel.
- Setelah dibayar, status order langsung menjadi **Dikirim**. Pengiriman sudah didaftarkan ke Komerce, tetapi belum ada AWB atau pickup. Backend menghitung status `labeled` sebagai "shipped", dan cpanel memakai aturan yang sama. Status per pengiriman di bagian **Pengiriman** lebih akurat (mis. "Menunggu pickup"). Perlu keputusan tim apakah aturan ini diubah.

## 7. Reset data lokal

Backup database dibuat di `storage/app/backup-database-*.sqlite`. Untuk kembali ke keadaan itu, hentikan server lalu salin file backup menjadi `database/database.sqlite`.
