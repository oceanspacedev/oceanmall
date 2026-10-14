# OceanMall Mobile

Aplikasi belanja OceanMall untuk pelanggan (iOS / Android / web) dengan **Expo Router**, **NativeWind**, dan **React Native Reusables**.

Alur yang didukung: pengenalan (sekali, di pemasangan baru) → browse/cari produk → keranjang → daftar/masuk → checkout (alamat RajaOngkir + ongkir + pembayaran Komerce) → layar "pesanan dibuat" dengan hitung mundur dan nomor VA/QR → status bayar otomatis → lacak resi → konfirmasi diterima → ulasan.

Backend: Laravel `/api/v1` (Sanctum Bearer). Bukan admin `/cpanel`. Satu akun hanya aktif di satu perangkat: login di perangkat lain mengeluarkan perangkat sebelumnya, dan mengubah kata sandi mengeluarkan semua perangkat lain (sengaja, `AuthController::login`).

> **Belum siap submit ke App Store / Play Store.** Ada empat hal yang masih kurang, lihat [Open blockers](#open-blockers-sebelum-submit-ke-toko).

## Prasyarat

- Node.js 20+
- Laravel jalan di `http://127.0.0.1:8000` (`composer run dev` atau `php artisan serve`), plus `php artisan queue:listen` untuk proses setelah bayar
- Expo Go di HP, atau simulator iOS / emulator Android

## Jalanin

```bash
cd mobile
cp .env.example .env   # sesuaikan EXPO_PUBLIC_API_URL
npm install
npm run dev
```

- **iOS simulator**: tekan `i` (API default `http://127.0.0.1:8000/api/v1`)
- **Android emulator**: tekan `a` (API default `http://10.0.2.2:8000/api/v1`)
- **HP fisik**: set `EXPO_PUBLIC_API_URL=http://<IP-LAN-laptop>:8000/api/v1` dan serve Laravel dengan `--host=0.0.0.0`
- **Web**: tekan `w`

Request API selalu kirim `Accept: application/json` + `Authorization: Bearer` setelah login.

## Konfigurasi (`EXPO_PUBLIC_*`)

Semua dibaca `lib/config.ts` dan ikut terbundel ke aplikasi, jadi jangan pernah menaruh rahasia di sini.

| Variabel | Dipakai untuk | Dev (`.env`) | Rilis (`.env.production`) |
| --- | --- | --- | --- |
| `EXPO_PUBLIC_API_URL` | Basis API Laravel, mis. `https://host/api/v1` | opsional (ada default simulator/emulator) | **wajib**, harus `https://`, bukan `CHANGE-ME` |
| `EXPO_PUBLIC_WEB_URL` | Origin storefront untuk link bagikan produk (`<origin>/shop/<slug>`) | opsional | opsional, default: API URL tanpa `/api/v1` |
| `EXPO_PUBLIC_SUPPORT_WHATSAPP` | Kontak bantuan, angka format internasional tanpa `+` (mis. `62812...`) | opsional | **wajib salah satu dari dua kontak ini**, build gagal tanpanya |
| `EXPO_PUBLIC_SUPPORT_EMAIL` | Kontak bantuan | opsional | (lihat baris di atas) |

- Build rilis (`eas build`, Xcode Release, `gradlew bundleRelease`, `expo export`) membaca **`.env.production`**, bukan `.env`.
  File itu di-commit (host produksi bukan rahasia). `.gitignore` root mengabaikan `.env.production`, jadi
  `mobile/.gitignore` menegasikannya; cek dengan `git check-ignore -v mobile/.env.production`.
- **Guard https**: `metro.config.js` menggagalkan **build** kalau `EXPO_PUBLIC_API_URL` kosong, masih `CHANGE-ME`, atau bukan
  `https://`. Build dengan host salah tidak akan sampai ke TestFlight atau Play. Lapisan kedua ada di `lib/config.ts`:
  di rilis ia tidak melempar error saat import, tapi mengekspor `API_URL = ''` dan `API_CONFIG_ERROR`.
  `AuthGate` (`lib/auth.tsx`) lalu menampilkan layar polos "Konfigurasi server belum diatur"
  (`components/config-error-screen.tsx`) dan tidak memanggil server sama sekali.
- **Guard kontak dukungan**: di rilis, `metro.config.js` juga menggagalkan build kalau tidak ada kontak yang valid (email
  berbentuk email dan bukan `CHANGE-ME`, atau nomor WhatsApp minimal 8 angka). Aplikasi membuang nilai yang tidak valid,
  jadi tanpa guard ini build bisa terbit tanpa satu pun jalur menghubungi kami, padahal kebijakan privasi dan listing toko
  menunjuk ke sana. Backend Laravel belum punya setelan kontak dukungan maupun halaman bantuan publik (lihat
  [Open blockers](#open-blockers-sebelum-submit-ke-toko)).
- **Guard teks hukum**: di rilis, `metro.config.js` menggagalkan build toko selama `lib/legal-content.json` masih
  `"draft": true` atau memuat `[TODO`. Profil EAS `preview` (build tester) dan `ALLOW_DRAFT_LEGAL=1` (build internal lain)
  dikecualikan, supaya QA bisa membaca draf. Archive Xcode lokal tidak memasang `EAS_BUILD_PROFILE`, jadi diperiksa seperti
  build toko.
- `.env*.local` (`.env.local`, `.env.development.local`, `.env.production.local`, dst.) digitignore dan **menimpa** file di
  atas, termasuk `.env.production` pada build rilis. Jangan ada di mesin yang membuat build rilis. `.env.production`
  sengaja mendaftar setiap kunci (kosong pun terhitung terdefinisi), supaya nilai dari `.env` tidak ikut masuk ke build rilis.

## Skrip npm

| Skrip | Fungsi |
| --- | --- |
| `npm run typecheck` | `tsc --noEmit` untuk seluruh app |
| `npm run format` / `npm run format:check` | Prettier (dengan plugin Tailwind) untuk `app/`, `components/`, `lib/` |
| `npm run check:colors` | `scripts/check-colors.js`: gagal bila ada warna hex atau kelas palet mentah (zinc, slate, sky, gray, rose, amber) di `app/`, `components/`, `lib/` |
| `npm run check` | ketiganya sekaligus; jalankan sebelum membuka PR |
| `npm run assets:brand` | membuat ulang ikon, splash, adaptive icon, dan logo di `assets/images` dari `../public/images` |
| `npm run assets:store` | membuat ulang ikon Play 512 dan feature graphic di `store/` |

## QA

Panduan uji lengkap (akun uji, simulasi pembayaran sandbox, checklist skenario): [TESTING.md](TESTING.md).
Checklist QA khusus rilis (teks besar, pembaca layar, deep link, build Release): [store/README.md](store/README.md#checklist-qa-rilis).

## UI

Komponen dasar dari [React Native Reusables](https://reactnativereusables.com) ada di `components/ui/`: `Button`
(prop `loading`), `Input` (prop `invalid`), `Label`, `Card`, `Badge`, `Avatar`, `Skeleton`, `Accordion`, `ToggleGroup`,
`Tabs`, `Alert`, `Separator`, `Text`, `Icon`, ditambah `Spinner`, `PressableScale`, dan `Toast`. Tambah komponen:

```bash
npx @react-native-reusables/cli@latest add
```

Aturan yang dijaga kode ini:

- **Satu palet, hanya tema terang.** Token warna ada di `global.css` (CSS variable, tidak ada blok `.dark`) dan
  dicerminkan ke JS di `lib/theme.ts` (`COLORS`, `SHADOW`, `IMAGE_PLACEHOLDER`, `NAV_THEME`) untuk prop yang tidak
  menerima `className` (warna ikon, placeholder, spinner). `app/_layout.tsx` mengunci NativeWind ke `light`, dan
  `app.json` memakai `userInterfaceStyle: light`. Jangan menulis hex atau kelas palet mentah; `npm run check:colors`
  menolaknya.
- **Radius** dari `--radius: 0.5rem` (`rounded-sm` 4px, `md` 6px, `lg` 8px). Elevasi hanya lewat `SHADOW`.
- **Layar** memakai `Screen` (header navy dengan Back, tombol keranjang, skeleton `loading`) atau `TabHeader` untuk tab
  (`components/screen.tsx`). Kerangka loading ada di `components/skeletons.tsx`, status kosong di `EmptyState`, error
  sebaris di `ErrorBanner`, tarik-untuk-muat di `BrandRefreshControl` + `lib/use-refresh.ts`. Umpan balik singkat lewat
  `toast.show(...)` (`lib/toast.tsx`), bukan dialog.
- **Daftar** (produk, pesanan, notifikasi) memakai `FlatList` (`SectionList` untuk notifikasi per hari) dengan
  `onEndReached` dan footer `LoadMore`; tidak ada tombol "Muat lebih banyak". Kegagalan halaman berikutnya tidak menghapus daftar.
- **Uang dan tanggal** hanya lewat `lib/format.ts` (`formatIdr`, `formatDateTime`, `formatRelative`): tanpa `Intl`, jadi
  tampilannya sama di semua perangkat dan zona waktu (waktu server dibaca sebagai WIB).
- **Error API** selalu lewat `errorMessage()` / `apiFieldErrors()` (`lib/api.ts`), yang mengubah teks framework
  ("Server Error", "Too Many Attempts.") menjadi kalimat Indonesia.
- **Rute baru** perlu `npx expo start -c` sekali supaya typed routes (`.expo/types`) ikut diperbarui.
- **Tautan masuk** dipetakan oleh `app/+native-intent.tsx` ke rute yang benar-benar ada (`oceanmall://shop/<slug>` dan
  `https://<host>/shop/<slug>` menjadi `/product/<slug>`); yang tidak dikenal mendarat di Beranda, tidak pernah di
  "tidak ditemukan".

Aturan teks: **setiap string yang terlihat pengguna harus berbahasa Indonesia** (informal "kamu", sentence case). Itu
berlaku juga untuk label aksesibilitas, alert, toast, dan pesan error dari API yang ditampilkan apa adanya. Teks legal
(`lib/legal-content.ts`, sumber teksnya `lib/legal-content.json`) masih draf sampai `"draft"` di JSON itu diubah ke `false`. Teks itu hanya dibundel di aplikasi: Laravel tidak membacanya dan belum punya halaman publik untuknya.

## Open blockers sebelum submit ke toko

Ini yang **belum ada** dan wajib selesai sebelum build toko pertama. Tidak ada satu pun yang sudah dibangun; backend Laravel
saat ini tidak punya endpoint hapus akun, endpoint laporan ulasan, maupun halaman web publik. Guard build
(`metro.config.js`: host API, kontak dukungan, teks hukum bukan draf) hanya mencegah build yang setengah jadi, bukan
mengganti keempat pekerjaan ini.

| # | Yang kurang | Kenapa wajib | Pemilik |
| --- | --- | --- | --- |
| a | **Hapus akun dari dalam aplikasi.** Butuh endpoint backend (mis. `DELETE /api/v1/auth/me` dengan konfirmasi kata sandi) **dan** layar atau sheet di aplikasi (baris "Hapus akun" di `app/profile.tsx`, `deleteAccount` di `lib/auth.tsx`). | Apple App Review 5.1.1(v) dan kebijakan account deletion Google Play: akun yang bisa dibuat di aplikasi harus bisa dihapus dari aplikasi. | Backend + Mobile (sesi lanjutan terpisah). Implementasi siap pakai ada di luar repo: `/Users/apriansyahrs/.claude/projects/-Users-apriansyahrs-Documents-Code-oceanmall/c82da155-7d6a-41de-b718-12e352ce569b/plan/backend-compliance/` (`backend-modified.patch` dan `backend-new-files.tgz`, termasuk sheet `delete-account-sheet.tsx`). |
| b | **URL publik** untuk kebijakan privasi, syarat, dukungan, dan penghapusan akun. Belum ada di Laravel (tidak ada `/legal/*`, `/bantuan`, `/hapus-akun`). Teks kebijakan dan syarat baru ada di dalam aplikasi (`lib/legal-content.json`, masih draf). | Kedua toko mewajibkan URL ini di listing (Privacy Policy URL, Support URL, dan URL penghapusan akun untuk Data safety Play). | Owner (isi teks) + Backend/Devops (halaman publik). |
| c | **Satu perangkat per akun vs akun demo reviewer.** `AuthController::login` menghapus token `mobile` lain, jadi login kedua mengeluarkan yang pertama. Akun demo bersama yang dipakai reviewer Apple/Google (atau dipakai tim bersamaan) akan saling mengeluarkan. | Reviewer yang tiba-tiba keluar akun menolak build ("tidak bisa login"). Perilaku satu perangkat itu sengaja, jangan diubah tanpa keputusan. | Owner memutuskan cara mengecualikan akun demo (mis. akun demo khusus yang tidak mencabut token lain); Backend mengerjakan. |
| d | **Laporkan atau blokir per ulasan.** Ulasan publik adalah konten buatan pengguna. Ulasan sudah dimoderasi dulu sebelum tampil (di `/cpanel` > Reviews), tetapi tidak ada tombol laporkan per ulasan di aplikasi dan tidak ada endpoint laporan di backend. | Apple App Review 1.2 (user-generated content): harus ada cara melaporkan konten yang tidak pantas, dan menanggapinya. | Backend (endpoint + antrean moderasi) + Mobile (tombol per ulasan). |

Yang sudah ada dan tetap dipertahankan: teks Syarat dan Kebijakan privasi di dalam aplikasi, kontak dukungan
(`lib/support.ts`: WhatsApp atau email, termasuk "Laporkan ulasan atau masalah" di sheet ulasan yang membuka kanal itu
dengan nomor pesanan), dan guard build. Detail pengisian listing dan form toko: [store/README.md](store/README.md).

## Rilis ke store

Folder `ios/` dan `android/` **di-commit** dan sudah disetel untuk Xcode 27 serta berisi ikon/splash hasil generate.
Karena itu **jangan pernah** menjalankan `npx expo prebuild --clean` di folder ini: ia menimpa semuanya. EAS Build juga
memakai `ios/` dan `android/` yang ada apa adanya, jadi config plugin di `app.json` tidak dijalankan saat build.

1. **Ubah `app.json`.** Ia sumber kebenaran untuk versi, `buildNumber`/`versionCode`, permission, dan privacy manifest.
2. **Cerminkan kuncinya ke native dengan tangan.** Kunci yang dicerminkan sekarang:

   | `app.json` | iOS | Android |
   | --- | --- | --- |
   | `userInterfaceStyle: light` | `Info.plist` `UIUserInterfaceStyle` | `strings.xml` `expo_system_ui_user_interface_style` |
   | `primaryColor`, `expo-status-bar` style `light` | `Info.plist` `UIStatusBarStyle` | `colors.xml` `colorPrimary`, `styles.xml` `windowLightStatusBar` |
   | `ios.supportsTablet: false` | `project.pbxproj` `TARGETED_DEVICE_FAMILY = "1"` | - |
   | `ios.config.usesNonExemptEncryption` | `Info.plist` `ITSAppUsesNonExemptEncryption` | - |
   | `ios.infoPlist` (locale `id`, Face ID) | `Info.plist` | - |
   | `ios.privacyManifests` | `PrivacyInfo.xcprivacy` | - |
   | `android.blockedPermissions`, `allowBackup` | - | `AndroidManifest.xml` (`tools:node="remove"`, `allowBackup`) |
   | `ios.buildNumber`, `android.versionCode`, `version` | `Info.plist` | `app/build.gradle` |
   | `expo-splash-screen` (`android.image`) | `Images.xcassets` | `res/drawable-*/splashscreen_logo.png` |

   Naikkan `version` (mis. 1.0.1) di **tiga** tempat sekaligus: `app.json`, `ios/OceanMall/Info.plist`
   (`CFBundleShortVersionString`), dan `android/app/build.gradle` (`versionName`), plus `version` di `package.json`.
   `buildNumber` dan `versionCode` dinaikkan otomatis oleh EAS (`appVersionSource: remote`, `autoIncrement`). Saat build
   pertama EAS menanyakan nilai awal, pakai 1 untuk keduanya.
3. **Verifikasi dengan salinan (opsional tapi disarankan).** Prebuild di salinan sementara dan bandingkan hanya
   kunci yang kamu sentuh. Jangan terapkan hasilnya ke `ios/` atau `android/` asli.

   ```bash
   S=$(mktemp -d) && rsync -a --exclude node_modules --exclude ios --exclude android --exclude .expo ./ "$S/"
   ln -s "$PWD/node_modules" "$S/node_modules" && (cd "$S" && npx expo prebuild --no-install --clean)
   diff -r --exclude build --exclude .gradle --exclude .cxx "$S/android" android   # harus kosong
   diff -r --exclude Pods --exclude build "$S/ios/OceanMall" ios/OceanMall         # lihat selisih yang dikenal di bawah
   ```

   Selisih yang **dikenal dan disengaja** (jangan "diperbaiki"):

   - iOS `Info.plist`: `UISupportedInterfaceOrientations~ipad` dan `RCTNewArchEnabled` hanya ada di repo.
     **Jangan menambah blok `dark` di tingkat atas plugin `expo-splash-screen`**: plugin itu lalu memaksa
     `UIUserInterfaceStyle=Automatic` dan app berhenti terkunci terang. Splash gelap Android diatur lewat
     `android.dark` (tanda putih di atas navy), yang aman.
   - iOS `project.pbxproj`: `developmentRegion = id` dan `knownRegions = (id, Base)` hanya ada di repo (tidak ada kunci
     `app.json` untuk itu); integrasi Pods, `PrivacyInfo.xcprivacy` di Resources, dan `Podfile` hasil tuning juga hanya
     ada di repo.
   - Android `build.gradle`: komentar tentang penandatanganan di blok `release`.
4. **Setelah menambah atau menaikkan dependensi native**: `cd ios && export LANG=en_US.UTF-8 && pod install`, commit
   `Podfile.lock`, lalu Gradle sync di Android Studio dan bangun ulang development build. Sekarang termasuk
   `expo-web-browser` (sheet pembayaran dalam aplikasi) dan `expo-file-system` (penanda pengenalan).
5. **Gerbang sebelum rilis**: `npm run check` (typecheck, format, warna), `npx expo-doctor`, dan
   [checklist QA rilis](store/README.md#checklist-qa-rilis). Catatan `expo-doctor` yang sudah diketahui dan bukan dari
   kode aplikasi: dua versi `react-native-screens` (satu bersarang di `expo-router`), regresi memori Hermes V1 pada
   SDK 56 (diselesaikan SDK 57), dan peringatan bahwa properti `app.json` tidak disinkronkan EAS karena `ios/` dan
   `android/` di-commit (itu sebabnya langkah 2 mencerminkannya dengan tangan).
6. **Isi nilai produksi**: `.env.production` (host https dan kontak dukungan), `lib/legal-content.json` (isi `[TODO ...]`, lalu `"draft": false`), `eas.json` `submit.production.ios` (`ascAppId`, `appleTeamId`).
   Sekali saja: `npx eas-cli init` menulis `expo.extra.eas.projectId` (dan `owner`) ke `app.json`; commit hasilnya.
7. **Build**: `npx eas-cli build -p all --profile production`. Profil `preview` membuat APK internal dan build simulator iOS.
8. **Submit**: `npx eas-cli submit -p ios --profile production` dan `npx eas-cli submit -p android --profile production`.
   Upload AAB pertama ke Play dilakukan manual lewat Console. Isi listing dan form toko dari [store/](store/README.md).

Ikon, splash, dan logo dibuat oleh `npm run assets:brand`; grafis Play oleh `npm run assets:store` (keduanya memakai
`jimp-compact`, devDependency). Jalankan ulang setelah sumber di `../public/images` berubah.

Build lokal `./gradlew assembleRelease` atau `bundleRelease` ditandatangani kunci **debug** dan tidak bisa diunggah ke
Play; pakai hanya untuk uji smoke. Penandatanganan toko dikelola EAS.
