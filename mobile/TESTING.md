# Panduan QA — OceanMall Mobile

Panduan untuk menguji aplikasi mobile (Expo) bersama backend Laravel di mesin lokal dengan kredensial **sandbox** Komerce.

Dokumen ini untuk uji fungsional harian (build Debug + Metro). Uji khusus rilis (build Release, teks besar, pembaca layar, deep link dari kondisi mati, sinyal toko) ada di [store/README.md](store/README.md#checklist-qa-rilis).

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
cd ios && export LANG=en_US.UTF-8 && pod install && cd ..
npx expo run:ios
```

- **Build pertama** (`expo run:ios` atau `expo run:android`) butuh beberapa menit. Setelah itu cukup `npx expo start -c` lalu buka app OceanMall yang sudah terpasang di simulator.
- **Android emulator:** jalankan `npx expo run:android`. API otomatis diarahkan ke `10.0.2.2:8000`.
- **HP fisik:** isi `EXPO_PUBLIC_API_URL=http://<IP-LAN-laptop>:8000/api/v1` di `mobile/.env`. IP laptop bisa dilihat dengan `ipconfig getifaddr en0`. Setelah itu restart Metro dengan `-c`.
- **iOS + CocoaPods:** kalau `pod install` gagal dengan _Unicode Normalization_, jalankan dulu `export LANG=en_US.UTF-8`. Jalankan `pod install` lagi setiap ada dependensi native baru, lalu bangun ulang app (`npx expo run:ios`). Contohnya `expo-web-browser` (sheet pembayaran dalam aplikasi): app lama yang dipasang sebelum modul itu ada tetap jalan, tapi "Buka halaman bayar" jatuh ke browser sistem.
- **File atau rute baru tidak terbaca ("Unable to resolve module", rute tidak dikenali):** Metro tanpa watchman kadang melewatkan file baru. Pasang watchman (`brew install watchman`), atau restart Metro dengan `npx expo start -c` (ini juga membuat ulang `.expo/types` untuk typed routes).
- **Kontak bantuan:** baris "Hubungi kami" dan "Butuh bantuan?" hanya muncul kalau `EXPO_PUBLIC_SUPPORT_WHATSAPP` dan/atau `EXPO_PUBLIC_SUPPORT_EMAIL` terisi di `mobile/.env` (angka internasional tanpa `+`, mis. `62812...`). Isi salah satunya untuk menguji baris itu. Di build **rilis** kontak ini wajib (diisi di `.env.production`, dan build gagal tanpanya). Variabel lain: [README.md](README.md#konfigurasi-expo_public_).
- **Sebelum membuka PR:** `npm run check` (typecheck, format, dan pemeriksa warna) harus lulus.
- **Aplikasi selalu tampil terang**, apa pun pengaturan HP. Cek sekali dengan HP atau simulator yang disetel ke mode gelap.

## 3. Akun uji

| Peran                    | Email                     | Kata sandi    |
| ------------------------ | ------------------------- | ------------- |
| Customer                 | `customer@oceanmall.test` | `password123` |
| Admin cpanel (`/cpanel`) | `admin@oceanmall.test`    | `password123` |

Akun-akun ini dibuat oleh `StoreConfigSeeder`. Untuk skenario daftar, pakai email baru di domain `@example.com`.

Satu akun hanya bisa login di **satu perangkat** (`AuthController::login` menghapus token `mobile` perangkat lain). Login di perangkat kedua otomatis mengeluarkan perangkat pertama: Akun di perangkat pertama kembali ke tampilan tamu, dan layar yang butuh akun (pesanan, notifikasi, checkout) menampilkan ajakan **Masuk**. **Mengubah kata sandi** juga mengeluarkan semua perangkat lain. Itu perilaku yang disengaja. Konsekuensinya, satu akun uji bersama tidak bisa dipakai dua penguji sekaligus: pakai akun berbeda per perangkat.

**Aturan kata sandi di aplikasi mengikuti produksi** (minimal 12 karakter, huruf besar dan kecil, angka, simbol) saat daftar dan ganti kata sandi, walaupun backend lokal menerima lebih pendek. Pakai kata sandi seperti `Oceanmall#2026x` untuk akun baru. Form **Masuk** tidak memeriksa aturan itu, jadi `password123` tetap bisa dipakai untuk login.

**Mengulang pengenalan (layar sambutan pertama):** pengenalan hanya tampil di pemasangan baru. Untuk melihatnya lagi, hapus aplikasi lalu pasang ulang (`xcrun simctl uninstall booted id.oceanmall.app` atau `adb uninstall id.oceanmall.app`; di HP fisik, hapus lalu pasang). Pemasangan ulang juga mengeluarkan akun dan mengosongkan token keranjang tamu, itu disengaja.

## 4. Catatan sandbox

- **Pembayaran:** halaman bayar sandbox Komerce tidak bisa diselesaikan, dan webhook sandbox tidak bisa menjangkau laptop. Untuk menandai order sudah dibayar:
  ```bash
  php artisan komerce:simulate-payment ORD-20261013-000006
  ```
  Setelah itu status di aplikasi berubah sendiri dalam sekitar 8 detik, selama layar pesanan terbuka dan pesanan belum dibayar. Aplikasi juga memeriksa begitu kamu kembali ke aplikasi dari aplikasi lain. Kalau mau langsung, tekan **Cek status pembayaran** atau tarik layar order ke bawah. Perintah ini hanya jalan di `APP_ENV=local` dengan payment host sandbox.
- **Kurir:** pilih **J&T Express · EZ** untuk menguji alur pengiriman. Di sandbox, pickup JNE selalu `failed` (perilaku Komerce, bukan bug aplikasi).
- **Status pengiriman:** buka order di `/cpanel` → tab pengiriman, lalu pakai tombol simulasi (_Generate AWB_, _Scan pickup_, _Scan delivered_). Tombol ini hanya muncul di lokal.
- **Kuota pencarian kecamatan:** pencarian memakai RajaOngkir _production_ dengan kuota sekitar 100 request/hari. Hasilnya di-cache 30 hari, jadi ulangi kata kunci yang sama (mis. `kebayoran baru`, `gambir`) dan hindari mencoba ratusan kata kunci berbeda.
- **Cara bayar per bank:** langkah di akordeon "Cara bayar" (BCA, BRI, Mandiri, BNI, Permata, QRIS) ditulis dari panduan umum, bukan dari Komerce. Langkah Mandiri sengaja umum. Cocokkan dengan panduan resmi bank sebelum rilis (`lib/payment-guides.ts`).

## 5. Skenario uji

Tandai ✅/❌ dan catat nomor order, screenshot, serta jam kejadian kalau gagal. Semua teks yang tampil harus berbahasa Indonesia. Kalau melihat bahasa Inggris, "Server Error", atau "HTTP 404" di layar, catat sebagai bug.

### Pertama kali dibuka

- [ ] Pemasangan baru: splash biru navy, lalu pengenalan tiga layar ("Belanja Lebih Luas, Pilihan Tanpa Batas", "Ongkir Jelas, Paket Terlacak", "Bayar Mudah dan Aman") dengan titik indikator. Geser atau tekan **Lanjut**. Tombol berubah menjadi **Mulai Belanja** di layar terakhir. Di Android, tombol Kembali memundurkan satu layar.
- [ ] **Lewati** (kanan atas) dan **Mulai Belanja** sama-sama membuka Beranda. **Sudah punya akun? Masuk** membuka layar Masuk.
- [ ] Tutup paksa lalu buka lagi: pengenalan tidak muncul lagi.
- [ ] Buka app dari tautan saat belum pernah dibuka (`xcrun simctl openurl booted oceanmall://product/<slug>` pada pemasangan baru): pengenalan dilewati dan produk langsung tampil. Back mendarat di Beranda.
- [ ] Matikan backend lalu buka app: strip kuning "Tidak ada koneksi internet" muncul dan app tetap terbuka sebagai tamu. Kalau ada sesi tersimpan, app menunggu profilnya paling lama 2,5 detik, lalu terbuka dengan data tamu dan profil menyusul saat jaringan pulih. Kalau start lebih dari sekitar 1 detik, layar navy "Menyiapkan OceanMall…" muncul, bukan layar putih kosong.
- [ ] Pada HP atau simulator mode gelap, seluruh layar (termasuk keyboard, alert, dan status bar) tetap terang dan terbaca.

### Beranda

- [ ] Header 56 px selalu ada (logo, kotak cari, lonceng, keranjang), juga saat memuat dan saat error. Tidak ada loncatan tinggi saat data masuk.
- [ ] Teks di kotak cari berganti tiap ±3 detik ("Cari di OceanMall", "Cari <nama kategori>"). Menekan kotak membuka tab **Cari** dengan keyboard terbuka.
- [ ] Lonceng menampilkan jumlah notifikasi belum dibaca untuk user yang masuk. Sebagai tamu, lonceng membuka Masuk, dan setelah masuk mendarat di Notifikasi. Ikon keranjang menampilkan jumlah barang, dan lencananya berdenyut kecil saat jumlah bertambah.
- [ ] Banner koleksi bergeser sendiri tiap 4 detik dan berulang. Menyentuh atau menggeser menjeda gerakan beberapa detik. Dengan Reduce Motion atau pembaca layar menyala, banner tidak bergerak sendiri. Titik indikator ada di atas gambar.
- [ ] Kategori tampil sebagai kisi 4 kolom dengan ikon. Lebih dari 8 kategori menjadikan ubin terakhir **Lainnya**.
- [ ] **Promo hari ini** berupa baris geser horizontal dengan lencana "Hemat s.d. N%". Promo juga memuat HP bervarian (mis. Realme 13 Pro 5G). **Lihat semua** membuka daftar promo.
- [ ] **Brand pilihan** dan **Rekomendasi untukmu** (satu-satunya kisi 2 kolom di Beranda, **Lihat semua** membuka daftar lengkap). Semua kartu produk punya **gambar**.
- [ ] Sebagai tamu, strip "Masuk untuk lacak pesanan & checkout lebih cepat" ada di atas, bisa ditutup dengan X, dan tidak muncul lagi setelah itu (juga setelah app dibuka ulang).
- [ ] Tarik ke bawah memuat ulang dengan spinner navy. Katalog kosong menampilkan "Katalog sedang disiapkan" dengan **Muat ulang**. Server mati menampilkan "Yah, ada yang salah" dengan **Coba lagi**.

### Cari dan daftar produk

- [ ] Tab **Cari**: kalau kolom kosong, keyboard langsung terbuka. Pencarian terakhir tampil sebagai pil (maksimal 8), ada X per pil dan **Hapus** untuk semua.
- [ ] Mengetik satu huruf menampilkan "Ketik minimal 2 huruf untuk mencari". Cari "realme" menampilkan hasil. Mengetik cepat tidak memunculkan hasil kata kunci lama.
- [ ] Saat mengetik, hasil sebelumnya tetap tampil agak redup dengan baris "Mencari “…”", tidak diganti spinner penuh.
- [ ] Kata kunci tanpa hasil menampilkan "Produk tidak ditemukan" dan pilihan kategori ("Atau jelajahi kategori"). Label di atas hasil berbunyi "N produk untuk “kata kunci”".
- [ ] Urutan **Terbaru**, **Termurah**, **Termahal** bekerja di pencarian, kategori, koleksi, promo, rekomendasi, dan **Semua produk**.
- [ ] Kategori, koleksi, merek, "Lihat semua", dan pencarian dengan lebih dari 20 produk memuat halaman berikutnya **otomatis saat menggulir ke bawah**. Tidak ada tombol "Muat lebih banyak". Di ujung daftar tampil "Kamu sudah melihat semua produk".
- [ ] Putuskan jaringan di tengah menggulir: daftar tetap ada dan di bawahnya muncul "Gagal memuat produk berikutnya" dengan **Coba lagi**. Layar tidak diganti pesan error penuh.
- [ ] Kartu produk: gambar persegi, tinggi kartu dalam satu baris sama walau judulnya berbeda, ada umpan balik saat ditekan. Baris kecil di bawah harga menampilkan nama merek (atau "OceanMall"). Rating dan "terjual" belum tampil karena API belum mengirimnya (lihat Batasan yang diketahui).

### Detail produk

- [ ] Detail produk bervarian: harga coret mengikuti varian yang dipilih, dan tombol + berhenti di stok varian. Varian yang habis tetap bisa ditekan dan memunculkan toast "<nama> sedang habis".
- [ ] Produk dengan stok ≤ 5 menampilkan "Tersisa N". Stok 0 menampilkan "Stok habis" dan kedua tombol bawah nonaktif.
- [ ] Bintang rating berwarna emas, tertulis "(N ulasan)", ada baris "OceanMall Resmi" dan "Merek <nama>" (atau "Merek OceanMall" kalau produk tanpa merek). "Lihat semua ulasan (N)" membuka sisa ulasan. Tanggal ulasan relatif (mis. "3 jam lalu", "Kemarin, 09:37", "14 Okt"), dan ada pil "Merekomendasikan".
- [ ] Ikon **Bagikan** di header membuka sheet berbagi berisi nama, harga, dan tautan `<WEB_URL>/shop/<slug>` tepat satu kali (di dev, `WEB_URL` diturunkan dari `EXPO_PUBLIC_API_URL`).
- [ ] **+ Keranjang** memunculkan toast "N barang ditambahkan ke keranjang" dengan tindakan **Lihat Keranjang**. Toast hilang sendiri setelah sekitar 4 detik, tinggi bar bawah tidak berubah, dan badge keranjang di header bertambah.
- [ ] Stepper berhenti di 10 atau di stok (mana yang lebih kecil) dan menampilkan "Maks. N per pesanan". Menambah lagi produk yang sudah ada 8 di keranjang sampai melebihi 10 menampilkan pesan server "Maksimal 10 per produk dalam keranjang." di atas tombol.
- [ ] **Beli sekarang** dengan qty 2 membuat produk itu tepat ×2 di checkout, tidak dijumlahkan dengan isi keranjang sebelumnya. Sebagai tamu, ia membuka layar Masuk berjudul "Masuk untuk lanjut checkout".
- [ ] Slug yang salah menampilkan "Produk tidak ditemukan" dengan **Kembali belanja**. Server mati menampilkan pesan dengan **Coba lagi**.

### Keranjang

- [ ] Ada dua pintu ke keranjang: tab **Keranjang** dan halaman penuh yang dibuka dari ikon keranjang di header atau toast **Lihat Keranjang**. Dari halaman penuh, Back kembali ke produk. Tab tetap seperti biasa.
- [ ] Bar checkout menempel di atas tab bar pada tab, dan di tepi bawah layar pada halaman penuh (tanpa celah dan tanpa tertutup).
- [ ] Qty lebih dari 1 menampilkan "Subtotal Rp…" per baris. Stepper dibaca "Jumlah <nama produk>".
- [ ] Hapus satu baris meminta konfirmasi "Hapus produk dari keranjang?", lalu toast "Produk dihapus dari keranjang". Ikon tempat sampah di header menanyakan "Kosongkan keranjang?", lalu toast "Keranjang dikosongkan".
- [ ] Kode promo yang valid memunculkan toast "Kode promo <KODE> terpasang, hemat Rp…" dan baris **Diskon promo**. Kode yang salah menampilkan pesan error di bawah kolom ("Kode promo tidak valid" bila server tidak memberi alasan). Kode bisa dilepas.
- [ ] Ongkos kirim tertulis "Dihitung saat checkout". Tombol bawah berbunyi "Masuk & checkout" untuk tamu dan "Checkout (N)" untuk user yang masuk.
- [ ] Keranjang kosong menampilkan "Wah, keranjangmu kosong". **Mulai belanja** membawa ke Beranda tanpa membuat tab bar kedua (di tab maupun di halaman penuh).
- [ ] Isi keranjang sebagai tamu, lalu masuk. Isi keranjang ikut pindah ke akun.

### Akun dan masuk

- [ ] Tab **Akun** sebagai tamu: kartu sambutan dengan logo, dua keunggulan, tombol **Masuk** dan **Daftar**, empat pintasan pesanan, kartu bantuan (Pusat bantuan, Hubungi kami bila kontak diisi, Syarat & ketentuan, Kebijakan privasi, Tentang OceanMall), dan footer berisi logo serta "Versi 1.0.0 (1)".
- [ ] Menekan pintasan pesanan (mis. **Dikirim**) sebagai tamu membuka Masuk. Setelah masuk, app mendarat di **Pesanan saya** pada tab itu (`/orders?status=<tab>`), bukan Akun.
- [ ] Judul layar Masuk menyesuaikan asal: "Masuk untuk lanjut checkout", "Masuk untuk melihat pesanan", "Masuk untuk melihat notifikasi", "Masuk untuk melihat profil", "Masuk untuk mengelola alamat", dan "Masuk ke akunmu" untuk yang lain. Layar **Daftar** berjudul "Buat akun baru" ("Daftar untuk lanjut checkout" dari checkout).
- [ ] Login dan Daftar menampilkan logo OceanMall (tanda dan tulisan), bukan teks "Ocean" "Mall".
- [ ] **Daftar**: empat syarat kata sandi (12 karakter, huruf besar dan kecil, angka, simbol) berubah dari "belum terpenuhi" ke "terpenuhi" saat mengetik, dan tombol **Daftar** nonaktif sampai semua form valid. Tombol Return memindahkan fokus ke kolom berikutnya (nama depan → nama belakang → email → HP → kata sandi → konfirmasi; keypad angka di iOS tidak punya tombol Return, jadi ketuk kolom berikutnya). Email yang sudah dipakai menampilkan pesan di bawah kolom Email, bukan hanya di atas. Nomor HP opsional, 8 sampai 15 angka.
- [ ] Teks "Dengan mendaftar, kamu menyetujui **Syarat & Ketentuan** dan **Kebijakan Privasi**" membuka kedua halaman itu, dan Back mengembalikan form dengan isian yang belum hilang.
- [ ] Daftar akun baru dari tombol **Beli sekarang** lanjut ke Checkout. Daftar dari Akun mendarat di Akun dengan kartu "Akunmu sudah aktif, <nama>!" dan tombol **Mulai belanja** (membuka Beranda; alamat memang baru bisa diisi saat checkout, jadi kartu tidak menjanjikan tombol tambah alamat). Kartu itu hanya tampil sekali dan hilang setelah pindah tab. Dengan tujuan checkout, kartu tidak tampil.
- [ ] Setelah masuk atau daftar, tombol Kembali tidak kembali ke layar Masuk/Daftar. Di Android, Back dari Akun keluar dalam satu kali tekan, dan swipe-back di iOS tidak memperlihatkan tab bar kedua.
- [ ] Masuk dengan email huruf besar-kecil campur (mis. `Customer@OceanMall.test`) berhasil. Mendaftar dengan email yang sama tapi beda huruf besar-kecil ditolak.
- [ ] Salah kata sandi berkali-kali menampilkan "Terlalu banyak percobaan. Coba lagi dalam N detik." (bukan "Too Many Attempts.").
- [ ] **Lupa kata sandi** menampilkan layar sukses dengan alamat email, "Kirim ulang (60)" yang menghitung mundur, dan tombol **Kembali ke halaman masuk**. Link di email (lihat `storage/logs/laravel.log` karena `MAIL_MAILER=log`) mengarah ke `http://localhost:8000/reset-password/...`.
- [ ] **Ubah kata sandi** (Profil & keamanan): kata sandi lama yang salah muncul di bawah kolomnya, aturan 12 karakter berlaku, dan keberhasilan menampilkan toast "Kata sandi diperbarui". Perangkat lain ikut keluar.
- [ ] **Ubah profil**: ubah nama, email, atau HP menampilkan toast "Profil diperbarui". Galat server (mis. email sudah dipakai) muncul di bawah kolom yang bersangkutan.
- [ ] **Keluar** meminta konfirmasi "Keluar dari akun?".
- [ ] **Hapus akun** (Profil & keamanan, bagian Akun): sheet "Hapus akun?" menjelaskan yang terhapus dan yang tetap disimpan, tombol **Hapus akun saya** nonaktif sampai kata sandi diisi. Kata sandi salah muncul di bawah kolomnya; enam kali salah dalam semenit menampilkan pesan terlalu banyak percobaan. Kata sandi benar: kembali ke Beranda dengan toast "Akunmu sudah dihapus", tab Akun jadi tamu, dan login dengan email itu gagal. Pesanan akun itu tetap ada di `/cpanel` tanpa customer. Halaman web `http://localhost:8000/hapus-akun` menjelaskan alur yang sama.
- [ ] **Daftar alamat**: **Jadikan utama** memunculkan toast "Alamat utama diperbarui", **Hapus** meminta konfirmasi lalu toast "Alamat dihapus". Menambah dan mengganti alamat dilakukan di checkout (tombol "Tambah alamat di checkout").

### Checkout dan pembayaran

- [ ] Sebagai tamu, checkout menampilkan "Masuk untuk lanjut checkout" dengan **Masuk** dan **Daftar**. Keranjang kosong menampilkan "Keranjang masih kosong" dengan **Belanja sekarang** (kembali ke Beranda, tanpa tab bar kedua).
- [ ] Nama dan HP terisi otomatis dari profil.
- [ ] Menekan **Simpan & hitung ongkir** dengan isian kosong menampilkan pesan merah di bawah kolom yang tepat ("Isi nama depan", "Isi nomor HP", "Isi alamat lengkap", "Pilih kecamatan dari daftar"), dan fokus pindah ke kolom pertama yang salah. HP tidak valid menampilkan "Nomor HP tidak valid".
- [ ] Mengetik di "Alamat lengkap" menggulir kolom di atas keyboard tanpa celah kosong ganda. Hasil pencarian kecamatan tidak tertutup keyboard, dan keyboard menutup setelah kecamatan dipilih.
- [ ] **Simpan & hitung ongkir** menampilkan toast "Alamat disimpan, pilih kurir" dan daftar kurir urut dari yang termurah, dan keranjang **tetap berisi**. Selama menghitung, kerangka kurir tampil.
- [ ] Memilih kurir mana pun berhasil: hanya kartu yang ditekan menampilkan spinner (kartu lain tidak diredupkan), baris "Ongkos kirim" dan "Total tagihan" menampilkan kerangka sebentar, lalu totalnya berubah.
- [ ] Metode bayar yang di luar batas nominal tampil abu-abu dengan keterangan "Minimal Rp…" atau "Maksimal Rp…".
- [ ] Kalau terjadi error, pesannya muncul di atas tombol **Buat pesanan**. Teks di bar berbunyi "Isi alamat pengiriman / Pilih kurir pengiriman / Pilih metode pembayaran untuk melanjutkan." sampai semuanya lengkap. Tidak ada kata "RajaOngkir" atau "kredensial" di layar mana pun.
- [ ] Alamat tersimpan bisa dipilih, **Ganti** dan **Tambah alamat baru** bekerja.
- [ ] Permintaan ke provider luar (simpan alamat, pilih kurir, **Buat pesanan**) menunggu sampai 45 detik, bukan 8. Untuk menguji, tambahkan `sleep(12);` sementara di awal `CheckoutController::placeOrder`: pesanan tetap berhasil dan layar "Pesanan berhasil dibuat!" terbuka. Dengan `sleep(50);` klien menyerah di 45 detik, tetapi karena keranjang di server sudah kosong dan ada pesanan belum bayar baru, app tetap membuka layar pesanan itu (bukan "Koneksi lambat" lalu "Keranjang kosong"). Kalau pengecekan itu sendiri gagal, pesan error meminta kamu membuka **Pesanan saya** dulu sebelum mencoba lagi.

### Layar pesanan dibuat

- [ ] **Buat pesanan** dengan VA BCA membuka layar **Pesanan berhasil dibuat!** (centang beranimasi, nomor pesanan dengan **Salin nomor**, total, hitung mundur "Bayar dalam HH:MM:SS" yang jadi merah di bawah satu jam, "Batas bayar …" dalam WIB, nomor VA atau QR). Tombol: **Lihat cara bayar** dan **Kembali ke beranda**.
- [ ] Tidak ada tombol Back di header. Swipe-back iOS tidak berfungsi. Tombol Kembali Android, **Selesai** di kanan atas, dan **Kembali ke beranda** semuanya mendarat di Beranda (tanpa kembali ke keranjang yang sudah kosong).
- [ ] Jalankan `komerce:simulate-payment` untuk pesanan itu: dalam sekitar 8 detik layar berganti ke **Pembayaran diterima!** dan muncul toast "Pembayaran diterima" (tanpa menekan apa pun).
- [ ] Kalau pembayaran gagal dibuat (pesanan tetap terbuat), judulnya **Pesanan dibuat**, alasan dari server tampil, dan tombol utamanya **Lihat pesanan** (bukan "Lihat cara bayar"). Di halaman detail pesanan itu, alasan yang sama tampil sebagai banner.

### Pesanan, pelacakan, dan notifikasi

- [ ] **Pesanan saya**: tab Semua, Belum bayar, Diproses, Dikirim, Selesai, Dibatalkan. Tab yang dipilih bergeser ke dalam layar. Pindah tab menampilkan kerangka, bukan layar kosong. Tombol di tiap kartu menyesuaikan status: **Bayar sekarang**, **Lacak paket**, **Konfirmasi penerimaan**, atau **Lihat detail**.
- [ ] Daftar pesanan memuat halaman berikutnya otomatis, berakhir dengan "Semua pesanan sudah ditampilkan". Kegagalan halaman berikutnya menampilkan **Coba lagi** tanpa menghapus daftar.
- [ ] Sebagai tamu, Pesanan, Notifikasi, dan Daftar alamat menampilkan ajakan **Masuk** dan **Daftar** (bukan layar kosong).
- [ ] Buka halaman order dengan VA BCA. Nomor VA tampil, tanggal berlaku mudah dibaca, dan hitung mundur berjalan. Akordeon **Cara bayar** memuat langkah per bank (QRIS untuk pembayaran QR) dengan nomor VA tercantum di langkah "Masukkan …".
- [ ] **Buka halaman bayar** membuka sheet dalam aplikasi (toolbar navy). Setelah sheet ditutup di iOS, status diperiksa diam-diam, tanpa pesan "belum dibayar".
- [ ] Saat hitung mundur mencapai nol (atau untuk pesanan lama yang sudah lewat batas), panel berganti menjadi "Batas pembayaran sudah lewat" tanpa memuat ulang halaman. Tombol **Buat pembayaran baru** hanya muncul kalau pembayaran sudah kedaluwarsa. Kalau cron sudah membatalkan pesanan, statusnya **Dibatalkan**.
- [ ] Jalankan `komerce:simulate-payment`, lalu tunggu atau tekan **Cek status pembayaran**. Status menjadi Dibayar · Diproses. Menekan **Cek status pembayaran** sebelum dibayar menampilkan toast "Pembayaran belum diterima…", bukan banner merah.
- [ ] Pembayaran lewat aplikasi lain lalu kembali ke aplikasi memperbarui status tanpa menarik layar.
- [ ] Strip progres enam langkah (Dipesan, Dibayar, Dikemas, Dikirim, Tiba, Selesai) mengikuti status. Pesanan dibatalkan menampilkan satu langkah merah **Dibatalkan**.
- [ ] Dengan kurir J&T EZ, simulasikan AWB, pickup, lalu delivered di cpanel. Di aplikasi, status pengiriman dan nomor AWB ikut berubah, riwayat pelacakan tampil enam baris dengan **Lihat semua riwayat (N)**, **Perbarui pelacakan** memunculkan toast "Status pengiriman diperbarui", dan tombol **Pesanan diterima** muncul setelah ada AWB.
- [ ] **Pesanan diterima** meminta konfirmasi ("Pesanan sudah diterima?"), lalu toast "Terima kasih! Pesanan selesai." dengan tindakan **Beri ulasan**.
- [ ] Ulasan di halaman produk menampilkan nama pengulas sebagai nama depan plus inisial nama belakang ("Budi S."), bintang, tanggal relatif, dan isi ulasan.
- [ ] **Laporkan** di bawah tiap ulasan: sebagai tamu membuka Masuk dan kembali ke produk itu. Setelah masuk, muncul pilihan alasan (kata-kata tidak pantas, spam, tidak sesuai produk, Batal). Memilih alasan menampilkan toast "Terima kasih, laporanmu sudah kami terima", ulasan hilang dari daftar dan jumlah ulasan berkurang. Di `/cpanel` > Reviews ulasan itu kembali berstatus belum disetujui; menyetujuinya lagi memunculkannya kembali. Melaporkan ulasan yang sama dua kali tidak menggandakan laporan.
- [ ] **Tulis ulasan** (di tiap produk pada pesanan yang sudah diterima) membuka sheet ulasan. Kirim berhasil menampilkan toast berisi pesan dari server (saat ini "menunggu moderasi"), dan produk yang sama menampilkan **Sudah diulas**. Mengulas dua kali ditolak.
- [ ] Baris **Butuh bantuan?** (hanya kalau kontak bantuan diisi) membuka WhatsApp dengan nomor pesanan sudah tertulis, atau email kalau WhatsApp tidak ada.
- [ ] **Notifikasi** dikelompokkan per hari (Hari ini, Kemarin, Minggu ini, Lebih lama) dengan waktu relatif ("5 mnt lalu", "Kemarin, 09:37"). Notifikasi muncul untuk pesanan baru dan pesanan dibayar. Menekan satu notifikasi langsung membuka pesanannya dan titik belum dibaca langsung hilang. **Tandai semua dibaca** menampilkan toast "Semua notifikasi ditandai dibaca". Daftar yang panjang berakhir dengan "Tidak ada notifikasi lain".
- [ ] Jumlah notifikasi belum dibaca tampil di lonceng Beranda dan pada tab **Akun**, dan menurun setelah dibaca.

### Bantuan, legal, dan jaringan

- [ ] **Pusat bantuan** dan **Tentang OceanMall** membuka layar yang sama: tagline, lima pertanyaan umum yang membuka-tutup (enam kalau kontak bantuan terisi), daftar kontak (hanya yang terkonfigurasi), serta tautan Syarat dan Kebijakan. **Hubungi kami** di Akun langsung membuka satu-satunya kanal, atau menampilkan pilihan WhatsApp dan email bila keduanya ada. Tanpa kontak terkonfigurasi, baris kontak tidak ada.
- [ ] Syarat & ketentuan dan Kebijakan privasi bisa dibuka tanpa akun. Selama `"draft"` true di `lib/legal-content.json`, ada catatan "Draf: teks ini belum ditinjau tim hukum OceanMall" dan penanda `[TODO …]` yang terlihat di teks. Ini disengaja sampai pemilik menyetujui teksnya, dan itu juga alasan build toko gagal: `metro.config.js` menolak bundel `NODE_ENV=production` selama teks masih draf, kecuali profil EAS `preview` atau `ALLOW_DRAFT_LEGAL=1` (jadi build tester tetap bisa menampilkan draf ini).
- [ ] Mode pesawat menampilkan strip kuning "Tidak ada koneksi internet" di bawah status bar (header tetap bisa ditekan). **Coba lagi** di strip memeriksa ulang koneksi. Saat online lagi, strip hijau "Kembali online" tampil sekitar 2 detik, lalu data dimuat ulang.
- [ ] Permintaan yang sekadar lambat (timeout) menampilkan "Koneksi lambat. Coba lagi, ya." dan **tidak** memunculkan strip offline. Satu kali ping `/up` yang gagal atau lambat juga tidak memunculkan strip: strip baru muncul setelah dua ping gagal berturut-turut (jeda 1,5 detik), dan tidak pernah karena ping yang sekadar lambat. Strip berada di alur layout, jadi layar tidak boleh melompat naik-turun di jaringan yang lemah.
- [ ] Rute yang tidak ada menampilkan "Halaman tidak ditemukan" dengan header navy dan Back yang berfungsi. Galat tak terduga di dalam layar menampilkan "Terjadi kesalahan" dengan **Coba lagi**.
- [ ] Teks error dari server selalu berbahasa Indonesia ("Lagi ada gangguan di sistem kami…" untuk 500, "OceanMall sedang pemeliharaan sebentar…" untuk 503). Tidak pernah "Server Error", "No query results for model", atau "HTTP 404".

### Tautan dalam (deep link)

Jalankan saat aplikasi sudah pernah dibuka (supaya pengenalan tidak ikut campur):

```bash
xcrun simctl openurl booted oceanmall://product/<slug>
adb shell am start -a android.intent.action.VIEW -d oceanmall://product/<slug> id.oceanmall.app
```

- [ ] `oceanmall://product/<slug>` dan `oceanmall://shop/<slug>` membuka produk yang sama. Back mendarat di Beranda.
- [ ] `oceanmall://category/<slug>`, `collection/<slug>`, `order/<nomor>`, `orders?status=unpaid`, `notifications`, `cart`, `search`, `promo`, dan `featured` membuka layar masing-masing.
- [ ] `oceanmall://categories`, `oceanmall://checkout`, `oceanmall://login`, dan alamat tak dikenal membuka Beranda, tidak pernah "Halaman tidak ditemukan".

## 6. Batasan yang diketahui

- Keranjang web storefront dan aplikasi belum disatukan. Web membuat keranjang per sesi, sedangkan aplikasi memakai keranjang terbaru yang berisi barang.
- Ongkir dihitung ulang setiap kali halaman Checkout dibuka. Hasil Delivery di-cache 10 menit, hasil Cost 12 jam.
- Label AWB dan tracking kurir sungguhan tidak tersedia di sandbox. Pakai tombol simulasi di cpanel.
- Setelah dibayar, order bisa langsung tampil **Dikirim**. Pengiriman sudah didaftarkan ke Komerce, tetapi belum ada AWB atau pickup. Backend menghitung status `labeled` sebagai "shipped", dan cpanel memakai aturan yang sama. Halaman detail pesanan menjelaskan keadaan itu ("terdaftar di kurir, menunggu penjemputan") dan strip progresnya tetap akurat, tetapi daftar pesanan hanya menulis **Dikirim**. Perlu keputusan tim apakah aturan ini diubah.
- API produk belum mengirim `rating_average`, `rating_count`, dan `sold_count` pada daftar produk, jadi kartu produk menampilkan nama merek (bukan rating) dan "Terjual N" tidak tampil. Aplikasi sudah siap memakainya begitu field itu ada.
- Teks Syarat & ketentuan dan Kebijakan privasi masih **draf** (`lib/legal-content.json`, `"draft": true`). File yang sama dibaca backend untuk halaman publik `/legal/*`; di `APP_ENV=local` halaman itu tampil dengan catatan draf, di luar itu 404 sampai teks disetujui dan kontak dukungan diisi di `.env` Laravel. Lama penyimpanan data belum ditetapkan (`[TODO]` di kebijakan).
- Login satu perangkat per akun berlaku untuk semua pelanggan. Hanya email di `REVIEWER_DEMO_EMAILS` (`.env` Laravel) yang bisa masuk di beberapa perangkat sekaligus; di lokal variabel itu kosong, jadi akun uji di sini tetap satu perangkat.
- Langkah "Cara bayar" tiap bank belum dicocokkan dengan panduan resmi bank (lihat catatan sandbox).
- Pemasangan dari build sebelum fitur pengenalan akan melihat pengenalan sekali dan keluar akun sekali, karena penanda "sudah dibuka" belum ada. Tidak masalah sebelum rilis.
- Ikon keranjang di header memakai `push`. Membuka produk dari halaman keranjang penuh lalu menekan ikon keranjang lagi bisa menumpuk halaman keranjang kedua (belum diuji di perangkat).

## 7. Reset data lokal

Backup database dibuat di `storage/app/backup-database-*.sqlite`. Untuk kembali ke keadaan itu, hentikan server lalu salin file backup menjadi `database/database.sqlite`.

Untuk mengosongkan sisi aplikasi (akun yang tersimpan, keranjang tamu, pencarian terakhir, penanda pengenalan), hapus aplikasi dari simulator atau HP lalu pasang lagi. Di Android bisa juga **Hapus data** pada info aplikasi.
