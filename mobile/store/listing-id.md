# Listing toko: bahasa Indonesia (bahasa utama)

Salin teks di bawah ini ke App Store Connect dan Google Play Console. Bahasa utama kedua toko: **Indonesia**. Tidak ada
lokalisasi Inggris (aplikasinya hanya berbahasa Indonesia). Kategori: **Belanja / Shopping**.

Aturan copy: jangan menyebut push notification (aplikasi belum mengirim push ke layar kunci), jangan menjanjikan garansi,
retur, atau menyebut "marketplace" (semua produk dijual langsung oleh OceanMall). Semua klaim di bawah sudah dicek ke
fitur yang ada di aplikasi.

Angka dalam kurung adalah jumlah karakter dan batas toko.

## Identitas

| Field | Isi |
| --- | --- |
| Nama aplikasi (kedua toko) | `OceanMall` (9/30). Cek ketersediaan nama di App Store Connect sebelum membuat app record. |
| Bundle ID / package | `id.oceanmall.app` |
| Kategori utama | Belanja (App Store: Shopping) |
| Hak cipta (App Store) | TODO(owner): `© 2026 <nama badan usaha>` |
| Email / WhatsApp dukungan | TODO(owner): `<email/WhatsApp>`. Harus sama dengan `EXPO_PUBLIC_SUPPORT_EMAIL` / `EXPO_PUBLIC_SUPPORT_WHATSAPP` di `.env.production`. |
| Support URL (App Store, wajib) | **Belum ada.** Laravel belum punya halaman dukungan publik (lihat "Yang memblokir submit" poin b). TODO(owner/devops): `https://<host>/<halaman dukungan>` setelah dibuat. |
| Marketing URL (opsional) | TODO(owner): `https://<host>` |
| Privacy Policy URL (kedua toko, wajib) | **Belum ada.** Teksnya baru ada di dalam aplikasi (`lib/legal-content.json`, masih draf). TODO(owner/devops): URL publik setelah halamannya dibuat (poin b). |
| Terms of Use URL | **Belum ada**, sama seperti Privacy Policy URL (poin b). |
| URL penghapusan akun (Play Data safety) | **Belum ada**, dan penghapusan akun dari dalam aplikasi juga belum ada (poin a dan b). Jangan diisi sampai keduanya dibangun. |

## App Store

**Subtitle** (18/30):

```
Belanja Lebih Luas
```

**Promotional text** (116/170). Bisa diubah kapan saja tanpa review. Teks ini sengaja netral; jangan menyebut promo
tertentu kecuali promonya sedang berjalan, dan perbarui begitu promo selesai:

```
Belanja produk pilihan, bayar lewat virtual account atau QRIS, dan lacak pesananmu langsung dari aplikasi OceanMall.
```

**Keywords** (93/100, tanpa spasi setelah koma, jangan ulangi nama app atau kategori):

```
toko online,promo,diskon,gadget,smartphone,aksesoris hp,qris,virtual account,lacak paket,resi
```

**What's New**: App Store Connect menyembunyikan kolom ini untuk versi pertama (1.0.0). Mulai isi di versi 1.0.1.

**Age rating**: ikuti hasil kuesioner Apple, jangan menulis "4+" di muka. Aplikasi punya user-generated content berupa ulasan produk dari pembeli terverifikasi (ditinjau dulu sebelum tampil; tombol laporkan per ulasan belum ada, lihat poin d di bawah), jadi jawab "Yes" pada kemampuan User-Generated Content dan jawab konten lain ("None") sesuai kenyataan: tidak ada konten dewasa atau judi. Lihat [review-notes.md](review-notes.md).

**Review notes**: lihat [review-notes.md](review-notes.md).

## Google Play

**Short description** (78/80):

```
Belanja produk pilihan, bayar via VA/QRIS, lacak paket langsung dari aplikasi.
```

**What's new, versi 1.0.0** (151/500):

```
Rilis pertama OceanMall. Jelajahi katalog dan promo, simpan alamat, bayar lewat virtual account atau QRIS, lalu lacak pesananmu langsung dari aplikasi.
```

**Content rating (IARC)**: isi kuesioner dengan jujur, termasuk pertanyaan interaksi pengguna / user-generated content (jawab Ya: ulasan produk yang dimoderasi sebelum tampil; jangan menyebut bisa dilaporkan sebelum poin d dibangun), lalu pakai hasilnya, jangan menulis "Everyone" di muka. **Target audience**: 18+ (bukan untuk anak). **Ads**: tidak ada iklan.

## Deskripsi lengkap (dipakai di Google Play; App Store memakainya sebagai Description)

```
OceanMall adalah aplikasi belanja resmi OceanMall. Temukan produk pilihan, masukkan ke keranjang, lalu bayar dan pantau pesananmu tanpa keluar dari aplikasi.

Yang bisa kamu lakukan di OceanMall:

• Jelajahi katalog, koleksi, dan promo terbaru, atau langsung cari produk yang kamu mau.
• Simpan produk ke keranjang tanpa perlu masuk dulu. Keranjangmu tetap ada setelah kamu masuk.
• Checkout lebih cepat dengan alamat tersimpan, jadi tidak perlu mengetik ulang setiap kali belanja.
• Ongkos kirim dihitung dari alamat tujuanmu, lalu pilih kurir yang paling cocok.
• Bayar lewat virtual account bank atau QRIS. Status pembayaran diperbarui otomatis.
• Pantau pesanan lewat kotak notifikasi di aplikasi, dan terima email setiap kali status pembayaran atau pengiriman berubah.
• Lacak resi pengiriman langsung dari halaman pesanan.
• Kelola profil, alamat, dan kata sandi kapan saja dari menu Akun.

Butuh bantuan? Hubungi kami di <TODO(owner): email/WhatsApp dukungan>.
```

Sebelum menempel ke toko, ganti baris "Butuh bantuan?" dengan kontak asli, atau hapus barisnya kalau belum ada.

## Grafis

| Aset | File | Catatan |
| --- | --- | --- |
| Ikon Play 512x512 | `store/play-icon-512.png` | Dibuat dari `assets/images/icon.png` oleh `node scripts/store-assets.js` |
| Feature graphic Play 1024x500 | `store/feature-graphic-1024x500.png` | Navy `#0a2a6b` + lockup putih, PNG 24-bit tanpa alpha |
| Ikon App Store 1024x1024 | `ios/OceanMall/Images.xcassets/AppIcon.appiconset/App-Icon-1024x1024@1x.png` | Sudah tanpa alpha, tidak perlu diunggah manual |
| Screenshot | lihat [README.md](README.md#screenshot) | Diambil setelah semua paket UX selesai digabung |

## Yang memblokir submit (harus ada sebelum review)

**Open blockers: belum dibangun, butuh pekerjaan backend dan/atau keputusan owner.** Selama keempatnya terbuka, aplikasi
belum boleh disubmit. Penjelasan lengkap dan pemiliknya ada di [README.md](../README.md#open-blockers-sebelum-submit-ke-toko).

- **(a) Hapus akun dari dalam aplikasi: belum dibangun.** Butuh endpoint backend dan layar di aplikasi (Apple 5.1.1(v),
  kebijakan account deletion Google Play). Implementasi siap pakai ada di luar repo untuk sesi lanjutan terpisah:
  `/Users/apriansyahrs/.claude/projects/-Users-apriansyahrs-Documents-Code-oceanmall/c82da155-7d6a-41de-b718-12e352ce569b/plan/backend-compliance/`.
- **(b) URL publik: belum ada.** Kebijakan privasi, syarat, dukungan, dan penghapusan akun wajib punya URL publik di
  kedua toko. Laravel belum punya halaman-halaman itu.
- **(c) Login satu perangkat per akun vs akun demo reviewer.** Login kedua mengeluarkan yang pertama, jadi akun demo
  bersama akan saling mengeluarkan. Owner memutuskan cara mengecualikan akun demo.
- **(d) Laporkan per ulasan: belum dibangun.** Ulasan publik adalah user-generated content (Apple 1.2); butuh endpoint
  backend dan tombol di aplikasi.

Selain itu, masih menunggu owner (build rilis gagal sampai poin 1 sampai 3 beres):

1. Teks hukum disetujui: isi semua `[TODO ...]` di `mobile/lib/legal-content.json` dan ubah `"draft"` jadi `false`
   (entitas usaha, pengembalian dan garansi, lama penyimpanan data, proses penghapusan akun). Sampai itu terjadi
   `metro.config.js` menolak build toko.
2. Kontak dukungan nyata di `mobile/.env.production` (`EXPO_PUBLIC_SUPPORT_EMAIL` dan/atau
   `EXPO_PUBLIC_SUPPORT_WHATSAPP`). Isi juga baris "Hak cipta" dan "Email / WhatsApp dukungan" di tabel Identitas.
3. Host produksi sungguhan di `mobile/.env.production` (build menolak jalan selama masih `CHANGE-ME`).
4. Akun demo untuk reviewer (lihat [review-notes.md](review-notes.md) dan poin c di atas).
