# Rilis Google Play: Payoung POS & Payoung Owner

Semua bahan untuk mengisi Play Console dua aplikasi. Aset gambar ada di `Spesifikasi/Merek/PlayStore/`;
teks di bawah siap ditempel. Bagian bertanda **[isi pemilik]** adalah data yang tidak boleh ditebak agent.

| | Payoung POS | Payoung Owner |
|---|---|---|
| ID paket | `id.payoung.kasir` | `id.payoung.pemilik` |
| Versi saat ini (`pubspec.yaml`) | `1.0.0+1` | `1.0.0+1` |
| Kategori | Bisnis | Bisnis |
| Tag | Kasir, POS, Bisnis kecil | Bisnis, Analitik |
| Harga | Gratis (langganan dibayar di dashboard web, bukan lewat Play) | Gratis |

## 1. Aset grafis

Buat ulang kapan saja (setelah UI berubah):

```bash
cd Aplikasi/Kasir   && flutter test AlatSitus/FotoPlayStore_test.dart   # ±30 detik
cd Aplikasi/Pemilik && flutter test AlatSitus/FotoPlayStore_test.dart
python3 Spesifikasi/Merek/PlayStore/BuatAsetPlayStore.py
```

| Slot Play Console | Payoung POS (`Kasir/`) | Payoung Owner (`Pemilik/`) |
|---|---|---|
| Ikon aplikasi 512×512 (PNG 32-bit) | `Ikon512.png` | `Ikon512.png` |
| Grafis fitur 1024×500 | `GrafikFitur.png` | `GrafikFitur.png` |
| Screenshot ponsel (2–8, 1080×2160) | `Hp/01…03*.jpg` | `Hp/01…06*.jpg` |
| Screenshot tablet 7 inci (1920×1200) | `Tablet7/01…06*.jpg` | tidak perlu (aplikasi ponsel) |
| Screenshot tablet 10 inci (2560×1600) | `Tablet10/01…06*.jpg` | tidak perlu |

Urutan nama berkas = urutan unggah. Data di tangkapan layar adalah data demo (toko "Kopi Senja"), dirender dari
widget asli aplikasi, bukan mockup.

## 2. Teks listing: Payoung POS

**Nama aplikasi** (≤ 30): `Payoung POS - Aplikasi Kasir`

**Deskripsi singkat** (≤ 80):
`Kasir offline untuk kafe, resto, toko, salon & laundry. Stok & laporan otomatis.`

**Deskripsi lengkap** (≤ 4000):

```
Payoung POS adalah aplikasi kasir untuk usaha Indonesia: kafe, restoran, toko ritel, grosir, salon, laundry, bengkel, dan apotek. Tetap bisa jualan saat internet putus; semua transaksi tersinkron otomatis begitu online lagi.

JUAL CEPAT
• Katalog per kategori, pencarian, dan pindai barcode (kamera atau scanner USB/Bluetooth)
• Bayar tunai dua ketukan, QRIS dinamis, kartu, transfer, tempo, deposit pelanggan
• Bagi tagihan, simpan pesanan, diskon dengan PIN supervisor
• Mode meja untuk restoran, dapur (KDS), nomor antrian, dan pesanan bawa pulang/antar

STRUK & PERANGKAT
• Printer thermal Bluetooth, USB, LAN, serta printer bawaan Sunmi/iMin
• Struk digital lewat WhatsApp, laci uang, layar pelanggan

SHIFT & KAS YANG RAPI
• Buka dan tutup shift dengan hitung pecahan uang
• Kas masuk/keluar tercatat beserta foto bukti
• Ringkasan omzet, metode bayar, produk terlaris, dan penjualan per jam

RETUR & KONTROL
• Riwayat transaksi lengkap, void, retur, dan tukar barang dengan PIN penyetuju
• Hak akses per peran: kasir, supervisor, pelayan, salesman

TERHUBUNG KE DASHBOARD Payoung
Stok, jurnal akuntansi, laporan laba rugi, pelanggan & poin, promo, dan karyawan dikelola di dashboard web Payoung. Pemilik bisa memantau semuanya dari aplikasi Payoung Owner.

Aplikasi ini memerlukan akun usaha Payoung. Daftar di payoung.id, lalu aktifkan perangkat kasir dengan kode atau QR dari dashboard.
```

**Catatan rilis 1.0.0**:
`Rilis pertama Payoung POS: jual offline, QRIS, printer Bluetooth/USB/LAN, shift & kas, retur, mode meja.`

## 3. Teks listing: Payoung Owner

**Nama aplikasi** (≤ 30): `Payoung Owner - Pantau Toko`

**Deskripsi singkat** (≤ 80):
`Pantau omzet, shift, stok, dan karyawan semua outlet Payoung dari HP Anda.`

**Deskripsi lengkap**:

```
Payoung Owner adalah aplikasi pendamping untuk pemilik usaha yang memakai Payoung POS. Lihat kondisi toko kapan saja tanpa harus datang ke outlet.

• Omzet hari ini semua outlet, dibanding kemarin dan minggu lalu
• Laporan penjualan per jam, per outlet, dan produk terlaris
• Setujui diskon, void, kas keluar, dan penjualan tempo dari jauh
• Pantau shift yang sedang buka dan selisih kas saat tutup
• Kehadiran karyawan, komisi, dan target bulan berjalan
• Insight mingguan: tren penjualan dan saran restock
• Notifikasi penting langsung ke HP

Masuk dengan akun pemilik usaha Payoung yang sama dengan dashboard web.
```

**Catatan rilis 1.0.0**: `Rilis pertama Payoung Owner: dasbor omzet, laporan, persetujuan jarak jauh, shift, karyawan, insight.`

## 4. Detail kontak & kebijakan

| Isian | Nilai |
|---|---|
| Situs web | `https://payoung.id` |
| Email dukungan | **[isi pemilik]** (wajib, tampil publik) |
| Telepon / WhatsApp | **[isi pemilik]** (opsional) |
| Kebijakan privasi | `https://payoung.id/legal/kebijakan-privasi` (P-06; pastikan versi berlaku sudah diterbitkan di konsol) |

## 5. Akses aplikasi (untuk peninjau Google)

Kedua aplikasi butuh akun, jadi pilih "Semua atau sebagian fungsi dibatasi" dan siapkan **tenant demo khusus peninjau**
(jangan akun toko sungguhan):

- **Payoung POS**: buat perangkat kasir di tenant demo, tulis kode aktivasi + PIN kasir demo di instruksi.
  Kode aktivasi sekali pakai, jadi buat yang baru setiap kali kirim ulang ke peninjauan. **Bawaan kode hanya berlaku
  15 menit**, terlalu singkat untuk peninjau Google: sebelum membuat kode, set `MENIT_BERLAKU_KODE_AKTIVASI=10080` (7 hari)
  di `.env` server produksi, jalankan `php artisan config:clear` (atau `optimize` ulang bila konfigurasi di-cache), baru
  buat kodenya di Kelola › Perangkat. Jangan dipakai untuk uji sendiri (sekali pakai). Setelah aplikasi disetujui,
  kembalikan ke `15`. Membuat kode baru membatalkan kode lama perangkat itu.
- **Payoung Owner**: email + kata sandi pemilik tenant demo, 2FA dimatikan untuk akun ini. Peninjau memakai email + kata sandi; tombol "Masuk dengan Google" (D-57) boleh tetap ada tetapi jangan jadi satu-satunya jalan masuk, dan cantumkan di instruksi bahwa masuk Google bersifat opsional (panduan: `Panduan/LoginGoogle.md`).

Kredensial hanya diisi di Play Console, **tidak** di repo atau dokumen ini.

## 6. Keamanan data (Data safety)

Jawaban umum kedua aplikasi:
- Mengumpulkan/membagikan data: **Ya** mengumpulkan; **Tidak** membagikan ke pihak ketiga (server Payoung adalah pemroses
  data milik toko; gerbang QRIS & WhatsApp dipanggil server atas nama toko, bukan oleh aplikasi).
- Dienkripsi saat transit: **Ya** (HTTPS). Basis data lokal kasir juga terenkripsi (K-7).
- Permintaan penghapusan data: **Ya**, lewat dukungan Payoung / pemilik toko (data milik tenant).
- Iklan: **Tidak ada**. SDK analitik/iklan pihak ketiga: **tidak ada**.

### Payoung POS

| Jenis data | Dikumpulkan | Tujuan | Wajib? |
|---|---|---|---|
| Nama (staf, pelanggan) | Ya | Fungsi aplikasi, manajemen akun | Wajib |
| Nomor telepon (pelanggan) | Ya | Fungsi aplikasi (struk WA, poin) | Opsional |
| Alamat (pelanggan grosir/salesman, antar) | Ya | Fungsi aplikasi | Opsional |
| Riwayat pembelian | Ya | Fungsi aplikasi, analitik toko | Wajib |
| Foto (swafoto absensi, bukti kas) | Ya | Fungsi aplikasi | Opsional |
| Lokasi persis | Ya (kunjungan salesman, absensi) | Fungsi aplikasi | Opsional |
| Log error / diagnostik | Ya (dikirim ke server Payoung, PII disaring) | Analitik, perbaikan | Wajib |
| ID perangkat (ID perangkat kasir Payoung) | Ya | Keamanan, fungsi | Wajib |

Informasi pembayaran pelanggan (kartu/rekening) **tidak** dikumpulkan; QRIS diproses gerbang pembayaran milik toko.

Izin yang dijelaskan saat diminta: Kamera (pindai barcode, swafoto absensi, foto bukti), Perangkat sekitar /
Bluetooth (printer & laci), Lokasi (kunjungan salesman, absensi).

### Payoung Owner

| Jenis data | Dikumpulkan | Tujuan | Wajib? |
|---|---|---|---|
| Email, nama | Ya | Manajemen akun | Wajib |
| Riwayat pembelian (agregat laporan toko) | Ya | Fungsi aplikasi | Wajib |
| ID perangkat (token push Firebase) | Ya | Notifikasi | Opsional |

Firebase Cloud Messaging hanya untuk notifikasi push; tidak ada analitik Firebase.

## 7. Rating konten & audiens

- Kuesioner IARC kategori **Utilitas, Produktivitas, Komunikasi, atau Lainnya**: semua jawaban "Tidak"
  (tanpa kekerasan, konten seksual, perjudian, interaksi pengguna, berbagi lokasi ke pengguna lain, pembelian digital).
  Hasil yang diharapkan: **Semua umur / PEGI 3**.
- Target usia: **18 tahun ke atas** (aplikasi bisnis). Tidak menarik bagi anak.
- Aplikasi berita: Tidak. Aplikasi pemerintah: Tidak. Fitur keuangan: **Tidak** (bukan pinjaman/dompet; hanya
  mencatat pembayaran toko).
- Iklan: Tidak.

## 8. Build & tanda tangan

1. Buat keystore unggah sekali, simpan di pengelola rahasia (bukan repo):
   `keytool -genkey -v -keystore payoung-unggah.jks -keyalg RSA -keysize 2048 -validity 10000 -alias payoung`
2. Isi `android/key.properties` (diabaikan Git) atau variabel `PAYOUNG_KEYSTORE_FILE`, `PAYOUNG_KEYSTORE_PASSWORD`,
   `PAYOUNG_KEY_ALIAS`, `PAYOUNG_KEY_PASSWORD`. Build rilis sengaja gagal bila kunci kosong.
3. Aktifkan **Play App Signing** (Google memegang kunci aplikasi; keystore di atas = kunci unggah).
4. Build AAB dengan alamat server produksi:
   ```bash
   cd Aplikasi/Kasir   && flutter build appbundle --release -t lib/UtamaProduksi.dart --dart-define=ALAMAT_SERVER=https://dashboard.payoung.id/
   cd Aplikasi/Pemilik && flutter build appbundle --release -t lib/UtamaProduksi.dart --dart-define=ALAMAT_SERVER=https://dashboard.payoung.id/
   ```
   Hasil: `build/app/outputs/bundle/release/app-release.aab`. Setiap unggahan wajib menaikkan angka build (`+N`).
5. Pemilik: siapkan `google-services.json` proyek Firebase produksi (tidak di repo) sebelum build.

### Memperbarui build yang sudah diunggah (pengujian tertutup)

1. `git pull origin main`; pastikan `Aplikasi/Kasir/android/key.properties` dan `payou-release.jks` ada (keystore unggah yang sama).
2. Naikkan **kode build** di `Aplikasi/Kasir/pubspec.yaml` (sekarang `1.0.0+3`): `version: 1.0.0+N`, dengan N lebih besar dari kode versi unggahan terakhir
   (lihat Uji dan rilis › Pengujian tertutup › rincian rilis, kolom Versi, mis. "1 (1.0.0)"). Biarkan nama versi `1.0.0` selama
   `versiAplikasi` di `Penyedia.dart` masih tertanam `'1.0.0'`: nilai itu dilaporkan ke server (P-10), jadi menaikkan nama versi di
   `pubspec.yaml` saja membuat Play Console dan server menampilkan versi berbeda.
3. Bangun: `cd Aplikasi/Kasir && flutter build appbundle --release -t lib/UtamaProduksi.dart --dart-define=ALAMAT_SERVER=https://dashboard.payoung.id/`
   (±1,5 menit). Hasil: `Aplikasi/Kasir/build/app/outputs/bundle/release/app-release.aab`.
4. Play Console › Uji dan rilis › Pengujian tertutup › track (mis. Alpha) › **Buat rilis baru** › unggah AAB › isi catatan rilis ›
   Berikutnya › Simpan › kirim untuk ditinjau.
5. Setelah disetujui, penguji menerima pembaruan lewat Play Store; ID aplikasi dan kunci sama, jadi aktivasi perangkat & data lokal tetap.
   Perubahan yang hanya di aplikasi tidak perlu deploy server. Aplikasi Pemilik tidak perlu diunggah ulang bila tidak berubah.
6. Galat umum: "Kode versi N sudah digunakan" (kode build belum naik); build gagal soal kunci (`key.properties`/keystore hilang);
   "lib/main.dart not found" (lupa `-t lib/UtamaProduksi.dart`); unggahan ditolak karena tanda tangan (keystore berbeda).

## 9. Urutan rilis yang disarankan

1. **Pengujian internal** (≤ 100 penguji, tanpa peninjauan panjang): uji printer, QRIS, offline di 2–3 perangkat asli.
2. **Pengujian tertutup** 14 hari dengan ≥ 12 penguji. Ini syarat Google untuk akun developer pribadi baru;
   akun organisasi (D-U-N-S) tidak terkena.
3. **Produksi** bertahap 10% → 50% → 100% (P-10 rilis bertahap di konsol bisa dipakai bersamaan).
4. Setelah tayang, daftarkan versi di konsol P-10 (versi minimum & catatan rilis) agar kasir lama diminta memperbarui.

## 10. Langkah praktis di Play Console (akun pribadi baru)

Diverifikasi 10 Okt 2026: AAB rilis Kasir (83,6 MB) dan Pemilik (55,3 MB) berhasil dibangun dan ditandatangani kunci unggah
(target SDK 36, izin `INTERNET` masuk lewat plugin). Tanpa `-t lib/UtamaProduksi.dart`, build gagal ("lib/main.dart not found").

1. **Selesaikan penyiapan aplikasi** (Dasbor › Selesaikan penyiapan): listing (§2), grafis (§1), kontak & privasi (§4),
   akses aplikasi (§5), data safety (§6), rating & audiens (§7).
2. **Uji dan rilis › Pengujian tertutup › Buat track**: buat daftar penguji (daftar email Google, ≥ 12 orang), unggah AAB,
   isi catatan rilis, kirim. Salin **tautan opt-in** dan bagikan ke penguji.
3. Penguji harus **menerima undangan (opt-in) lalu memasang dari Play Store dan tetap ikut sampai 14 hari penuh**. Hitungan
   14 hari baru berjalan saat ≥ 12 penguji sudah ikut serta; pantau di Dasbor › Produksi ("0 penguji saat ini ikut serta").
   Beri margin: ajak 15–20 orang karena sebagian bisa keluar.
4. Setelah syarat terpenuhi, tombol **Ajukan permohonan untuk produksi** aktif; jawab kuesioner (cara mencari penguji,
   umpan balik yang diterima, rencana perbaikan). Simpan catatan umpan balik penguji selama 14 hari sebagai bahan jawaban.
5. Pengujian internal (opsional, bisa paralel) tidak dihitung untuk syarat ini.

Contoh pesan ajakan penguji:

```
Halo, saya sedang menyiapkan aplikasi kasir Payoung POS. Boleh bantu jadi penguji? Caranya: (1) kirim email Google Anda
ke saya, (2) buka tautan ini di HP Android: <tautan opt-in>, (3) klik "Jadi penguji" lalu pasang aplikasinya dari Play Store,
(4) biarkan terpasang minimal 14 hari dan sesekali dibuka. Terima kasih!
```

**Tenant demo untuk peninjau** (bukan seeder lokal `DataDemoLokal`, yang dilarang jalan di produksi): daftar sungguhan di
`payoung.id/daftar` memakai email khusus peninjau, pilih sektor yang datanya paling mudah dipahami (mis. FnB), selesaikan
panduan awal, buat perangkat kasir + PIN demo, lalu tempel kode aktivasi di instruksi akses aplikasi. Untuk Owner, matikan
2FA di akun demo itu.

**Tenant untuk tim penguji** (v5.19): bila penguji perlu mencoba aplikasi sungguhan, buat tenant khusus lewat seeder, bukan akun toko sungguhan:

```bash
cd ~/domains/payoung.id/aplikasi/Aplikasi/Web
export PENGUJI_EMAIL=...  PENGUJI_NO_HP=08...  PENGUJI_KATA_SANDI=...  PENGUJI_PIN=...   # atau biarkan kosong, nanti ditanya
php artisan db:seed --class=TimPengujiSeeder --force
```

Seeder mencetak kode aktivasi tiap perangkat sekali ("Penguji 1" dst.), jadi salin saat itu juga. Bawaannya 50 perangkat
(`PENGUJI_JUMLAH_PERANGKAT` untuk mengubah). Karena paket Pro hanya 5 per outlet, seeder memasang override batas perangkat selama
60 hari untuk tenant itu (butuh akun pengelola; tercatat di audit). Naikkan `MENIT_BERLAKU_KODE_AKTIVASI` dulu bila kode dibagikan jauh hari. Kode baru: Kelola › Perangkat.

**Catatan teknis**
- Payoung Owner dibangun tanpa `google-services.json`, jadi push notification **tidak aktif** di AAB itu. Letakkan berkasnya di
  `Aplikasi/Pemilik/android/app/` (tidak di-commit) sebelum build bila push ingin ikut rilis.
- Kedua aplikasi memakai keystore yang sama (`payou-release.jks`; pemilik sertifikat tertulis "Payou"). Itu tidak masalah
  untuk kunci unggah, tetapi **cadangkan keystore + sandi di luar komputer ini**: kunci unggah hilang berarti harus minta reset ke Google.
- Setiap unggahan ulang wajib menaikkan angka build (`version: 1.0.0+N` di `pubspec.yaml`).

## Daftar periksa

- [ ] Email dukungan & kebijakan privasi terbit di `payoung.id/legal/kebijakan-privasi`
- [ ] Tenant demo + kode aktivasi + akun Owner untuk peninjau
- [x] Versi Pemilik dinaikkan ke 1.0.0, angka build naik tiap unggah
- [ ] Keystore unggah dicadangkan di luar komputer + Play App Signing aktif (keystore sudah ada, lihat §10)
- [x] AAB dibangun dengan `ALAMAT_SERVER` produksi (dibangun ulang tiap unggahan; Kasir & Pemilik terverifikasi 10 Okt 2026)
- [ ] Ikon, grafis fitur, screenshot ponsel & tablet diunggah
- [ ] Data safety, rating konten, audiens, iklan, akses aplikasi terisi
- [ ] Uji internal di perangkat asli (printer Bluetooth, kamera, QRIS, offline) lulus
