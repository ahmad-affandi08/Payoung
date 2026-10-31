# Payoung

POS SaaS multi-sektor untuk usaha di Indonesia: retail, F&B, jasa, grosir, apotek, bengkel, laundry, dan usaha perusahaan tanpa kasir. Satu sistem untuk kasir (online & offline), stok, pembelian, akuntansi otomatis, pajak, pelanggan, karyawan, dan laporan, lengkap dengan Platform Pengelola untuk tim internal.

> **Sumber kebenaran produk adalah [`PRD.md`](PRD.md).** Potongannya (flow, bagian PRD, keputusan) ada di [`Dokumen/`](Dokumen/Indeks.md) dan **dibuat otomatis** dari `PRD.md`, jangan diedit langsung. Aturan kerja pengembang & AI agent ada di [`CLAUDE.md`](CLAUDE.md) dan [`.claude/rules/`](.claude/rules). Berkas ini hanya pintu masuk untuk programmer: cara menjalankan, struktur kode, konvensi, pengujian, dan rilis.

---

## Daftar isi

1. [Gambaran produk](#gambaran-produk)
2. [Arsitektur & struktur repo](#arsitektur--struktur-repo)
3. [Teknologi & versi](#teknologi--versi)
4. [Menjalankan secara lokal](#menjalankan-secara-lokal)
5. [Konfigurasi lingkungan (`.env`)](#konfigurasi-lingkungan-env)
6. [Routing, domain, dan API](#routing-domain-dan-api)
7. [Database & migrasi](#database--migrasi)
8. [Antrean, jadwal, dan perintah artisan](#antrean-jadwal-dan-perintah-artisan)
9. [Pengujian & pengecekan kualitas](#pengujian--pengecekan-kualitas)
10. [Konvensi wajib](#konvensi-wajib)
11. [Alur kerja pengembangan](#alur-kerja-pengembangan)
12. [Resep: menambah fitur](#resep-menambah-fitur)
13. [CI/CD](#cicd)
14. [Deploy & rilis](#deploy--rilis)
15. [Masalah yang sering muncul](#masalah-yang-sering-muncul)
16. [Status pengembangan](#status-pengembangan)
17. [Dokumentasi lanjutan](#dokumentasi-lanjutan)
18. [Lisensi](#lisensi)

---

## Gambaran produk

| Aplikasi | Pengguna | Platform |
|---|---|---|
| **Back-office web** (`/kelola`) | Pemilik, admin, manajer outlet, akuntan, staf gudang, karyawan (absensi) | Peramban, responsif 360px s.d. layar lebar |
| **Aplikasi Kasir (POS)** | Kasir, supervisor, pelayan, salesman, apoteker | Android, iOS/iPadOS, Windows. Offline-first (minimal 72 jam tanpa internet) |
| **Aplikasi Pemilik** | Pemilik usaha | Android, iOS (dasbor, laporan, persetujuan jarak jauh, notifikasi push) |
| **Platform Pengelola** (konsol) | Tim internal Payoung | Peramban, akun & guard terpisah, 2FA |
| **Web publik** | Calon pelanggan, pembeli, tamu | Situs pemasaran, toko online, self-order QR meja, kios, reservasi online, struk digital, invoice, portal kurir, absen HP |
| **Open API** (`/api/v1`) | Integrator pihak ketiga | REST + webhook keluar, token API bercakupan |

**Dua edisi dari satu kode** (variabel `EDISI`):

- **`Saas`** (Payoung Cloud, bawaan): multi-tenant, ada Platform Pengelola, situs pemasaran, pendaftaran, langganan, dan penagihan.
- **`Lisensi`** (Payoung Mandiri): sekali beli, dipasang di server & domain pembeli, satu usaha, semua fitur, tanpa konsol/langganan/situs. Berkas lisensi Ed25519 offline. Lihat [`Panduan/InstalasiLisensi.md`](Panduan/InstalasiLisensi.md).

Kemampuan utama (daftar rinci per flow ada di PRD):

- **Penjualan & pembayaran**: harga bertingkat, daftar harga per kanal, pilihan/modifier, promo & voucher, pajak inklusif/eksklusif (PPN DPP 11/12, PB1/PBJT), biaya layanan, ongkir, pembulatan tunai, split payment (tunai, QRIS statis/dinamis, EDC, transfer, e-wallet, deposit, gift card, tempo), mode meja & dapur (KDS), pre-order, grosir (SO, surat jalan, faktur), kanvas & salesman.
- **Pasca-penjualan**: void, retur, tukar barang, retur tanpa struk, anti-fraud.
- **Shift & kas**: buka/tutup shift, kas masuk/keluar, tutup buta, rekonsiliasi selisih, laporan X/Z.
- **Persediaan**: ledger `MutasiStok` append-only, HPP rata-rata, batch & kedaluwarsa (FEFO), nomor seri/IMEI, transfer, opname per rak, produksi/rakitan, konsinyasi, biaya tambahan pembelian.
- **Pembelian**: pemasok, permintaan pembelian, PO bertingkat persetujuan, penerimaan, faktur, hutang, retur, nota debit.
- **Akuntansi otomatis**: setiap peristiwa keuangan menghasilkan jurnal seimbang di transaksi yang sama; bagan akun, buku besar, laba rugi, neraca, arus kas, tutup harian/bulan/tahun, aset tetap, rekonsiliasi bank, anggaran, jurnal umum.
- **Pelanggan & karyawan**: CRM, tier, poin, deposit, paket sesi, kampanye WhatsApp, piutang, invoice B2B, absensi (wajah + GPS), jadwal, gaji, komisi, kasbon.
- **Platform Pengelola**: tim internal, master regulasi, template sektor, paket & harga, langganan & tagihan (lama langganan bebas, D-249), integrasi pembayaran/WhatsApp/email, dokumen legal, tiket dukungan & obrolan, akses dukungan berizin, monitoring & insiden, analitik, mitra & referral.

---

## Arsitektur & struktur repo

Repo ini adalah **monorepo**: satu server Laravel, dua aplikasi Flutter, paket Dart bersama, spesifikasi lintas bahasa, dan dokumen produk.

### Peta folder di akar

| Folder / berkas | Isinya |
|---|---|
| `Aplikasi/Web/` | **Server utama** (Laravel 13): API POS/Pemilik/publik, back-office Inertia React (`/kelola`), Platform Pengelola, situs pemasaran, halaman publik, migrasi, test PHP. |
| `Aplikasi/Kasir/` | Aplikasi POS Flutter (Android, iOS/iPadOS, Windows). Titik masuk: `UtamaDev.dart`, `UtamaStaging.dart`, `UtamaProduksi.dart`. |
| `Aplikasi/Pemilik/` | Aplikasi Owner Flutter (Android, iOS), struktur sama dengan Kasir. |
| `Paket/Inti/` | Tipe nilai uang & kuantitas (tanpa float), ULID. Dart murni. |
| `Paket/MesinKasir/` | Mesin kalkulasi harga, pajak, diskon, promo, ongkir, pembulatan. Dart murni, **kembaran** `App\Domain\Penjualan\Kalkulasi` di PHP. |
| `Paket/KlienApi/` | Klien HTTP ke API server. |
| `Paket/SistemDesain/` | Token (warna, ukuran, font) dan komponen visual bersama. |
| `Paket/AdaptorPerangkat/` | Abstraksi perangkat keras: printer (ESC/POS: LAN, Bluetooth, USB, bawaan Sunmi/iMin, sistem), laci kas, layar pelanggan, pemindai. |
| `Spesifikasi/` | Kontrak lintas bahasa: `VektorUjiKalkulasi/` (harga/pajak/promo), `VektorUjiPin/`, `VektorUjiQris/` (wajib identik di PHP & Dart), `KontrakApi/RuteApi.json` (gerbang kontrak rute), `UjiBeban/` (k6), `Merek/` (aset & skrip pembuat aset merek). |
| `Alat/` | Penjaga & skrip proyek (terlindungi): `CekKonvensi.py`, `PecahPrd.py`, `Perbarui.sh`, `KonvensiPengecualian.json`. |
| `Panduan/` | Panduan operasional & teknis (lihat [dokumentasi lanjutan](#dokumentasi-lanjutan)). |
| `Dokumen/` | Potongan `PRD.md` hasil generate (flow, bagian, keputusan, indeks). **Jangan diedit langsung.** |
| `PRD.md` | Dokumen kebutuhan produk lengkap, sumber kebenaran. Jangan dibaca utuh, baca potongannya lewat `Dokumen/Indeks.md`. |
| `CLAUDE.md`, `.claude/` | Aturan kerja wajib, aturan per stack (`rules/`), skill (`skills/`), hook penjaga (`hooks/`), agen peninjau (`agents/`). |
| `.github/` | CI (`workflows/`), `CODEOWNERS`, `dependabot.yml`, template PR. |
| `pubspec.yaml`, `pubspec.lock`, `analysis_options.yaml` | Ruang kerja Dart (satu lockfile di akar), skrip melos, aturan analisis. |

### Isi `Aplikasi/Web/`

| Folder | Isinya |
|---|---|
| `app/Domain/{Domain}/` | **Logika bisnis** per domain (lihat tabel di bawah). Tiap domain: `Aksi/` (use case mengubah data), `Kueri/` (baca), `Layanan/`, `Model/`, `Data/`, `Enum/`, `Peristiwa/`, `Penangan/`, `Kebijakan/`. |
| `app/Http/` | `Kontroler/` (tipis), `Permintaan/` (validasi), `Perantara/` (middleware), `Respons/`. |
| `app/Console/Perintah/` | Perintah artisan proyek (~75, lihat [bagian perintah](#antrean-jadwal-dan-perintah-artisan)). |
| `routes/` | `web.php` + satu berkas per modul (`Penjualan.php`, `Akuntansi.php`, …), `Pos.php` (API kasir), `Pemilik.php` (API Owner), `ApiPublik.php` (Open API), `Webhook.php`, `Pengelola.php`, `console.php` (jadwal). |
| `database/migrations/` | Migrasi (pola expand → contract). `database/seeders/` berisi data awal & data demo. |
| `resources/js/` | Tampilan Inertia + React: `Halaman/` (satu berkas per layar), `Komponen/` (termasuk `TabelData`, `Formulir`, `Tanggal`, `Ui` shadcn), `TataLetak/`, `Pustaka/`, `Tipe/`, `Gaya/`. |
| `tests/` | `Unit/`, `Fitur/` (MySQL), `Arsitektur/`, `Konkurensi/` (dua proses), `Beban/` (k6), `Pendukung/` (pembantu). |
| `config/`, `bootstrap/`, `public/`, `storage/`, `lang/` | Standar Laravel. `public/model-wajah` (model absensi wajah), `public/pengembang` (OpenAPI), `public/build` (aset Vite). |

### Domain server

| Domain | Cakupan |
|---|---|
| `Penjualan`, `Kasir` | Transaksi, kalkulasi, void/retur, shift, kas, invoice B2B, grosir |
| `Persediaan`, `Pembelian` | Ledger stok, HPP, transfer, opname; PO, penerimaan, hutang |
| `Akuntansi`, `Pajak` | Jurnal, bagan akun, laporan keuangan, tutup periode; tarif pajak bertanggal |
| `Katalog`, `Promo`, `Pelanggan` | Produk, harga, resep, template; promo & voucher; CRM, loyalti, deposit |
| `Organisasi`, `Karyawan` | Tenant-user, outlet, perangkat, peran & izin; absensi, jadwal, gaji |
| `Pemenuhan`, `Bengkel` | Meja, dapur, kurir, reservasi, laundry; sektor bengkel & apotek |
| `Tenant`, `PanduanAwal`, `Lisensi` | Langganan & tagihan, pendaftaran; panduan awal & template sektor; edisi Lisensi |
| `Integrasi`, `Laporan`, `Dukungan` | Gerbang pembayaran, WhatsApp, email, webhook; laporan; tiket & obrolan |
| `Pengelola`, `Situs`, `Referensi`, `Bersama` | Platform Pengelola (lintas tenant); situs pemasaran; wilayah/bank; kode bersama (nilai `Uang`, audit, galat, dokumen) |

### Isi `Aplikasi/Kasir/` & `Aplikasi/Pemilik/`

| Folder | Isinya |
|---|---|
| `lib/Tampilan/` | Layar. Kasir: `RuangKerja/` adalah bingkai utama (bilah atas, rel navigasi, area kerja, bilah status). |
| `lib/Domain/` | Logika (keranjang, penjualan, shift, sinkron, PIN, katalog, apotek, bengkel, …). |
| `lib/Data/` | SQLite via Drift (terenkripsi SQLite3 Multiple Ciphers), repositori, outbox. |
| `lib/Aplikasi/` | Penyedia state (Riverpod), pengaturan lingkungan (`ALAMAT_SERVER`). |
| `test/` | Unit, widget, golden, migrasi skema lokal. |

### Prinsip arsitektur

- **Modular monolith, domain-driven.** Antar-domain hanya lewat Aksi/Layanan publik atau Peristiwa, dijaga test arsitektur (`tests/Arsitektur`).
- **Multi-tenant.** Setiap tabel tenant memakai scope `MilikTenant` (kolom `IdTenant`). Melewati scope hanya di `Domain/Pengelola` lewat `KonteksPengelola::JalankanLintasTenant()`. Detektor `tenant:periksa-silang` berjalan mingguan.
- **Stok & jurnal adalah turunan peristiwa.** Ditulis hanya lewat `CatatMutasiStok` dan `PostingJurnal`, **sinkron di transaksi DB yang sama** dengan dokumennya. Hanya efek non-kritis (notifikasi, webhook, ringkasan laporan) lewat antrean.
- **Dokumen terposting tidak diedit/dihapus.** Koreksi lewat dokumen pembalik (void, retur, penyesuaian).
- **Offline-first POS.** Dokumen dibuat di perangkat (ULID sebagai `UuidKlien`, nomor dokumen berkode perangkat), disimpan bersama entri outbox dalam satu transaksi SQLite, dikirim FIFO ke `POST /api/pos/v1/sinkron/kirim` secara idempoten (`Idempotency-Key`). Hal yang melanggar aturan saat offline diterima server sebagai **tinjauan** (`PerluTinjauan`), bukan ditolak.
- **Satu algoritma, dua implementasi.** Kalkulasi harga/pajak/promo ada di PHP dan Dart, keduanya wajib lolos vektor uji yang sama di `Spesifikasi/`.
- **Uang dan kuantitas bukan float.** PHP `Uang`/`Kuantitas` (brick/math), Dart `decimal`, DB `DECIMAL`. Pembulatan selalu eksplisit.
- **Tarif, harga, batas paket, dan aturan bisnis tidak di-hard-code.** Datang dari tabel bertanggal berlaku (`TarifPajak`, `HargaPaket`) atau pengaturan konsol (`PengaturanPlatform`) dengan nilai awal di `config/`.

---

## Teknologi & versi

| Lapisan | Teknologi |
|---|---|
| Server | PHP 8.3, Laravel 13, MySQL 8 (MariaDB 11.8 dipantau di CI), Sanctum, antrean database, brick/math, OpenSpout (Excel), pragmarx/google2fa |
| Back-office & situs | Inertia.js 3, React 19, TypeScript strict, Tailwind CSS 4, shadcn/ui (Radix, Base UI), TanStack Query & Table, react-hook-form + zod, Recharts, Vite |
| Absensi wajah (web) | `@vladmandic/human` (model di `public/model-wajah`) |
| Aplikasi mobile/desktop | Flutter 3.47 (CI), Dart ≥ 3.13, Riverpod 3, Drift (SQLite3 Multiple Ciphers), paket `decimal`, melos 7.8 |
| Kualitas | Pest (termasuk `arch()`), Larastan/PHPStan, Pint, Vitest, ESLint, Prettier, `flutter analyze`, k6 (uji beban) |
| Tipografi & merek | Atkinson Hyperlegible Next (UI) & Mono, palet Payoung (Muted Teal & Apricot) lewat token desain |

---

## Menjalankan secara lokal

### Prasyarat

| Perangkat lunak | Versi | Untuk |
|---|---|---|
| PHP + Composer | PHP 8.3, Composer 2 | Server |
| MySQL | 8.x | Database (dev **dan** test; SQLite tidak dipakai) |
| Node.js + npm | 22 | Tampilan back-office |
| Flutter | 3.47+ (Dart ≥ 3.13) | Aplikasi Kasir/Pemilik (opsional) |
| Python 3, Git | terbaru | Skrip penjaga, kode |

Ekstensi PHP: `bcmath`, `ctype`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.

### A. Server & back-office

```bash
git clone https://github.com/ahmad-affandi08/Payoung.git
cd Payoung/Aplikasi/Web
```

**1. Database.** Buat database dev dan database test (suite Pest berjalan di MySQL sungguhan):

```sql
CREATE DATABASE PosSaas    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE PosSaasTes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pos_saas'@'localhost' IDENTIFIED BY 'sandi_anda';
GRANT ALL PRIVILEGES ON PosSaas.*    TO 'pos_saas'@'localhost';
GRANT ALL PRIVILEGES ON PosSaasTes.* TO 'pos_saas'@'localhost';
FLUSH PRIVILEGES;
```

**2. Dependensi & lingkungan.**

```bash
composer install
cp .env.example .env
php artisan key:generate
# isi DB_PASSWORD di .env (nama database & user bawaan sudah PosSaas / pos_saas)
npm install
```

**3. Tabel & data awal.**

```bash
php artisan migrate --seed
```

Seeder mengisi peran tim internal, satuan standar, wilayah, tarif pajak (draf), katalog paket/harga, dan template sektor bawaan. Untuk menerbitkan template sektor lengkap sebagai data terbit: `php artisan template:terapkan`.

**4. Jalankan.**

```bash
composer dev
```

Satu perintah menjalankan server Laravel (`http://localhost:8000`), pekerja antrean, penampil log, dan Vite (muat ulang otomatis). Biarkan terminal terbuka.

Jalan pintas pertama kali: `composer setup` (install, `.env`, key, migrate, npm install, build).

**5. Buat akun usaha (tenant).**

1. Buka `http://localhost:8000/daftar` dan isi formulir (CAPTCHA dilewati di lokal).
2. Email tidak benar-benar dikirim (`MAIL_MAILER=log`): tautan verifikasi ada di `storage/logs/laravel.log`.
3. Masuk di `/masuk` → panduan awal → back-office `/kelola`. Di panduan awal, "Siapkan semuanya otomatis" membuat produk, pajak, dan metode bayar contoh.

**Data demo cepat (opsional, hanya lokal):**

```bash
php artisan db:seed --class=DataDemoLokal
```

Membuat tiga Super Admin demo (`admin@payoung.test`, dst.), dokumen legal, paket, dan satu tenant trial. Kata sandi dari env `DEMO_KATA_SANDI`; bila kosong dibuat acak dan dicetak sekali ke layar. **Jangan dijalankan di produksi.** (`TimPengujiSeeder` aman di produksi, tetapi khusus tim penguji Google Play, kredensial lewat env `PENGUJI_*`.)

### B. Platform Pengelola (konsol tim internal)

```bash
php artisan pengelola:buat-super-admin
```

Buka `http://pengelola.localhost:8000` (peramban modern mengarahkan `*.localhost` ke komputer Anda). Saat masuk pertama kali Anda diminta mengaktifkan 2FA (aplikasi authenticator). Aksi berbahaya meminta kode 2FA baru (`WajibDuaFaktorBaru`).

### C. Aplikasi Kasir (opsional)

```bash
# dari akar repo, sekali saja
dart pub get
# opsional: alat monorepo
dart pub global activate melos 7.8.2
```

1. Di back-office siapkan produk & metode bayar, lalu **Perangkat → buat perangkat** untuk mendapat **kode aktivasi**, dan atur PIN kasir.
2. Jalankan, arahkan ke server lokal lewat `ALAMAT_SERVER`:

```bash
cd Aplikasi/Kasir

flutter run -t lib/UtamaDev.dart                                          # emulator Android (alamat bawaan emulator)
flutter run -t lib/UtamaDev.dart --dart-define=ALAMAT_SERVER=http://127.0.0.1:8000        # Windows / simulator iOS
flutter run -t lib/UtamaDev.dart --dart-define=ALAMAT_SERVER=http://192.168.1.10:8000     # HP fisik (server: php artisan serve --host=0.0.0.0)
```

3. Di aplikasi: kode aktivasi → pilih kasir & PIN → buka shift → layar Jual.

Aplikasi Pemilik sama: `cd Aplikasi/Pemilik && flutter run -t lib/UtamaDev.dart`. Push notification butuh Firebase (lihat `Aplikasi/Pemilik/README.md`).

Di lingkungan agen/CI Flutter ada di `/opt/flutter` (`export PATH=/opt/flutter/bin:$PATH`).

---

## Konfigurasi lingkungan (`.env`)

Berkas `.env` tidak pernah di-commit dan **tidak boleh dibaca atau ditulis oleh agen**. Kunci yang paling relevan untuk programmer (selengkapnya di `.env.example` dan `config/`):

| Kunci | Fungsi |
|---|---|
| `APP_URL`, `APP_ENV`, `APP_DEBUG` | Alamat dasar & mode. |
| `DB_*` | Koneksi MySQL (`PosSaas`). |
| `EDISI` | `Saas` (bawaan) atau `Lisensi`. Edisi Mandiri dikunci saat build (`bootstrap/EdisiTerkunci.php`) dan mengalahkan `.env`. |
| `PENGELOLA_DOMAIN`, `PENGELOLA_COOKIE_SESI` | Subdomain konsol (lokal: `pengelola.localhost`) dan cookie sesi terpisah. |
| `DOMAIN_PEMASARAN`, `DOMAIN_TENANT` | Pemisahan domain produksi (D-20): situs pemasaran vs dashboard tenant. Kosong di lokal = satu domain. |
| `PROKSI_TEPERCAYA` | Daftar proksi tepercaya (CIDR/IP) agar `X-Forwarded-*` dipercaya. |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `database` bawaan. Di produksi skala besar pertimbangkan Redis (`Panduan/SkalaBesar.md`). |
| `MAIL_*` | `log` di lokal. Email hanya untuk komunikasi Payoung → pemilik; notifikasi ke pelanggan tenant lewat WhatsApp (D-33). |
| `FILESYSTEM_DISK`, `AWS_*` | Penyimpanan berkas (lokal atau S3-kompatibel). |
| `DUKUNGAN_MASUK_RAHASIA` | Rahasia tanda tangan webhook tiket masuk dari email/WhatsApp. |
| `DEMO_KATA_SANDI`, `PENGUJI_*` | Hanya untuk seeder demo/penguji. |

Kredensial penyedia pihak ketiga (DOKU, WhatsApp, email, Firebase, Google, GA4) **tidak** ditaruh di `.env`: dikelola Super Admin dari konsol (Integrasi, P-05) dan disimpan terenkripsi. `.env` hanya memberi nilai awal untuk beberapa pengaturan penagihan (rekening, status PKP, lama langganan) yang kemudian dikuasai konsol (`PengaturanPlatform`).

---

## Routing, domain, dan API

- **Domain.** Konsol didaftarkan lebih dulu di `Route::domain(config('pengelola.Domain'))` agar menang atas rute tenant. Di produksi tiga domain dilayani **satu** aplikasi Laravel: pemasaran (`payoung.id`), tenant (`dashboard.payoung.id`), konsol (`console.payoung.id`). Perantara `ArahkanDomainAplikasi` mengalihkan jalur ke domain yang benar (D-20).
- **Guard.** `web` (tenant, sesi), `pengelola` (konsol, sesi terpisah), token perangkat (Sanctum, API POS), token pengguna (API Owner), token API bercakupan (Open API).

| Lapisan | Prefix | Autentikasi | Catatan |
|---|---|---|---|
| Back-office & halaman | `/kelola/...` | Sesi tenant + izin (`WajibIzinTenant`) + fitur paket (`WajibFiturPaket`, `PetaFiturRute`) | Inertia |
| API POS | `/api/pos/v1/...` | Token perangkat | Idempoten (`Idempotency-Key`), kompatibel mundur 2 versi minor aplikasi |
| API Owner | `/api/pemilik/v1/...` | Token pengguna | Sama aturan kompatibilitas |
| Open API | `/api/v1/...` | Token API bercakupan | Berkursor, OpenAPI 3.1 di `/pengembang` |
| Webhook masuk | `/webhook/...` | Tanda tangan penyedia | DOKU, dukungan masuk |
| Konsol | domain `PENGELOLA_DOMAIN` | Sesi pengelola + izin + 2FA | Lintas tenant hanya di sini |

Aturan rute & API (detail di `.claude/rules/BackendRuteApi.md`):

- URL huruf kecil kebab-case Indonesia, kata benda tunggal (`/kelola/produk`, `/api/pos/v1/sinkron/kirim`); parameter `{camelCase}`; nama rute `kelola.produk.daftar`.
- Key JSON = nama kolom PascalCase. Uang sebagai string desimal (`"15000.00"`). Tanggal ISO-8601 UTC.
- Format galat seragam: `{"Galat": {"Kode": "...", "Pesan": "...", "Detail": {...}}}`.
- **Gerbang kontrak:** `Spesifikasi/KontrakApi/RuteApi.json` dijaga `KontrakApiTes`. Menambah/mengubah rute API publik harus memperbarui berkas itu (dan OpenAPI di `public/pengembang/openapi-v1.json` untuk `/api/v1`, dijaga `SpesifikasiApiPublikTes`). Perubahan yang merusak kontrak = versi baru (`/v2`).
- Header kustom: `X-Id-Kasir`, `X-Versi-Aplikasi`, `X-Skema-Sinkron`, `X-Tanda-Tangan`, `X-Waktu-Kirim`.

---

## Database & migrasi

- Nama tabel tunggal PascalCase (`Penjualan`, `PenjualanDetail`), PK `Id`, FK `Id{Tabel}`, waktu `DibuatPada`/`DiubahPada`/`DihapusPada`. Model mewarisi `ModelDasar`; relasi `belongsTo` selalu menyebut kolom eksplisit.
- **Migrasi yang sudah di-merge tidak boleh diubah.** Perubahan skema = migrasi baru dengan pola expand → contract (kolom baru nullable/ber-default dulu, kode beralih, baru dihapus kemudian).
- Nama berkas migrasi berurutan setelah migrasi terakhir (`2026_12_19_000254_...`). `CekKonvensi.py` menjaga urutannya.
- Perubahan skema lokal Kasir (Drift) memakai nomor skema bertingkat dan **tidak boleh** menghapus outbox yang belum terkirim.
- Pengecekan MySQL vs MariaDB: job MariaDB di CI hanya informasi.
- `migrate:fresh` / `db:wipe` hanya di lingkungan test.

---

## Antrean, jadwal, dan perintah artisan

Produksi membutuhkan **dua** proses selain web: pekerja antrean dan penjadwal.

```bash
php artisan schedule:work          # lokal: jalankan jadwal terus-menerus
php artisan queue:listen           # lokal: pekerja antrean (sudah ikut `composer dev`)
# produksi (hosting bersama): cron tiap menit
* * * * * php /path/Aplikasi/Web/artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php` mendaftarkan sekitar 25 tugas terjadwal (waktu WIB), antara lain `queue:work --stop-when-empty` tiap menit, `integrasi:kirim-webhook`, `tagihan:rekonsiliasi-gerbang` (15 menit), `tagihan:terbitkan-perpanjangan` (08.20), `pembelian:draf-po-otomatis` (05.30), `kasir:tutup-harian-otomatis` (06.15), `tindakan:kirim-ringkasan-harian` (07.00), `persediaan:bangun-ulang-saldo --periksa` (02.30), `tenant:periksa-silang` (mingguan). Penjadwal yang menyapu tenant hanya mengunjungi tenant yang punya pekerjaan (`KonteksPengelola::IdTenantDenganPekerjaan`) dan memakai `withoutOverlapping`.

Perintah berguna (daftar lengkap: `php artisan list`):

| Kelompok | Perintah |
|---|---|
| Setup & rilis | `rilis:pasang` (migrasi + penyelarasan data + cache), `pengelola:siapkan-peran`, `pengelola:buat-super-admin`, `template:terapkan`, `katalog:terapkan [--kering]`, `katalog:lengkapi-fitur`, `legal:siapkan-bawaan`, `bantuan:siapkan-bawaan`, `situs:segarkan-bawaan` |
| Perbaikan data | `persediaan:bangun-ulang-saldo`, `laporan:bangun-ulang-ringkasan`, `organisasi:siapkan-peran`, `tenant:periksa-silang`, `karyawan:selaraskan-tanggal-absensi` |
| Pembayaran | `tagihan:rekonsiliasi-gerbang`, `penjualan:rekonsiliasi-qris`, `tagihan:proses-tunggakan`, `langganan:terapkan-turun-paket` |
| Edisi Lisensi | `lisensi:buat-kunci`, `lisensi:terbitkan`, `lisensi:pasang`, `lisensi:info`, `lisensi:atur-integrasi`, `lisensi:ekspor-data-master`, `lisensi:impor-data-master` |
| Operasional | `pengelola:detak`, `pengelola:uji-integrasi`, `pengelola:kosongkan-allowlist-ip`, `status:ekspor`, `penyimpanan:ukur`, `unduhan:bersihkan` |

---

## Pengujian & pengecekan kualitas

Semua pengecekan ini juga dijalankan CI dan harus hijau sebelum merge. **Jalankan hanya yang terkena perubahan** (aturan proyek); CI tetap menjalankan semuanya.

| Cakupan | Perintah |
|---|---|
| Konvensi penamaan & pola terlarang | `python3 Alat/CekKonvensi.py --berubah` (atau `--semua`) dari akar |
| `Dokumen/` sinkron dengan `PRD.md` | `python3 Alat/PecahPrd.py --cek` dari akar |
| PHP: format & analisis statis | `composer analisis` (Pint `--test` + PHPStan/Larastan) di `Aplikasi/Web`; `composer rapikan` memperbaiki format |
| PHP: test | `composer tes:cepat`, atau satu berkas: `vendor/bin/pest tests/Fitur/Tenant/LamaLanggananBebasTes.php` |
| Frontend | `npm run periksa` (tsc, ESLint, Prettier, Vitest); satu berkas: `npx vitest run <berkas>`; `npm run rapikan` memformat |
| Flutter & paket Dart | `melos run periksa` dari akar (format lebar 120, analyze fatal-info, test). Satu berkas: `TZ=Asia/Jakarta flutter test <berkas>` di `Aplikasi/Kasir`; `dart test` untuk `Paket/Inti` & `Paket/MesinKasir` |

**Catatan test PHP:**

- Suite memakai **MySQL** database `PosSaasTes` (lihat `phpunit.xml`: user `pos_saas`, sandi `rahasia-lokal-tes`). `<env>` di phpunit tidak memaksa, jadi bila sandi lokal Anda berbeda cukup `export DB_PASSWORD=...` sebelum menjalankan Pest.
- Penamaan berkas test: `*Tes.php` (suite `Unit`, `Fitur`, `Arsitektur`). `tests/Konkurensi` (dua proses) dan `tests/Beban` dijalankan terpisah.
- Flutter: zona waktu **wajib WIB** (`TZ=Asia/Jakarta`) karena golden dan teks jam memakai waktu lokal. `melos run tes:flutter` sudah mengaturnya.

**Yang wajib ada pada perubahan perilaku:**

- Test perilaku per BR (sebut BR-xx di nama/deskripsi), isolasi tenant, idempotensi (`UuidKlien` dua kali = satu transaksi), dan **invariant** untuk flow keuangan/stok: Σ debit = Σ kredit, `SaldoStok` = Σ `MutasiStok`, nilai persediaan = saldo akun persediaan.
- Kalkulasi harga/pajak/promo lolos vektor `Spesifikasi/VektorUjiKalkulasi/` di PHP **dan** Dart. Kasus baru boleh ditambah, kasus lama tidak boleh dihapus/dilemahkan.
- Test arsitektur (batas antar-domain, larangan pola, nama, rute, kontrak API, penyedia pembayaran, surel) **terlindungi**: bila gagal, perbaiki kodenya.
- UI: semua tabel lewat `TabelData`, tanpa `<select>`/`<input type="date">` bawaan, token desain tanpa hex lepas, tanpa titik koma di teks antarmuka, responsif 360/768/1280px.
- **Dilarang** `->skip()`, `markTestSkipped`, menghapus assertion, melonggarkan toleransi, atau melemahkan lint/CI supaya lolos.

Uji beban API POS (k6, manual lewat Actions): [`Panduan/UjiBeban.md`](Panduan/UjiBeban.md).

---

## Konvensi wajib

Ringkasan [`CLAUDE.md`](CLAUDE.md) (detail di PRD §13.7 dan `.claude/rules/`):

- **Bahasa Indonesia + PascalCase** untuk tabel, kolom, folder, file, class, function. Function diawali kata kerja (`HitungTotal`, `SimpanPenjualan`). Variabel lokal camelCase. Istilah mengikuti kamus PRD §13.7.1 (Pemasok, bukan Supplier). Pengecualian hanya yang tercantum di §13.7.4 dan `Alat/KonvensiPengecualian.json`. ESLint menegakkan PascalCase juga pada variabel di berkas test.
- **Satu use case = satu class Aksi** (`Jalankan()` tunggal), kontroler tipis (validasi lewat `...Permintaan`, panggil Aksi), aksi yang mengubah data dibungkus `DB::transaction`.
- **Uang/kuantitas bukan float**; **tarif pajak dari `TarifPajak`**; **dokumen terposting tidak diedit**; **mutasi POS idempoten**; **migrasi merged tidak diubah**.
- **Setiap query data tenant lewat `MilikTenant`**; jangan `withoutGlobalScope(s)` di luar `Domain/Pengelola`.
- **Setiap aksi penting mencatat audit** (`LogAudit`). Kunci peristiwa audit baru wajib masuk `KamusPeristiwaAudit` agar log terbaca manusia.
- **Hak fitur paket ditegakkan di server** (`PetaFiturRute`, `WajibFiturPaket`), bukan hanya disembunyikan di UI.
- **UI web**: font Atkinson Hyperlegible, komponen `TabelData`, `Panel`, `AksiHalaman` (satu aksi utama berwarna di HP), `Tombol` (bukan `Button` mentah), `PemilihTanggal`/`PemilihJam`/`PilihanCari`, wizard bertahap untuk form tambah yang besar, microcopy Indonesia konkret ("Simpan produk"), format Rupiah `Rp 1.250.000`, pemisah keterangan ` | `. Tanpa gradien/efek kaca/emoji; `<h1>` hanya lewat `JudulHalaman`; blok halaman tidak disempitkan `max-w-*`.
- **POS Flutter**: semua layar di bingkai Ruang Kerja Kasir, uang `decimal`, offline-first (Drift), hardware lewat `Paket/AdaptorPerangkat`, font di-bundle, target sentuh ≥ 48dp.
- **Dilarang**: `git push --force`, `--no-verify`, menonaktifkan hook, membaca/menulis rahasia, melemahkan test/lint/CI.

---

## Alur kerja pengembangan

1. **Setiap tugas terikat ke ID flow** (`P-xx` Platform Pengelola, `F-xx` tenant) dan aturan bisnisnya (`BR-xx`). Mulai dengan `/mulai-flow <ID>` (baca `Dokumen/Flow/`, susun rencana yang menyebut BR dan D-xx).
2. Kerjakan **sekecil mungkin** sesuai cakupan, ikuti urutan dependensi flow (PRD bagian 7). Jangan refactor di luar tugas.
3. Setiap perubahan perilaku disertai test; flow keuangan/stok wajib invariant test.
4. Sebelum menyatakan selesai, jalankan `/cek-dod` (pengecek konvensi, test, lint, lalu tinjauan agen `penjaga-konvensi`).
5. **Perubahan PRD** dilakukan di `PRD.md` (baris riwayat versi + tabel keputusan D-xx), lalu `python3 Alat/PecahPrd.py` untuk memperbarui `Dokumen/`. `Panduan/DaftarKekurangan.md` harus merujuk versi PRD dalam 8 versi terakhir (dijaga `PecahPrd.py --cek`).
6. **Penjaga:** hook di `.claude/hooks/` (lindungi file, cek perintah, cek setelah edit, cek sebelum selesai), `CekKonvensi.py`, test arsitektur, dan CI. Bila penjaga menolak, perbaiki kodenya, bukan penjaganya. Sejak D-17 agen boleh memperbarui file penjaga asal tidak melemahkannya, dicatat di PRD, dan dilaporkan.
7. **PR** memakai `.github/pull_request_template.md` dan menyebut Flow, BR, dan D-xx. Berkas di `.github/CODEOWNERS` menentukan peninjau. Dependabot memperbarui dependensi (Composer dan npm di `Aplikasi/Web`, pub di akar, GitHub Actions).
8. Menyimpang dari PRD diam-diam dilarang: bila aturan bertabrakan atau terasa salah, berhenti dan tanyakan, atau tulis usulan di bagian "Usulan perubahan keputusan" pada PR.

---

## Resep: menambah fitur

Contoh urutan kerja untuk fitur baru di server + back-office:

1. **Baca flow & BR** terkait lewat `Dokumen/Indeks.md`. Catat D-xx yang berlaku.
2. **Skema**: migrasi baru bernomor berikutnya (kolom PascalCase, `IdTenant` untuk data tenant, indeks yang dibutuhkan). Model mewarisi `ModelDasar`, pakai `MilikTenant`.
3. **Domain**: `Aksi/{KataKerja}{Objek}.php` dengan `Jalankan()`, transaksi DB, audit (`PencatatAudit`), pelanggaran aturan lewat `PelanggaranAturanBisnis`. Stok/jurnal hanya lewat `CatatMutasiStok`/`PostingJurnal`. Aturan bisnis yang bisa berubah → pengaturan (konsol/config), bukan konstanta.
4. **HTTP**: `Permintaan` (validasi + `attributes()` berbahasa Indonesia), kontroler tipis, rute di berkas `routes/{Modul}.php` dengan izin (`IzinTenant`) dan fitur paket (`PetaFiturRute`). Rute API: perbarui `Spesifikasi/KontrakApi/RuteApi.json`.
5. **UI**: halaman di `resources/js/Halaman/...`, tabel lewat `TabelData`, form dengan komponen `Komponen/Formulir`, aksi halaman lewat `AksiHalaman`, keadaan memuat/kosong/galat/tanpa izin. Menu: satu rumah saja (menu samping **atau** `Pustaka/DaftarPengaturan.ts`), anggaran navigasi dijaga test.
6. **Test**: Pest di `tests/Fitur/...` (perilaku BR, izin, isolasi tenant, idempotensi, invariant), Vitest untuk komponen/logika murni.
7. **Dokumentasi**: baris riwayat versi + D-xx di `PRD.md`, `python3 Alat/PecahPrd.py`, ringkas di status `CLAUDE.md`.
8. **Verifikasi**: `CekKonvensi.py --berubah`, `composer analisis`, Pest berkas yang tersentuh, `npm run periksa` (atau Vitest berkas), `npm run build`.

Menambah penyedia pembayaran baru: [`Panduan/PenyediaPembayaran.md`](Panduan/PenyediaPembayaran.md). Mengubah merek/aset: skrip di `Spesifikasi/Merek/`.

---

## CI/CD

| Workflow | Isi |
|---|---|
| `.github/workflows/CekKepatuhan.yml` | `konvensi` (CekKonvensi + PecahPrd), `backend` (PHP 8.3 + MySQL 8, Pint + PHPStan + Pest dipecah 4 potongan), `backend-mariadb` (informasi, tidak memblokir), `web` (tsc, ESLint, Prettier, Vitest, build), `flutter` (format, analyze, test, vektor uji), `rilis` (artefak per SHA, termasuk `payoung-mandiri-{sha}`). Juga `composer audit` / audit npm. |
| `.github/workflows/BangunAplikasi.yml` | Bangun Kasir untuk Windows (zip + pemasang Inno Setup), iOS (tanpa tanda tangan), Android. Manual (`workflow_dispatch`) dan pada push/PR terkait. |
| `.github/workflows/UjiBeban.yml` | k6: checkout, outbox, polling, webhook. Manual: Actions → Uji Beban → Run workflow. |

Bila CI merah: baca lognya, reproduksi, perbaiki akar masalah. Jangan men-skip test atau melemahkan konfigurasi.

---

## Deploy & rilis

**Server (SaaS atau hosting bersama/VPS):**

```bash
bash Alat/Perbarui.sh                 # tarik kode, composer install, rilis:pasang
bash Alat/Perbarui.sh --dengan-situs  # sekaligus menimpa halaman situs pemasaran dengan teks bawaan terbaru
bash Alat/Perbarui.sh --kering        # hanya lihat rencana
PHP=/opt/alt/php83/usr/bin/php bash Alat/Perbarui.sh   # bila PHP bawaan hosting bukan 8.3
```

`rilis:pasang` menjalankan migrasi, penyelarasan data tenant lama (peran, izin, nama bawaan, tanggal absensi, …), template, bantuan, dan cache. Harga katalog dan penimpaan situs tetap manual (`php artisan katalog:terapkan --kering` dulu).

| Panduan | Untuk |
|---|---|
| [`Panduan/PasangDiHosting.md`](Panduan/PasangDiHosting.md) | Pasang di hosting bersama (hPanel, SSH), tiga domain, cron, `.env` |
| [`Panduan/MigrasiServer.md`](Panduan/MigrasiServer.md) | Pindah dari hosting bersama ke server sendiri tanpa henti layanan |
| [`Panduan/InstalasiLisensi.md`](Panduan/InstalasiLisensi.md) | Payoung Mandiri di server pembeli |
| [`Panduan/RunbookOperasional.md`](Panduan/RunbookOperasional.md) | Respons alert (penjadwal berhenti, antrean tertunda, error rate, outbox macet, …) |
| [`Panduan/SkalaBesar.md`](Panduan/SkalaBesar.md) | Daftar kerja menjaga performa di skala 100.000 pengguna |

**Aplikasi mobile/desktop:** build Android/iOS/Windows lewat `BangunAplikasi.yml`; penandatanganan Android wajib kunci rilis; Windows punya pembaruan otomatis ber-checksum SHA-256. Kode build ada di `pubspec.yaml` masing-masing aplikasi (saat ini Kasir `1.0.1+14`, Pemilik `1.0.1+11`) dan dinaikkan setiap perubahan yang dirilis. Langkah lengkap: [`Panduan/RilisAplikasi.md`](Panduan/RilisAplikasi.md) dan [`Panduan/RilisPlayStore.md`](Panduan/RilisPlayStore.md). Versi minimum, rilis bertahap, flag fitur, dan kill switch diatur dari konsol (P-10).

---

## Masalah yang sering muncul

| Gejala | Penyebab & solusi |
|---|---|
| `SQLSTATE[HY000] [1045] Access denied` | `DB_PASSWORD` di `.env` tidak sama dengan sandi user MySQL. Untuk Pest, sandi ada di `phpunit.xml` atau ekspor `DB_PASSWORD`. |
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL belum berjalan. |
| Pest gagal `Unknown database 'PosSaasTes'` | Buat database test dan beri hak ke user `pos_saas` (lihat langkah A.1). |
| Halaman tampil tanpa gaya / kosong | Vite belum jalan. Pastikan `composer dev` hidup, atau `npm run dev` / `npm run build`. |
| Tautan verifikasi email tidak ada | Lihat bagian bawah `storage/logs/laravel.log`. |
| Laporan/dasbor belum terisi setelah berjualan | Pekerja antrean belum jalan (`php artisan queue:listen`). |
| Notifikasi/jadwal tidak jalan | Penjadwal tidak aktif. Lokal: `php artisan schedule:work`; produksi: cron `schedule:run`. |
| Aplikasi Kasir tidak bisa terhubung | Periksa `ALAMAT_SERVER`; HP fisik butuh `php artisan serve --host=0.0.0.0` dan IP komputer. |
| `/masuk` dashboard dialihkan ke domain pemasaran | Pastikan `DOMAIN_TENANT` / `DOMAIN_PEMASARAN` benar, atau kosong di lokal. Rute ter-cache memakai `PenyediaAplikasi::PakaiKoleksiRuteBiasa`. |
| Tes Flutter jam/golden berbeda | Jalankan dengan `TZ=Asia/Jakarta`. |
| `PecahPrd.py --cek` gagal | Jalankan `python3 Alat/PecahPrd.py` setelah mengedit `PRD.md`; periksa rujukan versi di `Panduan/DaftarKekurangan.md`. |

---

## Status pengembangan

**Fase 1 inti selesai** (PRD §22) dan fitur fase lanjutan sudah banyak dibangun. PRD saat ini di **versi 7.69** (lama langganan bebas, D-249). Daftar lengkap yang sudah ada tersusun kronologis di bagian "Status" pada [`CLAUDE.md`](CLAUDE.md) dan di riwayat versi `PRD.md` (bagian 25). Garis besar:

| Area | Sudah ada |
|---|---|
| Platform Pengelola | Tim internal & 2FA, master regulasi, template sektor (19+ sektor), paket/harga/add-on, langganan & tagihan (DOKU, kupon, refund, turun/naik paket, lama bebas), dokumen legal, tiket & obrolan, akses dukungan, insiden & status, analitik (GA4), kampanye prospek, mitra & referral, rilis aplikasi bertahap |
| Registrasi & organisasi | Pendaftaran, panduan awal (siapkan otomatis), peran & izin, outlet, perangkat, tim, absensi web |
| Katalog & harga | Produk, varian, resep, satuan, daftar harga, label, impor/ekspor, favorit, harga terbuka |
| Penjualan & kasir | Kalkulasi PHP & Dart, sinkron offline, bayar tunai/QRIS/split, meja & KDS, void/retur/tukar, pre-order, grosir, kanvas, salesman, apotek, bengkel, laundry, reservasi |
| Persediaan & pembelian | Ledger, batch, nomor seri, transfer, opname, produksi, konsinyasi, PO/penerimaan/faktur/hutang/retur/nota debit |
| Keuangan & laporan | Jurnal otomatis, bagan akun, buku besar, laporan keuangan, tutup periode, aset tetap, rekonsiliasi bank, anggaran, laporan penjualan/stok/pajak/anti-fraud |
| Pelanggan & karyawan | CRM, tier, poin, deposit, gift card, kampanye WhatsApp, piutang, invoice B2B, jadwal, gaji, komisi, kasbon |
| Kanal | Toko online, self-order QR meja, kios, Aplikasi Pemilik, Open API + webhook, edisi Lisensi (Payoung Mandiri) |

Daftar kekurangan & pekerjaan tertunda: [`Panduan/DaftarKekurangan.md`](Panduan/DaftarKekurangan.md). Keputusan yang menunggu pemilik produk: [`Panduan/KeputusanMenunggu.md`](Panduan/KeputusanMenunggu.md). Beberapa layar Flutter dan fitur kamera (pindai barcode/seri) belum diuji di perangkat sungguhan; lihat catatan pada daftar kekurangan.

---

## Dokumentasi lanjutan

**Produk & aturan**

- [`PRD.md`](PRD.md): dokumen lengkap (baca per bagian lewat [`Dokumen/Indeks.md`](Dokumen/Indeks.md))
- [`Dokumen/Flow/`](Dokumen/Flow): spesifikasi per flow bisnis
- [`Dokumen/Keputusan.md`](Dokumen/Keputusan.md): keputusan produk (D-xx)
- [`CLAUDE.md`](CLAUDE.md) & [`.claude/rules/`](.claude/rules): aturan kerja per stack (Backend domain/migrasi/rute/pengelola, Akuntansi & stok, Frontend web, Desain UI, Flutter, Pengujian)

**Panduan teknis & operasional** (`Panduan/`)

| Berkas | Isi |
|---|---|
| `PasangDiHosting.md`, `MigrasiServer.md`, `InstalasiLisensi.md` | Pemasangan & migrasi server |
| `RunbookOperasional.md`, `SkalaBesar.md`, `UjiBeban.md` | Operasi, kinerja, uji beban |
| `RilisAplikasi.md`, `RilisPlayStore.md` | Build & rilis aplikasi |
| `PenyediaPembayaran.md`, `DokuSnap.md` | Penyedia pembayaran (DOKU) |
| `LoginGoogle.md`, `AnalitikGoogle.md`, `KampanyeEmail.md` | Integrasi Google & email kampanye |
| `AksesDukungan.md`, `ObrolanDukungan.md` | Dukungan: akses berizin, obrolan |
| `KalibrasiWajah.md`, `KesegaranDataKasir.md` | Absensi wajah, kesegaran data kasir |
| `PanduanSEO.md`, `RencanaDesainSitusPemasaran.md` | Situs pemasaran |
| `AuditKemudahanPakai.md`, `DaftarKekurangan.md`, `KeputusanMenunggu.md` | Audit & daftar pekerjaan |

Catatan per aplikasi: [`Aplikasi/Kasir/README.md`](Aplikasi/Kasir/README.md), [`Aplikasi/Pemilik/README.md`](Aplikasi/Pemilik/README.md), [`Aplikasi/Web/README.md`](Aplikasi/Web/README.md).

---

## Lisensi

Proprietary. Hak cipta © 2026 Ahmad Affandi Sikumbang. Seluruh hak dilindungi. Lihat [`LICENSE`](LICENSE).
