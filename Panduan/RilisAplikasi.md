# Membangun dan merilis aplikasi Kasir & Owner (POS-20, POS-21, POS-23, D-154)

Panduan ini menjelaskan **apa yang sudah otomatis**, **apa yang tetap butuh akun atau sertifikat milik pemilik produk**, dan
**cara kerja pembaruan otomatis**. Untuk Google Play lihat juga `Panduan/RilisPlayStore.md`.

## 1. Pembangunan otomatis (GitHub Actions › "Bangun Aplikasi")

Berkas: `.github/workflows/BangunAplikasi.yml`. Berjalan manual (**Actions › Bangun Aplikasi › Run workflow**), pada tag
`kasir-v*` / `pemilik-v*`, dan pada pull request yang menyentuh folder `windows/`, `ios/`, `android/` atau berkas itu sendiri
(runner macOS/Windows berbayar, jadi tidak jalan di setiap push).

| Tugas | Runner | Hasil (artefak) | Bisa dipasang? |
|---|---|---|---|
| `windows-kasir` | windows-latest | `Payoung-Kasir-Windows-x.y.z.zip` (folder Release), `Payoung-Kasir-Pemasang-x.y.z.exe` (Inno Setup, per pengguna tanpa admin), `SHA256SUMS.txt` | **Ya.** Tanpa sertifikat, Windows SmartScreen memperingatkan "penerbit tidak dikenal" (Info lanjut › Tetap jalankan). |
| `ios` (Kasir, Owner) | macos-latest | `Payoung-…-iOS-tanpa-tanda-tangan.zip` (`Runner.app`) | **Tidak.** Hanya bukti bahwa proyek iOS terbangun. IPA yang bisa dipasang butuh akun Apple Developer (bagian 3). |
| `android` (Kasir, Owner) | ubuntu-latest | `Payoung-…-debug.apk` (uji sideload); `.aab` rilis hanya bila secret kunci unggah ada | APK debug **ya** (uji); AAB rilis hanya lewat Play Console. |

Setiap artefak disertai `SHA256SUMS.txt`. Artefak disimpan 14–30 hari.

## 2. Yang tetap butuh keputusan atau akun pemilik produk

| Kebutuhan | Kenapa | Secret / tindakan |
|---|---|---|
| **Sertifikat penandatangan kode Windows** (OV atau EV) | Menghilangkan peringatan SmartScreen; EV langsung dipercaya, OV membangun reputasi bertahap | Secret `WINDOWS_PFX_BASE64` (isi PFX di-base64) dan `WINDOWS_PFX_SANDI`; langkah penandatanganan sudah ada di workflow dan hanya jalan bila secret terisi |
| **Akun Apple Developer** (US$ 99/tahun) | Sertifikat distribusi + provisioning profile + App Store Connect untuk TestFlight/App Store | Belum ada langkah IPA bertanda tangan; ditambahkan setelah akun ada (fastlane atau `flutter build ipa` dengan secret) |
| **Kunci unggah Android** | Build rilis Android sengaja menolak kunci debug (audit F-04) | Secret `PAYOUNG_KEYSTORE_BASE64`, `PAYOUNG_KEYSTORE_PASSWORD`, `PAYOUNG_KEY_ALIAS`, `PAYOUNG_KEY_PASSWORD` |
| **Tempat menyimpan pemasang Windows** (D-02 belum diputuskan) | Pembaruan otomatis mengunduh dari alamat **https** yang stabil | Unggah `Payoung-Kasir-Pemasang-x.y.z.exe` ke hosting/CDN pilihan, lalu catat di konsol (bagian 4) |

## 3. iOS / iPadOS (POS-21)

Terbukti oleh CI: proyek iOS terkompilasi tanpa tanda tangan di macOS. **Belum terbukti:** tanda tangan, TestFlight, dan
perilaku di perangkat/simulator nyata (Bluetooth LE printer, kamera, biometrik). Langkah berikutnya setelah akun Apple
tersedia: sertifikat distribusi + profil provisioning sebagai secret, langkah `flutter build ipa`, unggah ke TestFlight.

## 4. Pembaruan otomatis (POS-23)

1. Bangun rilis (bagian 1), unggah `Payoung-Kasir-Pemasang-x.y.z.exe` ke alamat https.
2. Konsol Platform Pengelola › **Rilis aplikasi › Catat draf**: aplikasi *Pos*, platform *Windows*, versi `x.y.z`,
   **tautan unduh** dan **checksum SHA-256** (baris dari `SHA256SUMS.txt`; wajib untuk Windows). Terbitkan bertahap
   (rollout) seperti rilis lain; versi minimum wajib tetap bekerja seperti sebelumnya (BR-P10.1).
3. Aplikasi Kasir membaca `GET /api/pos/v1/konfigurasi-aplikasi` (±15 menit sekali). Bila ada versi baru, **Status sinkron**
   menampilkan kartu "Versi x.y.z tersedia" dan layar wajib-perbarui menampilkan kartu yang sama.
4. Di **Windows** dengan tautan https dan checksum, tombol **Unduh dan pasang** mengunduh pemasang, memeriksa SHA-256, lalu
   menjalankannya diam-diam (`/VERYSILENT /CLOSEAPPLICATIONS /RESTARTAPPLICATIONS`). Aplikasi menutup, diganti, dan terbuka
   lagi sendiri; basis data lokal dan antrean kirim tidak disentuh. Tombol dinonaktifkan selama ada transaksi belum terkirim.
5. **Android / iOS** memperbarui lewat toko aplikasi (Play Console / App Store); kartu hanya menampilkan tautan bila diisi.

**Pengaman:** hanya alamat https; unduhan yang SHA-256-nya tidak sama dengan yang dikirim server **tidak pernah
dijalankan** dan dihapus; batas ukuran 400 MB; tanpa checksum, pembaruan otomatis tidak ditawarkan (tautan manual saja).

**Batas yang jujur:** checksum dan tautan sama-sama datang dari server Payoung, jadi checksum melindungi dari berkas
rusak atau diganti di tempat penyimpanan, bukan dari server Payoung yang dibobol. Tanpa tanda tangan kode, Windows tidak
memeriksa asal pemasang; sertifikat (bagian 2) menutup celah itu. Pembaruan otomatis belum diuji di Windows sungguhan
oleh pengembang; yang diuji adalah logika unduh/checksum/peluncuran di tes otomatis dan pembangunan di CI.
