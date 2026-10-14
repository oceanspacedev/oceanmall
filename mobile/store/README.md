# Store: aset listing, checklist console, dan QA rilis

Folder ini berisi semua yang dibutuhkan untuk mengisi App Store Connect dan Google Play Console. Langkah build dan
upload ada di [../README.md](../README.md#rilis-ke-store).

## Open blockers (belum bisa submit)

Empat hal berikut **belum dibangun** dan wajib selesai sebelum submit. Penjelasan lengkap, alasan, dan pemiliknya ada di
[../README.md](../README.md#open-blockers-sebelum-submit-ke-toko).

| # | Yang kurang | Pemilik |
| --- | --- | --- |
| a | Hapus akun dari dalam aplikasi (butuh endpoint backend dan layar di aplikasi; Apple 5.1.1(v), Play account deletion). Implementasi siap pakai ada di luar repo untuk sesi lanjutan terpisah: `/Users/apriansyahrs/.claude/projects/-Users-apriansyahrs-Documents-Code-oceanmall/c82da155-7d6a-41de-b718-12e352ce569b/plan/backend-compliance/` | Backend + Mobile |
| b | URL publik kebijakan privasi, syarat, dukungan, dan penghapusan akun (wajib di kedua toko; belum ada di Laravel) | Owner + Backend/Devops |
| c | Login satu perangkat per akun akan mengeluarkan akun demo bersama milik reviewer; owner harus memutuskan cara mengecualikan akun demo | Owner (keputusan) + Backend |
| d | Laporkan/blokir per ulasan untuk user-generated content (Apple 1.2): endpoint backend dan tombol di aplikasi | Backend + Mobile |

| File | Isi |
| --- | --- |
| [listing-id.md](listing-id.md) | Teks listing bahasa Indonesia (subtitle, deskripsi, keywords, What's New) |
| [review-notes.md](review-notes.md) | Catatan reviewer (Inggris), akun demo, dan pemetaan App Privacy / Data safety |
| `play-icon-512.png` | Ikon Play 512x512 (dibuat oleh `scripts/store-assets.js`) |
| `feature-graphic-1024x500.png` | Feature graphic Play 1024x500, PNG 24-bit tanpa alpha (dibuat oleh `scripts/store-assets.js`) |

## Grafis

```bash
cd mobile
npm run assets:store      # tulis ulang store/play-icon-512.png dan store/feature-graphic-1024x500.png
```

Jalankan ulang setelah `npm run assets:brand` mengubah ikon atau lockup putih. Ikon App Store 1024x1024 sudah ada
di asset catalog iOS (tanpa alpha), tidak perlu diunggah terpisah.

## Screenshot

Ambil screenshot **dari build rilis yang sudah jalan** (bukan Metro dev), setelah UI sudah final. Jangan memberi
caption pada layar yang isinya bergantung pada data demo yang belum tentu ada, dan jangan menulis harga atau promo yang
bisa kedaluwarsa.

| Toko | Ukuran | Catatan |
| --- | --- | --- |
| App Store iPhone 6.9" | 1320x2868 | Wajib. Simulator iPhone 17 Pro Max (atau Pro Max terbaru). |
| App Store iPhone 6.5" | 1284x2778 | Hanya kalau App Store Connect memintanya. |
| App Store iPad | tidak ada | Aplikasi khusus iPhone (`supportsTablet: false`). |
| Google Play telepon | 1080x2400 | Minimal 2, maksimal 8. |

Cara ambil (tanpa frame perangkat, caption ditambahkan di tool desain):

```bash
# iOS: rapikan status bar dulu, lalu ambil
xcrun simctl status_bar booted override --time 9:41 --batteryState charged --batteryLevel 100 --cellularBars 4
xcrun simctl io booted screenshot --type=png beranda.png

# Android
adb exec-out screencap -p > beranda.png
```

Caption adalah teks yang ditempel di atas gambar (bukan metadata toko), jadi harus berbahasa Indonesia dan ikut dibuat
ulang kalau layarnya berubah.

| # | Layar | Headline di gambar |
| --- | --- | --- |
| 1 | Beranda | Belanja Lebih Luas |
| 2 | Detail produk | Detail produk yang jelas (usulan) |
| 3 | Keranjang | Satu keranjang, checkout cepat (usulan) |
| 4 | Checkout | Bayar via VA dan QRIS |
| 5 | Detail pesanan | Lacak resi dari aplikasi |
| 6 | Akun | Pesanan dan alamat di satu tempat (usulan) |

Headline 1, 4, dan 5 dari rencana awal; 2, 3, dan 6 adalah usulan, ganti sesuai selera brand.

## Checklist console

Owner: **Owner** = pemilik produk, **Backend**, **Devops**, **Dev** = tim mobile. Isi kolom Status sendiri.

### App Store Connect

| Item | Owner | Status |
| --- | --- | --- |
| Apple Developer Program aktif, App ID `id.oceanmall.app` terdaftar | Owner | Belum |
| App record dibuat, nama `OceanMall` tersedia | Owner | Belum |
| Support URL: **belum ada**, Laravel belum punya halaman dukungan publik (blocker b) | Owner + Backend/Devops | Belum dibangun |
| Privacy Policy URL dan Terms URL: **belum ada**, teksnya baru ada di dalam aplikasi (blocker b) | Owner + Backend/Devops | Belum dibangun |
| Penghapusan akun dari dalam aplikasi (guideline 5.1.1(v)): **belum dibangun** (blocker a) | Backend + Mobile | Belum dibangun |
| Teks hukum disetujui: isi `[TODO ...]` dan `"draft": false` di `lib/legal-content.json` (build toko gagal sebelum itu) | Owner | Belum |
| Kontak dukungan di `.env.production` (build rilis gagal sebelum itu) | Owner | Belum |
| App Privacy: isi dari tabel di [review-notes.md](review-notes.md) | Owner | Belum |
| Age rating: isi kuesioner jujur, termasuk User-Generated Content (ulasan produk), lalu pakai hasilnya | Owner | Belum |
| Export compliance: otomatis aman (`ITSAppUsesNonExemptEncryption=false`) | Dev | Selesai |
| Akun demo produksi + alamat tersimpan, masuk ke App Review Information. Perhatikan blocker c: satu perangkat per akun | Devops + Owner | Belum |
| Moderator memantau `/cpanel` > Reviews selama masa review (setujui atau hapus ulasan dengan cepat) | Owner | Belum |
| Laporkan per ulasan (guideline 1.2): **belum dibangun** (blocker d) | Backend + Mobile | Belum dibangun |
| Screenshot 6.9" (dan 6.5" bila diminta) | Dev | Belum |
| `eas.json` `submit.production.ios`: `ascAppId` dan `appleTeamId` | Owner | Belum |
| TestFlight internal dulu sebelum submit untuk review | Dev | Belum |

Sign in with Apple tidak diperlukan (tidak ada login pihak ketiga).

### Google Play Console

| Item | Owner | Status |
| --- | --- | --- |
| Akun developer. Akun personal baru wajib closed test minimal 12 tester selama 14 hari berturut-turut sebelum boleh rilis produksi. Akun organisasi butuh nomor D-U-N-S. Mulai closed test secepat AAB pertama jadi. | Owner | Belum |
| App dibuat (bahasa utama Indonesia, gratis, kategori Belanja) | Owner | Belum |
| Upload AAB pertama **manual** lewat Console (API Play tidak bisa membuat app baru), baru setelah itu `eas submit` | Dev | Belum |
| Enroll Play App Signing; kunci upload dikelola EAS (`eas credentials -p android`) | Dev | Belum |
| Service account untuk `eas submit`, simpan sebagai `mobile/play-service-account.json` (di-gitignore) | Devops | Belum |
| Store listing: deskripsi, ikon 512, feature graphic, screenshot telepon | Dev | Belum |
| Data safety: isi dari tabel di [review-notes.md](review-notes.md) | Owner | Belum |
| Content rating (IARC): isi kuesioner jujur, termasuk interaksi pengguna (ulasan produk yang dimoderasi sebelum tampil; jangan menyebut bisa dilaporkan sebelum blocker d dibangun), lalu pakai hasilnya | Owner | Belum |
| Target audience: bukan untuk anak. Ads: tidak ada iklan | Owner | Belum |
| App access: tempel instruksi reviewer + akun demo | Devops | Belum |
| Financial features declaration: tidak menyediakan layanan keuangan (jual barang fisik), konfirmasi sekali dengan konsultan | Owner | Belum |
| URL penghapusan akun (Data safety): **belum ada**, dan penghapusan akun di aplikasi juga belum ada (blocker a dan b) | Backend + Owner | Belum dibangun |
| Cek dukungan page size 16 KB: `zipalign -c -P 16 -v 4 app-release.apk` (target SDK 36) atau pre-launch report Play | Dev | Belum |
| Konfirmasi kewajiban pendaftaran PSE (Penyelenggara Sistem Elektronik) dengan konsultan hukum | Owner | Belum |

### Keputusan yang masih terbuka

- **Crash reporting**: v1.0 tanpa SDK crash reporting berarti rilis pertama buta terhadap crash. Kalau ditambahkan
  (Sentry atau Crashlytics), ubah `app.json` `ios.privacyManifests`, jawaban App Privacy, Data safety, dan kalimat "tanpa
  SDK analytics" di `review-notes.md` dalam perubahan yang sama.
- **Tablet dan foldable Android**: target SDK 36 mengabaikan `screenOrientation=portrait` dan batasan resize di layar
  lebar (600dp ke atas). Pilih: batasi lebar konten maksimal 600 dan beri pass emulator resizable, atau terima listing
  khusus ponsel.
- **Universal links** (`https://<host>/shop/<slug>` membuka aplikasi): butuh host produksi plus file asosiasi
  (`apple-app-site-association`, `assetlinks.json`), lalu isi `ios.associatedDomains` dan `android.intentFilters`.
  `app/+native-intent.tsx` sudah memetakan link https, jadi sisanya hanya konfigurasi.

## Checklist QA rilis

Jalankan di build **Release** (bukan Metro dev) di perangkat fisik, iOS dan Android. Skenario fungsional lain ada di
[../TESTING.md](../TESTING.md).

**Config dan build**

- [ ] `npm run check` (typecheck, format, dan pemeriksa warna) lulus.
- [ ] Build produksi dengan host `CHANGE-ME` **gagal** di langkah bundling dengan pesan `EXPO_PUBLIC_API_URL ...`
      (`npx expo export --platform ios --output-dir /tmp/oceanmall-export`). Hal yang sama untuk dua gerbang lain,
      semuanya di `metro.config.js` dan hanya aktif saat `NODE_ENV=production`: tanpa kontak dukungan yang valid di
      `.env.production` build gagal dengan "No usable support contact", dan selama `lib/legal-content.json` masih
      `"draft": true` atau memuat `[TODO` build toko gagal dengan "legal text ... is not ready". Profil EAS `preview`
      dan `ALLOW_DRAFT_LEGAL=1` melewati gerbang teks hukum saja, untuk build tester. Archive Xcode lokal tidak
      memasang `EAS_BUILD_PROFILE`, jadi diperiksa seperti build toko.
- [ ] Dengan host https sungguhan app jalan dan katalog termuat. Build Debug tetap jalan ke localhost.
- [ ] iOS: Info.plist hasil archive berisi `UIUserInterfaceStyle=Light`, `ITSAppUsesNonExemptEncryption=false`,
      hanya perangkat iPhone, dan `PrivacyInfo.xcprivacy` ikut terbundel dengan tujuh tipe data (termasuk
      `NSPrivacyCollectedDataTypeOtherUserContent` untuk ulasan).
- [ ] Android (APK atau AAB rilis): `aapt dump permissions app-release.apk` tidak memuat `SYSTEM_ALERT_WINDOW`,
      `READ_EXTERNAL_STORAGE`, `WRITE_EXTERNAL_STORAGE`; manifest `allowBackup=false`.
- [ ] Splash Android 12+ (kunci layar ponsel ke tema terang lalu gelap): tanda putih utuh di dalam lingkaran, tidak
      terpotong, tidak ada kilatan putih.
- [ ] Ikon aplikasi di launcher dan nama `OceanMall` benar di iOS dan Android (termasuk ikon monokrom Android 13+).

**a. Teks besar.** iOS: Pengaturan > Aksesibilitas > Tampilan & Ukuran Teks > Teks Lebih Besar (nyalakan juga "Ukuran
Aksesibilitas Lebih Besar" untuk ujung ekstrem). Android: Pengaturan > Tampilan > Ukuran font dan Ukuran tampilan
(terbesar). Cek: login, sticky bar produk, bar keranjang, baris ringkasan checkout, badge tab, pil di beranda. Tidak
ada teks terpotong atau tumpang tindih, tombol utama tetap bisa ditekan.

**b. Pembaca layar.** TalkBack (Android) dan VoiceOver (iOS) di: login, kartu produk, baris keranjang, layar pesanan
dibuat. Setiap kontrol punya label dalam bahasa Indonesia dan urutan fokus masuk akal.

**c. Deep link dari kondisi mati.** Matikan aplikasi total, lalu:

```bash
xcrun simctl openurl booted oceanmall://product/<slug>
adb shell am start -a android.intent.action.VIEW -d oceanmall://product/<slug> id.oceanmall.app
```

Produk tampil, Back mendarat di Beranda, Back kedua keluar aplikasi. Ulangi dengan `oceanmall://shop/<slug>` (harus
sama), `oceanmall://categories` dan `oceanmall://checkout` (harus ke Beranda, tidak pernah "tidak ditemukan").

**d. Navigasi balik.** Produk > keranjang > Back kembali ke produk. Masuk dari tab Akun lalu Back di Android keluar
dalam satu kali tekan. Di iOS, swipe-back setelah login tidak memperlihatkan tab bar kedua.

**e. Predictive back (keputusan, bukan default).** Manifest sekarang `android:enableOnBackInvokedCallback="false"`.
Di build percobaan, nyalakan dan cek peek tombol Back di auth, checkout, dan detail pesanan sebelum memutuskan
mengirimnya (target SDK 36 bisa memaksanya). Jangan menambah `predictiveBackGestureEnabled` di `app.json` sebelum itu.

**f. Aturan kata sandi produksi.** Daftar dengan kata sandi 12+ karakter campuran. Staging menerima 8 karakter, tapi
aplikasi memakai aturan produksi.

**g. Mode pesawat.** Strip offline muncul, strip "Kembali online" muncul saat jaringan pulih, tidak menimpa header.

**h. Hanya tema terang.** Di ponsel yang disetel mode gelap: seluruh layar, keyboard, alert, status bar, dan splash
tetap terang dan terbaca (iOS dan Android).

**i. Hapus akun, laporkan ulasan, dan URL publik: belum bisa diuji.** Ketiganya belum dibangun (blocker a, b, dan d di
atas). Setelah dibangun, tambahkan uji di sini di build rilis dengan host produksi: hapus akun baru dari aplikasi
(kata sandi salah ditolak, benar menghapus akun dan kembali ke Beranda sebagai tamu, login lagi gagal), tombol laporkan
per ulasan, dan URL publik kebijakan, syarat, dukungan, dan penghapusan akun terbuka di jendela privat tanpa login dan
tanpa penanda "Draf" atau `[TODO`.

## Android: shrink dan minify (setelah uji perangkat)

`android.enableMinifyInReleaseBuilds` dan `android.enableShrinkResourcesInReleaseBuilds` di `android/gradle.properties`
sengaja **belum dinyalakan**. Nyalakan hanya sebagai langkah terpisah setelah build internal-track diuji di **perangkat
Android fisik** dan semua ini lulus:

1. Login dan sesi tersimpan (SecureStore), tutup paksa lalu buka lagi.
2. Gambar produk termuat (expo-image).
3. Animasi (Reanimated 4, react-native-worklets) jalan.
4. QR code pembayaran tampil (react-native-svg).
5. Splash dan ikon adaptif tampil (resource yang direferensikan lewat nama bisa terhapus oleh shrink).

Kalau ada yang rusak, rilis dulu dengan `shrinkResources` mati dan tambahkan aturan `-keep` di
`android/app/proguard-rules.pro`. `localeFilters` dan `resourceConfigurations` juga sengaja tidak dipakai (terjemahan
AppCompat/Material untuk TalkBack bisa ikut terhapus).

## Penandatanganan

- Build toko ditandatangani oleh EAS. Jangan ubah `signingConfigs` di `android/app/build.gradle`.
- `./gradlew assembleRelease` atau `bundleRelease` lokal memakai kunci **debug** dan **tidak bisa diunggah** ke Play.
  Berguna hanya untuk uji smoke (boot dan render beranda).
- iOS: provisioning dan sertifikat dikelola EAS (`eas credentials -p ios`) atau Xcode Automatic Signing untuk archive
  lokal.
