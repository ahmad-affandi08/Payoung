# Daftar Kekurangan & Pekerjaan Tertunda Payoung

Status per 24 Oktober 2026 (PRD v6.87, disisir terhadap v6.78–v6.87: v6.78–v6.83 kabar stok antar-outlet, kuota transaksi bulanan & harian, dan absensi web (umpan balik langsung, ambang wajah 0,70; D-184…D-188) tidak mengubah butir A1–N, **tes PHP barunya belum dijalankan lokal, hanya CI**, dan absensi wajah **belum diuji di perangkat sungguhan** (model ringan tidak menjamin menolak orang yang sangat mirip, lihat `Panduan/KalibrasiWajah.md`); v6.84 QRIS statis toko bernominal otomatis di kasir (D-189; **belum diuji dengan aplikasi bank/dompet sungguhan**, tidak semua aplikasi menegakkan nominal dari QRIS statis yang diubah, dan dana tetap dikonfirmasi kasir manual); v6.85 halaman Aktivasi QRIS DOKU disembunyikan (D-190), sehingga **tenant SaaS belum punya jalur QRIS dinamis lewat gerbang** (QRIS online toko online & kios menunggu penyedia lain); v6.87 istilah UI "Filter" menggantikan "Saring" (D-192; artikel Pusat Bantuan/halaman situs yang sudah tersimpan di basis data tidak ikut berubah); v6.86 tab seragam gaya Majoo (D-191, tampilan HP diperiksa di Chromium, belum di perangkat sungguhan); sebelumnya PRD v6.77, disisir terhadap v6.69–v6.77: v6.69–v6.74 merek/ID aplikasi, tampilan Ruang Kerja kasir, dan galat dialog (D-182) tidak mengubah butir A1–N; v6.75–v6.77 fitur khusus sektor tidak bocor lintas sektor (D-183: web, server, Aplikasi Kasir, Aplikasi Owner, panduan awal usaha tanpa kasir, Kotak Tindakan berbatas sektor; **tes PHP barunya belum dijalankan lokal karena `composer install` gagal di lingkungan agen, hanya CI yang menjalankannya**); sebelumnya PRD v6.68, disisir terhadap v6.52–v6.68: v6.68 absensi lewat login mobile first (D-180; mengganti tautan rahasia, layar QR outlet, dan absen PIN kasir; **halaman Absen dan kamera/GPS/wajah belum diuji di perangkat sungguhan**, dan keputusan K36–K38 tentang kepercayaan wajah tetap berlaku), v6.67 penyedia pembayaran bisa diganti lewat tiga colokan (D-179; tidak mengubah perilaku, tanpa celah baru), v6.62 pindai kamera nomor seri/IMEI (D-175) dan v6.61 pindai kamera di seluruh isian & pencarian barcode back-office (D-174), **keduanya belum diuji di perangkat sungguhan** (QR/DataMatrix pada label perangkat juga belum dicoba), v6.52–v6.57 F-21 invoice & penagihan B2B (D-166…D-170; fitur baru di luar inventaris 145 ID, e-Meterai & tautan bayar QRIS otomatis masih menunggu keputusan/DOKU), v6.58 barcode produk diketik/dipindai kamera/dihapus dari detail produk (D-171; **kamera belum diuji di perangkat sungguhan**, terutama iPhone/Safari lewat ZXing), v6.59 ringkasan menu menurut jenis usaha & pintasan Beranda (D-172), v6.60 alert error rate/latensi API ditunda buka/tutup dengan minimum 100 permintaan (D-173; **PGL-20 tetap SEBAGIAN**); tidak ada butir A1–N yang berubah status; sebelumnya PRD v6.51, disisir terhadap v6.43–v6.51: v6.44 log audit terbaca (D-164), v6.48 ganti jenis usaha oleh Pemilik (D-165), v6.49 Pengaturan dirampingkan, v6.50 webhook DOKU menerima notifikasi QRIS berformat Non-SNAP (jawaban dukungan DOKU; tetap belum diuji dengan pembayaran produksi sungguhan), v6.51 rentang tanggal di Ringkasan Beranda; tidak ada butir A1–N yang berubah status; sebelumnya PRD v6.42, disisir terhadap v6.32–v6.42: v6.42 live chat dukungan, PLT-12 SELESAI (bagian N, D-163); v6.41 gerbang pembayaran milik toko disembunyikan dari tenant SaaS (D-162), QRIS dinamis tenant baru menunggu Aktivasi QRIS tersambung; v6.37 perbaikan indeks Search Console; v6.38–v6.40 pembayaran tagihan lewat DOKU SNAP, halaman bayar bergaya Midtrans, dan logo cara bayar dari `idn-finlogos` (CC BY-NC, harus diganti logo resmi sebelum peluncuran komersial, D-161); tidak ada butir lama yang berubah status selain yang tercatat di bagian M; PRD v6.31: bagian L = tur produk interaktif PLT-12; PRD v6.30: bagian J menutup delapan butir "kecil sampai sedang" (PGL-01, TAX-07, OWN-03, PLT-08, POS-05, PGL-07, PGL-15, PGL-20 lanjutan); PRD v6.26: bagian I menutup butir 6 urutan G (servis non-kendaraan, grid ukuran × warna, minimum charge, reservasi meja + DP) dan memuat hasil analisis serta tes Flutter yang untuk pertama kali benar-benar dijalankan; bagian H = PRD v6.17–v6.22; PRD v6.16: audit ulang seluruh ID fitur PRD terhadap kode, hasil di bagian G, yang menggantikan status bagian F bila berbeda; PRD v6.15, disisir terhadap v6.07–v6.15: v6.07 Nota Debit pembelian SELESAI (D-135); v6.08 satu perintah pembaruan server `rilis:pasang`; v6.09 konsistensi lebar halaman back-office; v6.10 menu situs pemasaran bisa digulir di HP; v6.11 Beranda & pencarian cepat konsol; v6.12–v6.13 kode outlet & nama gudang bawaan mengikuti nama usaha; v6.14–v6.15 wizard bertahap tambah promo/kampanye/tim/outlet dan penunjuk langkah container query (D-136), semuanya sudah diperiksa di Chromium 360/768/1280px; tidak ada butir lama lain yang berubah status; PRD v6.06: Permintaan Pembelian / PR; PRD v6.05: self-order QR meja bayar QRIS dari HP + menu berkala, F20 SELESAI; PRD v6.04: pratinjau sandbox & tawaran pembaruan template, F30 SELESAI; PRD v6.03: templat komunikasi platform, PGL-07 SELESAI; PRD v6.02: Pusat Bantuan & bantuan kontekstual, sebagian PLT-12; PRD v6.01: ketersediaan per outlet × kanal, F21 SELESAI; PRD v6.00: opname per rak, F22 SELESAI; PRD v5.99: analitik platform, PGL-24 SELESAI; PRD v5.98: rencana produksi harian Bakery; PRD v5.97: tab Tagihan/Tiket/Perangkat di tampilan 360° tenant; PRD v5.96: skor kesehatan tenant, PGL-09 SELESAI; PRD v5.95: insiden & halaman status, PGL-21 SELESAI; PRD v5.94: cuti/izin karyawan; PRD v5.93: jam operasional outlet; PRD v5.92: kirim PO lewat WhatsApp; PRD v5.91: ekspor jurnal; PRD v5.90: pusat notifikasi web; PRD v5.89: impor pemasok; PRD v5.88: anggaran vs realisasi, F8 SELESAI; PRD v5.87: umpan balik pelanggan, F7 SELESAI; PRD v5.86: draf PO dari perkiraan pemakaian, F24 SELESAI; PRD v5.85: PPN masukan, F26 SELESAI; PRD v5.84: prediksi penjualan, bundling, saran harga; PRD v5.83: waktu saji, food cost, ~~performa kasir~~ (SELESAI @ v5.83); PRD v5.82: laporan pembelian; PRD v5.81: ~~slow/fast moving~~ (SELESAI @ v5.81); PRD v5.80: laporan per pelanggan & perbandingan outlet; PRD v5.79: lokasi produksi bahan resep BR-05.4; PRD v5.78: void item meja masuk laporan + opsi bahan terbuang; PRD v5.77: push kedaluwarsa batch ke Aplikasi Owner, butir F23 SELESAI; PRD v5.76: alat bantu dukungan di konsol, butir F1 SELESAI (D-133); PRD v5.75: penutupan celah PGL-16 bagian 1; PRD v5.74: akses dukungan berizin & akses darurat (D-132); PRD v5.73: jurnal umum manual, butir F2 SELESAI (D-131); PRD v5.69–v5.72: wizard tambah produk, label harga/barcode, pemindai kamera di Jual (D-128 s.d. D-130), tidak mengubah status butir A1–K; PRD v5.68: struk per tamu & produk favorit pilihan pemilik, D-127; PRD v5.67: absen pulang di Ruang Kerja kasir, D-126; PRD v5.66: shift ganda satu kasir dicegah saat online, D-125; PRD v5.65: Pusat Unduhan, butir B16; butir B14, B15 diberi label DITUNDA karena hanya ide opsional per outlet, bukan kekurangan, dan B4 geofence ditandai SELESAI; PRD v5.64, disisir terhadap v5.18–v5.64 (v5.64: teks situs & tombol unduh, menutup E11; v5.63: pengingat pesanan online berbayar belum ditagih (E8 butir 4; shift virtual menunggu keputusan, D-123); v5.62: retur pembelian dengan pengembalian uang (E8 butir 3); v5.61: penutupan uang QRIS tanpa penjualan (E8 butir 2); v5.60: hapus-buku piutang (E8 butir 1); v5.59: tutup akun dan penghapusan data UU PDP, menutup E5; v5.58: ekspor data lengkap usaha (E5 bagian 2); v5.57: turun paket mandiri & pindah ke Gratis (E5 bagian 1); v5.56: penyimpanan berkas ditegakkan dan tarif pajak baru diumumkan, menutup E2 dan E10; v5.55: penjualan tarif lama & kas dari pencatat yang izinnya berubah diterima sebagai tinjauan, menutup E7; v5.54: rekening tujuan & status PKP diatur dari konsol, tidak mengubah status butir; v5.53: batas refund diatur dari konsol, tidak mengubah status butir; v5.52: kupon 100%, tagihan manual Enterprise, hapus-buku, refund, dan bukti transfer menutup E4 dan E6; v5.51: penegakan hak fitur paket di server menutup E1–E3, lihat bagian E; v5.41–v5.49: cakupan outlet per peran, permintaan stok, kategori biaya bawaan, kuota & WhatsApp tenant, toko online publik modern & dibagikan, serta audit ujung-ke-ujung 12 Oktober yang hasilnya dicatat di bagian E; butir A1–K tidak berubah status; v5.27–v5.35: gambar situs jadi aset build, menu Solusi & halaman publik modern, surat jalan/daftar ambil barang transfer, pintasan Retur ke Gudang, tagihan lunas saat kembali dari DOKU, naik paket Pro → Bisnis; tidak ada butir lama yang selesai selain yang tercatat di bagian B): v5.21 dropdown cari di HP, v5.22 harga peluncuran & `katalog:terapkan`, v5.23–v5.24 halaman masuk/daftar berisi iklan, v5.25 `template:terapkan` & 5 sektor baru, v5.26 SEO situs pemasaran; tidak ada butir lama yang berubah status, butir baru A19–A21 di bawah; v5.17 ilustrasi keadaan kosong Kasir (Dart belum dikompilasi); v5.16 perbaikan kasir (Dart belum dikompilasi); v5.15 hanya perbaikan boot saat migrasi; v5.14 situs pemasaran didesain ulang (tidak mengubah butir lain; nomor WhatsApp situs produksi masih kosong); v5.13 pendaftaran merchant DOKU Partner API (asumsi badan registrasi & tanda tangan belum diuji sandbox); v5.12 semua pembayaran pindah ke DOKU: QRIS dinamis ke sub account menunggu jawaban DOKU (butir baru, belum diuji sandbox); daftar disisir ulang terhadap v5.01–v5.11: v5.11 kasir menolak menjual melebihi stok + audit kesegaran data (`Panduan/KesegaranDataKasir.md`; Dart belum dikompilasi); tidak ada butir yang berubah status; v5.10 persetujuan jarak jauh disegarkan dari server; v5.01 audit kinerja skala besar, v5.02–v5.08 perbaikan aplikasi Kasir (retur dari struk, shift lama, umpan hasil aksi, PIN tukar barang, pemindaian bug; butir K-11 tetap SELESAI), v5.09 ganti email & penyaring email asal-asalan di Keamanan akun; sebelumnya terhadap v4.80–v5.00: v4.98–v4.99 hanya nama outlet pertama & naskah legal, v5.00 menambah kios pesan sendiri di web (butir B14); sebelumnya terhadap v4.53–v4.79: tidak ada butir yang berubah status, v4.71–v4.79 hanya merek, penanda muat, dan penyunting situs; v4.70 menambah masuk dengan Google yang belum diuji dengan Client ID sungguhan dan konfigurasi native Android/iOS-nya, lihat `Panduan/LoginGoogle.md`; bagian K disisir dari kode 2 Oktober 2026 dan statusnya diperbarui tiap butir selesai sampai K-25; butir A3, A14, dan A15 disisir ulang di v3.25, sisanya belum disisir butir demi butir sejak v2.88, lihat catatan audit di bawah). Dokumen ini mencatat apa yang **belum ada**, **belum diuji di produksi**, atau **menunggu keputusan pemilik produk**, supaya sistem bisa dibuka ke publik lebih dulu dengan risiko yang diketahui. Perbarui setiap kali satu butir selesai.

Status butir (audit F-26): **TERBUKA** = belum dikerjakan; **SELESAI @ versi** = sudah dikerjakan & ada test regresinya; **DITUNDA** = sengaja ditunda; **MENUNGGU KEPUTUSAN** = butuh pemilik produk. Butir baru ditandai SELESAI hanya bila test regresinya ada.

## A. Wajib diperhatikan sebelum/tepat saat dibuka ke publik

| # | Kekurangan | Dampak | Yang perlu dilakukan |
|---|---|---|---|
| A1 | Baru dipasang di hosting produksi (26/09/2026): situs & halaman masuk sudah terbuka, cron aktif | Alur lengkap (daftar tenant, kasir sinkron, email) belum diuji di produksi | Uji alur ujung ke ujung setelah email, CAPTCHA, legal, dan harga paket diatur |
| A2 | **SELESAI (3 Okt 2026, konfirmasi pemilik):** hosting memakai **MySQL**, sama dengan lingkungan test (§13.7.5) | — | Pastikan versi MySQL 8.x (`SELECT VERSION()`) |
| A3 | **Uji beban sudah jalan di runner CI (v3.20–v3.25, `Panduan/UjiBeban.md`), angka kapasitas hosting sendiri belum terukur** (§23). Run 1 Okt 2026 (10 tenant, ±16 request/s, 1 menit): checkout p95 791 ms, outbox (20 penjualan/request) p95 3,55 d, polling p95 465 ms, webhook p95 477 ms, 0 galat 500, 960 penjualan diterima & tersimpan, invarian stok/jurnal utuh. Deadlock insert `Penjualan` (kunci celah indeks unik `Uuid`) ditutup sebagian di v3.27 (220 → ±40–75 pada beban 25 penjualan/detik, variasi antar-run besar); sisanya dari upsert `SaldoStok` dan diserap percobaan ulang `PemrosesSinkron` (5 kali), upaya `UPDATE` per baris tidak terbukti membantu | Angka runner bukan angka hosting bersama; akar deadlock belum diubah | Ukur di staging dengan skrip yang sama; batasi jumlah tenant awal (beta), pantau dasbor Operasional di konsol |
| A4 | Harga paket bawaan masih **Draf** | Halaman `payoung.id/harga` hanya menampilkan paket harga negosiasi (Enterprise) | Super Admin cukup menekan "Terbitkan harga" di konsol (D-34, tanpa peninjau kedua) |
| A5 | Dokumen legal (S&K, Kebijakan Privasi) belum terbit di database produksi | Pendaftaran tenant tertutup | Terbitkan dari konsol → Legal. Isi hukumnya perlu ditinjau ahli hukum (UU PDP, UU ITE) |
| A6 | Sebagian (3 Okt 2026): **CAPTCHA sudah memakai Cloudflare Turnstile**; penyedia email belum dipastikan | Tanpa email, verifikasi email & reset kata sandi belum berfungsi | Konsol → Integrasi → Email, lalu uji kirim |
| A7 | **SELESAI @ v2.28, v2.32**: persetujuan cookie sebelum GA4/Meta Pixel + tautan "Pengaturan cookie" untuk menarik persetujuan | — | Isi teks kebijakan cookie di dokumen legal |
| A8 | Kontak, WhatsApp, media sosial, logo, dan tautan unduh situs masih kosong | Tombol WhatsApp & blok Kontak belum tampil | Konsol → Situs pemasaran → Pengaturan |
| A12 | **SELESAI @ v2.32** (kode): job CI `rilis` membuat paket `payoung-web-{SHA}.tar.gz` + SHA-256 (PHP tanpa dev + `public/build`) di setiap push `main` | — | Deploy dari artefak CI itu, bukan build manual |
| A9 | TERBUKA: Aplikasi Kasir & Pemilik belum dirilis ke Play Store/App Store/Windows. Sejak v2.32 build rilis Android **gagal** tanpa kunci produksi (`android/key.properties` atau `PAYOUNG_KEYSTORE_*`) | Pengguna belum bisa mengunduh aplikasi kasir | Buat keystore unggah + Play App Signing, ID aplikasi sudah `id.payoung.kasir` / `id.payoung.owner` (v3.16; ganti hanya sebelum rilis pertama, setelah terbit mengganti ID = aplikasi berbeda), build dengan `ALAMAT_SERVER=https://dashboard.payoung.id/`, unggah ke toko aplikasi |
| A10 | Pencadangan otomatis database & file belum diatur di hosting | Risiko kehilangan data | Aktifkan backup harian hPanel + catat di konsol (`pengelola:catat-backup`) |
| A13 | **SELESAI @ v2.38**: pemindai QR kode aktivasi di aplikasi kasir (`PemindaiQr` + `PemindaiQrPlatform`, paket `mobile_scanner`). `mobile_scanner` tidak mendukung Windows, jadi tombol Pindai hanya muncul bila `CekTersedia()` true dan isian manual tetap jalur utama di semua platform | Di Windows kode aktivasi tetap diketik manual | Uji di perangkat Android & iOS nyata (izin kamera, perangkat POS tanpa kamera) |
| A11 | TERBUKA (tidak terulang): satu test (`EksporVarianTes` round-trip) kadang gagal saat seluruh suite dijalankan, lolos bila dijalankan sendiri. **1 Okt 2026:** suite Pest penuh (2.464 test, MySQL 8) dijalankan ulang: `EksporVarianTes` lolos, juga 6× berturut-turut sendirian dan bersama 301 test Katalog; urutan ekspor sudah deterministik (`lazyById`, `orderBy('Id')`). Satu-satunya kegagalan run itu `PenjualanBatchFefoTes` karena memakai `bcadd()` (ekstensi bcmath tidak terpasang di mesin itu) — diganti `Uang` (brick/math) | Indikasi test tidak stabil (urutan/data) | Bila muncul lagi di CI, simpan pesan galatnya (assersi mana yang gagal) sebelum menyelidiki; jangan dilewati |
| A14 | **SELESAI @ v3.25**: test F-17 (toko online, self-order, tandai habis) berjalan hijau di CI (job `Aplikasi Web: PHP (8.3, MySQL 8)` dan MariaDB); tidak lagi bergantung pada MySQL lokal mesin pengembangan | — | Mesin pengembangan ini masih tidak punya dependensi PHP, jadi Pest hanya dijalankan CI |
| A16 | **SELESAI @ v2.88**: layar Riwayat › Pesanan toko online di aplikasi Kasir (muat ke keranjang kanal Online, baris bayar Uang muka dengan `UuidPesananOnline`, 5 test domain + 3 test widget) | — | `PengaturanTokoOnline.QrisAktif` boleh dinyalakan untuk pesanan **ambil sendiri** dan kirim bergratis ongkir; pesanan kirim berongkir masih tertahan butir A17 |
| A17 | **SELESAI @ v2.97–v3.01**: ongkir punya rumah di `Penjualan` (`BiayaKirim`/`DiskonKirim`, peran akun `PendapatanPengiriman`, mesin kalkulasi PHP & Dart + vektor bersama, J-07.1), ongkir terbuka di kasir (v3.00), promo gratis ongkir (v3.01) | — | Pesanan kirim berongkir kini bisa ditagih; ongkir & gratis ongkir di penjualan kasir kanal Antar (bukan pesanan online) **SELESAI @ v3.29** |
| A18 | TERBUKA (tidak terulang): dua golden test Flutter (`GoldenRuangKerja_test.dart`, `RuangKerja_test.dart`, 1280dp) pernah gagal dengan diff 0,03% (288px) di satu mesin pengembangan, bahkan di `HEAD` tanpa perubahan; keduanya lolos pada 1 Okt 2026 | Suite Flutter pernah tidak hijau penuh di lingkungan itu | Bila muncul lagi, simpan diff sebelum menyelidiki perbedaan rendering font antara CI dan mesin lokal. **Jangan** membuat ulang golden-nya untuk meloloskan test |
| A19 | TERBUKA (tindakan di server, bukan kode): rilis v5.22–v5.25 baru berlaku di production setelah `php artisan katalog:terapkan` (harga promo/normal, add-on, fitur paket) dan `php artisan template:terapkan` (19 template sektor langsung terbit; butuh tarif PPN terbit), masing-masing `--kering` dulu, lalu `optimize:clear` & `optimize` | Tanpa itu iklan promo di halaman masuk tidak muncul dan pilihan sektor baru (sayur, gym, cuci kendaraan, pet shop, florist) tidak tersedia di panduan awal | Jalankan sesuai `Panduan/PasangDiHosting.md` §4 |
| A20 | TERBUKA: margin paket Starter Rp 59.000 (harga peluncuran) belum dihitung terhadap biaya WhatsApp, DOKU, dan server; klausul perubahan harga di S&K belum diperiksa | Promo bisa merugi atau perubahan harga ke pelanggan lama bisa dipersoalkan | Hitung biaya per tenant aktif; minta peninjau hukum memeriksa S&K sebelum promo dibuka lebar |
| A21 | TERBUKA (SEO, v5.26): situs belum didaftarkan ke Google Search Console, peta situs belum dikirim, belum ada profil bisnis/sosial bernama sama, konten per jenis usaha baru 3 halaman dan blog masih sedikit | Payoung sulit ditemukan lewat Google selain pencarian nama persis | Ikuti `Panduan/PanduanSEO.md` bagian B–D |
| A15 | **SELESAI @ v2.88 (kode):** `composer analisis` merah di `main` (19 galat PHPStan, 2 berkas Pint), `npx tsc` gagal di `Kelola/TokoOnline/Daftar.tsx`, dan `KontrakApiTes` merah karena dua rute POS pesanan online; ketiganya sudah diperbaiki. | Gerbang CI utama pernah tidak hijau, sehingga bug nyata (mis. `PenjualanPembayaran::AmbilJumlah()` yang tidak ada → pencairan 500) lolos ke produksi | Tersisa memastikan job `Cek Kepatuhan` wajib hijau lewat proteksi branch (butir D1, pengaturan GitHub) |

## A2. Perbaikan teknis yang ditunda (diminta pemilik produk: dikerjakan setelah fitur C)

| # | Pekerjaan | Rincian |
|---|---|---|
| T1 | **SELESAI @ v3.30** (sebagian besar) Kecilkan ukuran tampilan | Situs payoung.id ±169 KB gzip, dashboard ±333 KB saat pertama dibuka. Rencana: CSS situs dipisah dari CSS dashboard, pustaka JS bersama dipecah (situs tidak memuat komponen tabel/kalender), grafik Beranda dashboard dimuat belakangan, logo PNG 60 KB → WebP/SVG, kompresi & cache panjang di `.htaccess`. Target ±80–100 KB (situs), ±200 KB (dashboard). Hasil v3.30: situs ±115 KB (CSS 10 KB), Beranda ±237 KB tanpa recharts; sisa terbesar adalah React + Inertia (±104 KB) yang dibutuhkan semua entri. |
| T2 | **SELESAI @ v3.28** Tampilan Integrasi | Enkripsi tertulis "Ssl" → "SSL"; kata sandi SMTP/rahasia ditampilkan 4 karakter terakhir → sembunyikan penuh untuk kata sandi (kunci API boleh tetap 4 terakhir). |
| T3 | **SELESAI @ v2.32** Build otomatis | Job CI `rilis` (lihat A12). |
| T4 | **SELESAI @ v2.28** Situs pemasaran bagian B | Prospek, persetujuan cookie, analitik dari konsol, blog. |

## B. Fitur yang belum dibangun (urutan kerja berikutnya)

1. ~~Situs pemasaran bagian B~~ — **SELESAI @ v2.28**.
2. ~~F-16d bagian 2 paket sesi~~ — **SELESAI**.
3. ~~Mode jasa (booking, staf)~~, ~~laundry~~, ~~grosir (SO/surat jalan/faktur/retur/cetak dokumen)~~ — **SELESAI**; keputusan grosir ditutup v2.74 dan implementasi lengkap sampai v2.82.
4. ~~Karyawan: geofence absensi~~ — **SELESAI @ v4.19–v4.23** (absensi web: absen ditolak di luar radius outlet yang diatur di Detail outlet › Lokasi absensi dari HP, 20–1.000 m, plus QR berganti di layar outlet). Yang masih terbuka hanya keputusan K36–K38 (kepercayaan pencocokan wajah, ambang 0,60, siapa melihat skor).
5. ~~Laporan anti-fraud~~ — **SELESAI @ v2.27** (atribusi diperbaiki v2.32); ~~persetujuan jarak jauh lengkap di Aplikasi Pemilik~~ — **SELESAI @ v2.81**.
6. ~~**Push notification** (FCM/APNs) untuk Aplikasi Owner~~ — **SELESAI @ v2.81**. Konfigurasi aplikasi Firebase Android/iOS dan uji perangkat nyata tetap langkah rilis eksternal.
7. **Gratis ongkir — SELESAI v2.86:** ambang gratis per zona kode pos dihitung ulang server dari subtotal katalog.
8. Batch & kedaluwarsa, nomor seri (sudah di F-05a); ~~produksi~~ — **SELESAI @ v2.29**; bahan terbuang bagian 1 **SELESAI @ v2.30**.
9. ~~Harga per kanal ojol (input manual)~~ — **SELESAI @ v2.36**; integrasi API resmi ojol tetap fase lanjut.
10. ~~Modul Gudang di aplikasi (penerimaan PO, transfer, opname, termasuk pindai kamera Android/iOS)~~ — **SELESAI @ v2.80**.
11. **Toko online `/{slugTenant}` & kurir — bagian 1 SELESAI v2.86, bagian 2 SELESAI v2.87, layar kasir SELESAI v2.88:** bagian 1 = katalog, varian/modifier, keranjang hitung server, checkout multi-outlet ambil/kirim, bayar saat ambil/COD, zona ongkir + gratis ongkir, pesanan back-office/POS, fulfillment kurir, status publik, kedaluwarsa otomatis, dan butir Kotak Tindakan. Bagian 2 = bayar di muka lewat QRIS web (`TagihanQris` tanpa perangkat, idempoten per pesanan), jurnal uang muka J-17.1, penagihan kasir lewat metode Uang muka + pemulihan saat void, dan pengembalian uang J-17.2 + butir Kotak Tindakan-nya. **Bagian 3 (rincian awal, sebagian besar kini usang):** baris biaya kirim di `Penjualan` + peran akun pendapatan pengiriman (butir A17, prasyarat pesanan kirim berongkir), shift virtual BR-17.1 + penjualan yang dibuat server sendiri (ditunda karena `Penjualan.IdShift` tidak boleh null, jadi menyentuh `TutupShift`/`TutupHarianOtomatis`/`ShiftBelumDitutup`/laporan X-Z), refund otomatis ke gerbang, push POS BR-17.3, pembaruan status dari aplikasi POS, dan agregator ongkir. **Yang masih terbuka kini hanya:** shift virtual BR-17.1 (menunggu keputusan pemilik produk, D-123), refund otomatis ke gerbang (pengembalian J-17.2 dicatat, bukan dijalankan), dan agregator ongkir; pembaruan status dari aplikasi POS **SELESAI v3.33**, sedangkan push FCM ke kasir sengaja ditunda karena polling 10 detik sudah memenuhi BR-17.3. (**Selesai:** biaya kirim v2.97–v3.00, tandai habis/86 v3.21–v3.24, **akun pembeli opsional dengan kode WhatsApp terintegrasi data Pelanggan v3.31** — harga tier & promo pelanggan di checkout, riwayat & poin di Akun saya, pelanggan ikut terpasang di kasir; **voucher berkode di checkout v3.46**; **cadangan stok pesanan online v3.48**.)
12. ~~Billing langganan semi-otomatis lewat Midtrans~~ — **SELESAI @ v2.72–v2.73** (bayar online, webhook, pelunasan idempoten; gerbangnya kini DOKU sejak v5.12). Otomatisasi penerbitan tagihan berulang penuh tetap F-19 lanjutan.
13. **Fase 3 lainnya:** Open API + webhook + portal developer (token API & endpoint baca selesai v3.82; webhook keluar penjualan/void/retur selesai v3.83; portal pengembang `/pengembang` + OpenAPI selesai v3.84; peristiwa webhook produk, pelanggan, shift, penyesuaian stok, PO & GRN selesai v3.91; endpoint tulis `stok:tulis` (penyesuaian stok) selesai v3.95; `pembayaran.diterima` & `stok.menipis` selesai v4.07; pengumuman & banner pemeliharaan P-10 PGL-19 selesai v3.45, kampanye WA/email bersegmen CRM-07 selesai v3.44, landed cost pihak ketiga selesai v3.41, konsinyasi v3.40, rekonsiliasi bank v3.39, aset tetap v3.38), bengkel, template sektor lengkap (selesai v3.80; Apotek & Bengkel v3.89), e-Faktur/Coretax (ekspor XML Faktur Pajak Keluaran selesai v3.12, rekap nota retur v4.08; berkas impor resmi nota retur belum, K35/K22), Salesman (server, API & back-office kunjungan selesai v3.85; ruang kerja Salesman di aplikasi Kasir v3.87; kanvas = outlet kendaraan + rekap harian v3.88), insight (analisis ABC, menu engineering, saran restock selesai v3.43; faktor musiman Ramadan/Lebaran v3.78; insight mingguan email v3.79), mode LAN (**DITUNDA**, K-28), portal mitra eksternal (P-12 fase 3; master mitra, atribusi tautan, komisi & pencairan di konsol selesai v3.50).
14. **Kios pesan sendiri di web (D-80) — bagian pertama SELESAI v5.00:** layar sentuh `/{slug}/kios/{token}`, nomor antrian K001, bayar di kasir atau QRIS, layar antrian, tautan rahasia per outlet. **DITUNDA sampai ada tenant pemakai kios yang memintanya (bukan kekurangan; semuanya opsional per outlet dan tidak boleh berlaku untuk outlet tanpa kios, tanpa KDS, atau tanpa nomor antrian):** tiket dapur/KDS sebelum kasir menerima pesanan (hanya outlet yang punya KDS/stasiun dapur), cetak nomor antrian kios di printer outlet (default mati), mode kios terkunci (kiosk browser/PWA), multibahasa. **Belum diuji:** layar Kasir belum menampilkan nomor antrian secara khusus (namanya "Kios K001 | …"); belum diuji di tablet sungguhan.
15. **DITUNDA — Kirim retur Toko → Gudang dari aplikasi Kasir (D-96):** di back-office sudah ada lewat tombol "Retur ke Gudang" di Transfer stok (v5.33). Menu Stok › Gudang di Kasir baru menerima (PO, transfer masuk, opname). Menambah "Kirim ke Gudang" butuh endpoint POS baru (buat + kirim transfer idempoten per `Idempotency-Key`, pilih produk + batch/nomor seri), draf lokal, dan izin; dikerjakan hanya bila ada permintaan nyata dari toko. Berlaku hanya untuk outlet yang punya lokasi stok berjenis **Gudang** terpisah dari Toko dan staf berizin transfer; tombolnya tersembunyi di luar itu, dan tidak terikat sektor (jangan menyimpulkan sektor mana yang memakainya tanpa memeriksa data tenant). Pemakaian sehari-hari lebih sering dikerjakan admin gudang dari back-office.
16. **Pusat Unduhan (D-124) — SELESAI v5.65** untuk laporan penjualan, daftar penjualan, stok, umur piutang/hutang, komisi, dan buku besar. **DITUNDA (tidak diperlukan sekarang):** laporan berbatas tetap 5.000 baris (obat resep, kunjungan sales, nomor seri) dan laporan pajak yang ringkas tidak ditawari ekspor besar; tambahkan hanya bila datanya terbukti melewati batas. Penyiapan berjalan di akhir permintaan yang sama (bukan antrean), jadi butuh PHP-FPM/LiteSpeed.

## C. Keputusan yang menunggu pemilik produk

Semua keputusan yang menunggu kini dikumpulkan di satu berkas: [`KeputusanMenunggu.md`](KeputusanMenunggu.md).

## D. Temuan audit 27-09-2026 yang butuh tindakan di luar kode

| # | Butir | Status |
|---|---|---|
| D1 | Proteksi branch `main` (wajib PR, review CODEOWNERS, wajib semua job `Cek Kepatuhan`, blok force-push/hapus) dan jadikan `main` branch default | TERBUKA (pengaturan GitHub pemilik repo) |
| D2 | ~~Pastikan mesin DB produksi~~ | SELESAI (3 Okt 2026, butir A2): produksi memakai MySQL; job `backend-mariadb` tetap informasi, tidak perlu jadi matriks wajib |
| D3 | Backup DB & file + uji restore berkala (butir A10) | TERBUKA |
| D4 | Kunci unggah Android + Play App Signing; ID aplikasi final | TERBUKA (butir A9) |
| D5 | Isi `SITUS_KUNCI_SIDIK` (kunci HMAC sidik prospek terpisah dari APP_KEY) di lingkungan produksi | TERBUKA |
| D6 | Verifikasi header keamanan di tepi (Cloudflare/web server); aplikasi sudah mengirim CSP dasar, HSTS (HTTPS), nosniff, Referrer-Policy, Permissions-Policy sejak v2.32 | TERBUKA |

## Catatan audit 1 Oktober 2026 (commit `be3ad096`)

Diverifikasi terhadap kode (bukan hanya dibaca ulang). **Sudah diperbaiki (v3.15–v3.16):** ekspor Coretax tidak lagi Error di brick/math 1.0.0 (`AngkaCoretaxTes`); Prettier `Laporan/Stok.tsx`; basis data SQLite uji dicabut dari Git; `concurrency` + penjaga SHA pada rilis `aset-terbaru`; analisis & Pest, serta empat langkah Flutter, sebagai langkah terpisah; job informasi `backend-mariadb` (MariaDB 11.8); workflow Laravel bawaan yang mati dihapus; trusted proxy lewat `PROKSI_TEPERCAYA` (§7a Panduan Pasang); CSP ketat **Report-Only** + penerima `/laporan-csp`; webhook billing memeriksa tanda tangan sebelum balasan uji; guard `IdTenant` di `MilikTenant` (dicatat kritis, ditolak bila `TENANT_TOLAK_ID_BERBEDA=true`); perintah mingguan `tenant:periksa-silang` (rujukan foreign key lintas tenant harus nol); pelanggaran strict mode Eloquent kini tercatat di log produksi; peringatan kritis bila produksi berjalan dengan nilai contoh pengembangan (nilai produksi di §7b); ID aplikasi mobile `id.payoung.*`; nama workspace Dart; penjaga CI versi dokumen ini terhadap PRD.

**Masih TERBUKA (butuh keputusan, infrastruktur, atau pengukuran):**
- **Naikkan CSP Report-Only menjadi penegak** setelah `/laporan-csp` bersih beberapa minggu; nyalakan `TENANT_TOLAK_ID_BERBEDA=true` setelah log `Penulisan lintas tenant terdeteksi` bersih.
- **Foreign key gabungan `(IdTenant, Id)`** di seluruh tabel (migrasi besar; sementara dijaga detektor mingguan di atas).
- **Pin action GitHub ke SHA** (Dependabot `github-actions` sudah memantau tag), **gate coverage** untuk domain uang/jurnal/stok, **pecah bundle**, **public/build tidak lagi di Git** (diganti artefak rilis).
- ~~**MariaDB vs MySQL** (A2)~~ — **SELESAI**: diputuskan MySQL (3 Okt 2026); job `backend-mariadb` hanya informasi.
- **Backup & restore drill** (A10), **uji beban** (A3), **legal/DPA** (A5), ~~berkas LICENSE~~ (sudah ada, `LICENSE` berisi lisensi proprietary dan `composer.json` bertuliskan `proprietary`; sisa: tinjauan hukum atas naskahnya), deskripsi repository GitHub.
- **Migrasi ke server sendiri tanpa henti** (permintaan pemilik 3 Okt 2026): rencana & prasyarat di `Panduan/MigrasiServer.md` (DNS lewat Cloudflare, disk berkas ke penyimpanan objek, `APP_KEY` disimpan aman, cek hak replikasi di hosting).
- **Ekspor Coretax belum divalidasi ke aplikasi resmi** (impor satu faktur) dan status CI Flutter belum diverifikasi (golden 1280dp, A18).

## K. Celah aplikasi kasir — audit 2 Oktober 2026

Hasil pemindaian aplikasi kasir (`Aplikasi/Kasir/lib`) terhadap PRD §8 F-07, §9 per sektor, §17.2, §18, dan §19, setelah
pemilik produk menilai kasir "tertinggal" (contoh: FnB tidak bisa memilih jenis pesanan). Setiap butir sudah dicek ke
kode. Urutan pengerjaan = urutan tabel; butir yang selesai diberi **SELESAI @ versi**.

### K-a. Kritis untuk operasional

| # | Celah | Bukti / PRD | Status |
|---|---|---|---|
| K-1 | **Jenis pesanan FnB** (Makan di tempat / Bawa pulang / Antar) tidak bisa dipilih di kasir; tanpa mode meja semua penjualan tercatat Bawa pulang. Pilihan kanal hanya muncul bila ada kanal ojol/harga berkanal | `LayananPenjualan.AmbilPilihanKanal`; §9.1–§9.2 | SELESAI @ v3.51 |
| K-2 | **Nomor antrian / nama pemesan** untuk penjualan bayar-dulu (QSR) tidak ada; struk & tiket dapur hanya nomor INV; layar panggil antrian tidak ada | §9.2 | SELESAI @ v3.52 (layar panggil antrian @ v3.81) |
| K-3 | **Cetak tagihan sementara (pre-bill)** untuk pesanan meja tidak ada | §9.1 | SELESAI @ v3.53 |
| K-4 | **Keranjang hilang bila aplikasi tertutup** (hanya di memori; PRD minta tersimpan di SQLite) | `Penyedia.dart` `PengaturKeranjang`; §17.2.7 | SELESAI @ v3.54 |
| K-5 | **Barcode timbangan** (awalan 20–29, harga/berat di EAN-13) tidak dikenali | `KatalogLokal.CariKode`; §9.3, F-03 | SELESAI @ v3.55 |
| K-6 | **Pemicu sinkron belum lengkap**: hanya timer 30 detik di ruang kerja; tidak saat aplikasi kembali ke depan, koneksi kembali, atau di layar kunci/pilih kasir | `RuangKerja.dart`, `GerbangKasir.dart`; §18.3 | SELESAI v3.56 (`SinkronkanSegera`: kembali ke depan, koneksi pulih, layar pilih kasir & buka shift) |
| K-7 | **Basis data lokal tidak terenkripsi** (SQLCipher) | `Persiapan.dart`; §17.2.6 | SELESAI v3.57 (SQLite3 Multiple Ciphers, semua perangkat, berkas lama dienkripsi di tempat) |

### K-b. Penting

| # | Celah | Bukti / PRD | Status |
|---|---|---|---|
| K-8 | `ModeKasir` template sektor (Retail/Cepat/Meja/Layanan/Grosir) tidak pernah sampai ke kasir; tata letak sama untuk semua sektor | §5.1, §9 | SELESAI v3.58 (beranda Meja, katalog daftar Retail/Grosir, pengali `12*kode`) |
| K-9 | **Pemilih varian** (ukuran × warna) di kasir tidak ada; produk induk varian ditolak | `KatalogLokal.dart`; §9.4 | SELESAI v3.59 (`PanelVarian`, skema lokal 23) |
| K-10 | **Cek harga** tanpa menambah ke keranjang | §9.3 | SELESAI v3.60 (`PanelCekHarga`, F4) |
| K-11 | **Retur tanpa struk & tukar barang** satu layar | `LembarRetur.dart`; §9.3–§9.4 | SELESAI v3.61–v3.62 (tukar barang server + layar kasir; retur tanpa struk: K28 diputuskan, server & back-office v4.01, layar kasir v4.02) |
| K-12 | Status meja **minta bill / perlu dibersihkan** | `LayarMeja.dart`; §9.1 | SELESAI v3.63 (`MintaBill` di `PesananTerbuka.Ubah`, outbox `Meja.Bersih`, skema lokal 24) |
| K-13 | **Course / tahan & kirim** (hold & fire) per kursus | §9.1 | SELESAI v3.64 (`Baris[].Kursus`, "Tahan Utama" & "Kirim Utama") |
| K-14 | **Bagi tagihan rata per orang / per nominal** (sekarang hanya per item, khusus meja) | BR-08.2, §9.1 | SELESAI v3.65 (tombol Bagi tagihan di Bayar); struk per tamu SELESAI @ v5.68 |
| K-15 | Umpan balik pindai (suara + getar, sorot baris) | §17.2.7 prinsip 4 | SELESAI v3.66 (`UmpanBalikPindai`, sorot `BarisKeranjang`, sakelar di Pengaturan) |
| K-16 | Produk **favorit/terlaris** di atas katalog, kategori terakhir diingat, daftar pintasan `?` | §17.2.7 | SELESAI v3.67 (kategori Terlaris lokal, kategori diingat, `?`); favorit pilihan pemilik SELESAI @ v5.68 |
| K-17 | Layar status sinkron: waktu sinkron terakhir, umur outbox tertua (> 2 jam), transaksi **PerluTinjauan**, peringatan jam perangkat | §18.3 butir 7 & 10 | SELESAI v3.68 (`PerluTinjauan` di jawaban sinkron, `CatatSinkron`) |
| K-18 | **Buka ulang shift** oleh supervisor, **foto bukti** kas masuk/keluar | F-06, §19.1 | SELESAI @ v3.70 (buka ulang v3.69, foto bukti v3.70) |
| K-19 | Info batch/kedaluwarsa saat jual (FEFO hanya di server) | §9.5 | SELESAI @ v3.71 |
| K-20 | Buat **booking salon dari kasir** + kalender slot per staf | §9.8 | SELESAI @ v3.72 |
| K-21 | Log lokal & pelaporan galat (Sentry) | §17.2.6 | SELESAI @ v3.73 (kanal mandiri; Sentry menunggu K29) |
| K-22 | Izin retur terpisah dari void | F-09 | SELESAI @ v3.74 |

### K-c. Tambahan / sektor baru (lihat K25 di `KeputusanMenunggu.md`)

| # | Celah | Status |
|---|---|---|
| K-23 | Mode latihan (POS-18) | SELESAI @ v3.75 |
| K-24 | Riwayat lebih dari hari ini & ringkasan akhir hari per outlet di perangkat | SELESAI @ v3.76 |
| K-25 | Item harga terbuka / item kustom | SELESAI @ v3.77 |
| K-26 | Sektor **Apotek** (golongan obat, resep, peran Apoteker, racikan, embalase) | SELESAI @ v3.89–v3.94 (server, back-office & kasir; racikan server v3.93, racikan di aplikasi kasir v3.94) |
| K-27 | Sektor **Bengkel** (kendaraan, work order jasa + sparepart, mekanik per jasa) | SELESAI @ v3.89–v3.92 (server, back-office & tagih WO di kasir; sparepart ber-batch/seri di WO v3.92) |
| K-28 | Mode LAN / Outlet Hub (§18.5, fase 3), layar pelanggan monitor kedua Windows, pembaruan otomatis Windows | DITUNDA |

## E. Temuan audit ujung-ke-ujung 12 Oktober 2026 (PRD v5.48–v5.64)

Hasil empat penelusuran baca-kode (gerbang fitur paket, halaman & rute yatim, janji "menyusul", alur lintas modul). Yang pasti salah dan tidak butuh keputusan produk sudah diperbaiki di v5.48–v5.49; tabel ini mencatat seluruh temuan beserta status penyelesaiannya; hanya E8 yang masih SEBAGIAN (shift virtual menunggu keputusan pemilik produk, D-123). Semua butir terbaca dari kode dan belum dijalankan di produksi kecuali dinyatakan lain.

| # | Butir | Status |
|---|---|---|
| E1 | ~~**Paket tidak ditegakkan di server.** Fitur hanya digembok di menu; tenant Gratis/Starter bisa membuka URL-nya langsung (bertentangan dengan BR-P04.3).~~ | SELESAI @ v5.51 (D-111: `PetaFiturRute` + `WajibFiturPaket` untuk `/kelola`, API POS, dan API Owner; sakelar masa peralihan `TENANT_PENEGAKAN_FITUR_PAKET`). Belum dijaga karena tak punya kunci fitur sendiri: POS pelanggan API, modul piutang/salesman, laporan ABC/menu engineering |
| E2 | ~~Fitur dijual tetapi kosong~~: `franchise.royalti` dikeluarkan dari katalog (migrasi 000182), add-on `INSIGHT` ditegakkan (v5.51), dan `BatasPenyimpananMb` kini diukur dan ditegakkan pada gambar produk & lampiran (v5.56, D-116). | SELESAI @ v5.56 (jalankan `php artisan penyimpanan:ukur --semua` sekali setelah rilis) |
| E3 | ~~Template sektor menjanjikan fitur di luar paket yang wajar.~~ | SELESAI @ v5.51 (opsi B: template tidak diubah; tenant di luar paket melihat gembok + dialog naik paket/add-on, server menolak aksi tulis fitur itu) |
| E4 | ~~Tagihan Rp 0 (kupon 100%) melempar `TotalNol`; paket Enterprise tidak punya aksi tagih/catat bayar manual.~~ | SELESAI @ v5.52 (D-112: tagihan Rp 0 langsung lunas metode `Kupon`; tagihan manual + catat pembayaran langsung di konsol) |
| E5 | Turun paket mandiri (BR-P04.4), pindah ke Gratis saat ditangguhkan, dan peringatan kelebihan outlet/pengguna/SKU SELESAI @ v5.57 (D-117). Ekspor data lengkap usaha SELESAI @ v5.58 (D-118). Tutup akun dan penghapusan data (UU PDP, masa tunggu 14 hari, anonimisasi, bukti email) SELESAI @ v5.59 (D-119). | SELESAI @ v5.57–v5.59 |
| E6 | ~~Status `Dihapuskan` dan `Dikembalikan` tidak bisa dicapai; unggah bukti transfer tanpa rute/tombol; pembayaran gateway `Menunggu` > 7 hari tidak ditutup~~ | SELESAI @ v5.52 (hapus-buku, refund dengan persetujuan Super Admin di atas Rp 1.000.000, unggah bukti hanya untuk tagihan manual) dan @ v5.50 (arsip pembayaran lama). Sisa: refund sebagian dan nota kredit |
| E7 | ~~Penjualan POS ditolak server karena `TarifPajakTidakSah` tanpa jalan koreksi di kasir; kasir yang dicabut aksesnya saat offline membuat shift/penjualan tertahan permanen di outbox (K33).~~ | SELESAI @ v5.55 (D-115: tarif yang baru berakhir ≤ 30 hari = diterima + `TarifPajakLama`; kas dari pencatat yang izinnya berubah = diterima + `IzinBerubah`; `Shift.Buka` oleh pengguna yang dicabut tetap ditolak) |
| E8 | Pesanan online berbayar di muka baru menjadi penjualan saat kasir menagih (shift virtual BR-17.1 belum ada — pengingat Kotak Tindakan selesai v5.63; shift virtual menunggu keputusan pemilik produk, D-123); ~~pembayaran QRIS terlambat tanpa penjualan (`PerluTinjauan`) tidak punya aksi penutup/jurnal~~ (selesai v5.61, BR-08.8); ~~piutang tidak bisa dihapus-buku~~ (selesai v5.60, BR-12.8); ~~retur belanja stok & retur untuk faktur yang sudah dibayar ditolak~~ (selesai v5.62, BR-04.9). | SEBAGIAN @ v5.60 (hapus-buku piutang), v5.61 (QRIS tanpa penjualan), v5.62 (retur pembelian ber-refund), v5.63 (pengingat pesanan online berbayar belum ditagih); sisa: shift virtual pesanan online (keputusan pemilik produk, D-123) |
| E9 | ~~Aplikasi Kasir: daftar produk "Habis" hilang saat restart offline; indikator "data terakhir diperbarui" tidak ditampilkan; Aplikasi Pemilik tanpa "Lupa kata sandi"~~ | SELESAI @ v5.50 (Dart dikompilasi & dites; Flutter ada di `/opt/flutter`) |
| E10 | ~~Event `TarifPajakTerbit` tanpa pendengar~~ SELESAI @ v5.56 (Kotak Tindakan `pajak.tarif.*`). Rute `POST pesanan-online/{uuid}/tautkan` dan metode klien `TautkanPesananOnline` tetap sebagai kompatibilitas mundur (CLAUDE.md #16) sampai dua versi minor berlalu. ~~Perintah baru di `PasangDiHosting.md`~~ SELESAI @ v5.50. | SELESAI @ v5.56 |
| E11 | ~~Teks situs menyebut "cadangan rutin" padahal cadangan otomatis hosting belum diatur (lihat A10); tombol "Unduh aplikasi" di situs mengarah ke `/kontak`~~ | SELESAI @ v5.64 (teks diluruskan, tombol memakai `@unduh-android`); pencadangan otomatis sendiri = A10/D3 (infrastruktur) |


## F. Audit kelengkapan PRD terhadap kode — 12 Oktober 2026 (PRD v5.72)

Dipicu temuan bahwa cetak label (PRD-10) tercantum di PRD tetapi tidak pernah dibangun (kini selesai, D-129). Tujuh penelusuran baca-kode membandingkan PRD (§8 daftar fitur, §9 per sektor, §10.10 laporan, flow F-xx dan P-xx) dengan isi repo.

> **Diverifikasi ulang 13 Oktober 2026 (PRD v6.16): lihat bagian G di bawah. Bila status di bagian F berbeda dengan bagian G, bagian G yang berlaku.**

**Batas audit (baca sebelum memakai tabel ini):** penelusuran hanya memeriksa **ada atau tidaknya kode**, bukan apakah fiturnya benar; tes tidak dijalankan. Tiga butir terbesar (jurnal umum manual, akses dukungan, gift card/feedback) diperiksa ulang dengan pencarian nama dan tidak ditemukan, tetapi pencarian itu berbasis tebakan nama. **Sebelum mengerjakan satu butir, verifikasi dulu ke kode dan PRD** (jangan menyalin status di sini sebagai fakta). Butir yang sudah ada di bagian A–E atau `KeputusanMenunggu.md` tidak diulang kecuali perlu.

Status: **TERBUKA** = belum dikerjakan; **MENUNGGU KEPUTUSAN** = ada di `KeputusanMenunggu.md`; **DITUNDA** = sengaja ditunda PRD/pemilik produk; **?** = subagen tidak yakin, periksa dulu. Urutan usulan ada di akhir bagian ini.

### F-a. Belum ada sama sekali (bobot tinggi)

| # | Fitur (ID PRD) | Catatan | Status |
|---|---|---|---|
| F1 | Akses dukungan berizin + alat bantu dukungan di konsol (PGL-16, P-09) | P0, Fase 1 "sebelum beta tertutup" | **SELESAI @ v5.76 (D-132, D-133)**: akses dukungan berizin + akses darurat + penyamaran + jejak (v5.74); alat bantu dukungan BR-P09.3 di konsol: status perangkat & outbox, cabut perangkat, minta/baca log, bangun ulang saldo stok & ringkasan harian, kirim ulang pesan WhatsApp & email verifikasi, semua dengan hasil sebelum/sesudah. Ditunda: dorongan push untuk meminta log dari perangkat yang lama offline |
| F2 | Jurnal umum manual (FIN-05) | **SELESAI @ v5.73 (D-131).** `/kelola/akuntansi/jurnal-umum/buat`, tombol di halaman Jurnal; akun kendali ditolak; koreksi lewat dokumen pembalik. Tidak dibuat: persetujuan kedua (four-eyes) dan butir Kotak Tindakan "jurnal umum belum ditinjau" (menunggu keputusan pemilik produk) | SELESAI |
| F3 | OTP WhatsApp saat daftar (PLT-01, F-00) | | TERBUKA |
| F4 | Aplikasi Owner: cek stok/kartu stok (OWN-06), aksi cepat: ubah harga, tandai habis, promo, pengeluaran berfoto (OWN-07), anti-fraud (OWN-09) | P1; anti-fraud sudah ada di web | TERBUKA |
| F5 | Skor kesehatan tenant (PGL-09, SELESAI @ v5.96), insiden + halaman status publik (PGL-21, SELESAI @ v5.95), analitik platform (PGL-24, SELESAI @ v5.99), template komunikasi (PGL-07, SELESAI @ v6.03 untuk dunning tagihan langganan; peristiwa lain belum), help center (PLT-12: Pusat Bantuan + bantuan kontekstual SELESAI @ v6.02; tur produk & live chat belum) | P1 | TERBUKA SEBAGIAN |
| F6 | Gift card / voucher bernilai (CRM-06) | | MENUNGGU KEPUTUSAN (K4) |
| F7 | Feedback/rating pasca transaksi (CRM-08) | **SELESAI @ v5.87**: bintang 1–5 + komentar di struk digital (satu per transaksi, 30 hari, saklar per outlet), tab Umpan balik di laporan penjualan | SELESAI |
| F8 | Anggaran vs realisasi (FIN-11) | **SELESAI @ v5.88**: anggaran per akun × bulan × tahun untuk seluruh usaha atau satu outlet, salin dari tahun lalu, laporan selisih/capaian/status + ekspor | SELESAI |
| F9 | PPh 21 & BPJS otomatis (EMP-07) | P3 | MENUNGGU KEPUTUSAN (K9) |
| F10 | Portal mitra (PGL-23); portal pemasok (INV-15) | portal pemasok P3; mitra: master/komisi sudah ada (v3.50) | TERBUKA / sebagian K24 |
| F11 | Multi-bahasa POS (POS-19) | | TERBUKA |
| F12 | Mode LAN (POS-24); widget & langganan Owner (OWN-12, OWN-13) | POS-24 = K-28; OWN-12/13 masuk Fase 4 | DITUNDA |

### F-b. Dibangun sebagian

| # | Fitur (ID PRD) | Yang kurang |
|---|---|---|
| F13 | OWN-01 login Owner | tanpa OTP, PIN, atau biometrik |
| F14 | OWN-03 push Owner | pesanan online tanpa pemicu; void/diskon/refund tidak real-time; konfigurasi push iOS kurang |
| F15 | OWN-04 persetujuan di Owner | PO dan penyesuaian stok tidak bisa disetujui dari Owner |
| F16 | OWN-05 laporan di Owner | tanpa laba-rugi sederhana |
| F17 | OWN-08 perangkat di Owner | tidak bisa mencabut perangkat |
| F18 | PGL-18 / P-10 | flag fitur tidak dipakai kasir; Owner tanpa versi minimum dan flag |
| F19 | POS-20 / POS-21 / POS-22 / POS-23 | tanpa jalur build/rilis Windows di CI; build iOS belum terbukti; tanpa adaptor SDK vendor / pemindai bawaan; tanpa pembaruan otomatis desktop |
| F20 | SLS-04 self-order | **SELESAI @ v6.05**: bayar QRIS dari HP tamu (jalur `PesananOnline` Sumber Meja) dan menu disegarkan tiap 30 detik. Belum: pesanan lunas belum otomatis masuk pesanan terbuka meja/dapur (kasir menerimanya) |
| F21 | PRD-11 ketersediaan per kanal | SELESAI @ v6.01: tanda habis per outlet × kanal (Kasir, Toko online, Self-order & kios); kanal marketplace menunggu sinkron menu |
| F22 | INV-07 opname | SELESAI @ v6.00: opname per rak (`RakGudang`, `ProdukRak`), beberapa penghitung berbarengan, ringkasan penghitung |
| F23 | INV-09 kedaluwarsa | **SELESAI @ v5.77**: push H-30 / H-7 / lewat ke Aplikasi Owner, satu kali per batch per tingkat (`BuatNotifikasiKedaluwarsa`) |
| F24 | INV-13 draf PO | **SELESAI @ v5.86**: sumber `Perkiraan` (laju pemakaian 28 hari × cakupan 7/14/30 hari) selain stok minimum |
| F25 | SLS-10 ojol | hanya input manual (rekonsiliasi pencairan API menyusul) |
| F26 | TAX-05 | **SELESAI @ v5.85**: PPN masukan per bulan × tarif × status kredit, dikurangi retur pembelian, + selisih kurang/lebih bayar di Laporan pajak |
| F27 | TAX-06 / TAX-07 | ekspor Coretax belum divalidasi (K22, K35); faktur pajak per transaksi POS tidak ada |
| F28 | FIN-12 | **ekspor jurnal CSV/Excel umum SELESAI @ v5.91**; format khusus Accurate, Jurnal.id, Zahir belum (belum diverifikasi) |
| F29 | PGL-01 allowlist IP | DITUNDA |
| F30 | PGL-03 template | **SELESAI @ v6.04**: pratinjau sandbox (tenant sementara yang dibatalkan, 5 pemeriksaan) dan tawaran pembaruan ke tenant (Kotak Tindakan + Pengaturan, aditif). Belum: produk contoh tidak ikut pembaruan |
| F31 | PLT-10 / PLT-08 | **SELESAI @ v5.89 (impor pemasok) dan v5.90 (pusat notifikasi web: lonceng, halaman, bisukan jenis; pengaturan per outlet belum)** |

### F-c. Laporan §10.10 yang kurang

~~Per pelanggan~~ (SELESAI @ v5.80); ~~perbandingan outlet~~ (SELESAI @ v5.80; merek tersembunyi D-76); slow/fast moving; ~~pembelian per pemasok, per produk, dan PO outstanding~~ (SELESAI @ v5.82); ~~food cost teoretis vs aktual~~ (SELESAI @ v5.83); ~~waktu saji KDS~~ (SELESAI @ v5.83); performa kasir; perbandingan outlet/brand; ~~prediksi penjualan~~ (SELESAI @ v5.84); ~~rekomendasi harga/bundling~~ (SELESAI @ v5.84). Status: **SELESAI** (gelombang v5.80–v5.84).

### F-d. Per sektor (§9)

| Sektor | Belum ada |
|---|---|
| FnB | minimum charge; reservasi meja + DP; denah meja seret-lepas (kolom `PosisiX`/`PosisiY` di `Meja` ada tetapi tidak dipakai) |
| Fashion | koleksi/musim; input stok grid ukuran × warna |
| Apotek | HNA tersimpan; embalase/tuslah |
| Servis | servis elektronik untuk unit non-kendaraan; resep untuk produk Jasa (salon); rebooking/follow-up salon; DP reservasi; antrian walk-in + booking satu tampilan |
| Grosir | armada/rute dan konfirmasi terima; ongkir per jarak; harga per pelanggan individual; update harga massal per kategori |
| Bakery | ~~rencana produksi harian~~ (SELESAI @ v5.98); diskon sore berbasis kedaluwarsa |

### F-e. Flow tenant (F-xx) yang belum

Bahan resep dikurangi dari lokasi produksi Dapur/Bar (BR-05.4, SELESAI @ v5.79) | ~~Permintaan Pembelian (PR)~~ (SELESAI @ v6.06) | ~~Nota Debit~~ (SELESAI @ v6.07, D-135) | kirim PO lewat WA (SELESAI @ v5.92; email tidak dibuat, D-33) | penyesuaian HPP untuk selisih harga faktur | shift virtual BR-07.4/BR-17.1 (MENUNGGU KEPUTUSAN, D-123) | stok berkurang saat kirim ke dapur (opsi) | void item masuk laporan + opsi waste (SELESAI @ v5.78) | izin harga manual kasir `pos.harga.timpa` | jam operasional outlet (SELESAI @ v5.93) | kartu dashboard piutang/anomali | pola anti-fraud tambahan + push ke pemilik | gabung pelanggan ganda (K1) | hadiah produk dari poin | membership (K5) | kondisi promo per brand | stacking promo `combinable_with`/`cost_center` | bonus & kedaluwarsa deposit (K2, K3) | transfer paket sesi | invoice PDF & faktur pajak langganan (F-19) | pre-order dari back-office/online | DP bertahap | komisi bertingkat, target harian/per kategori | cuti/izin (SELESAI @ v5.94; pengajuan oleh karyawan & jatah tahunan belum) | potongan selisih kas di gaji | pencocokan wajah di absensi kasir.

### F-f. Flow Platform Pengelola (P-xx) yang belum

Override tarif pajak daerah (BR-P02.3) dan pemberitahuan tarif daerah | ganti paket manual (P-07) | tampilan 360° tenant tanpa tab tagihan/tiket/perangkat (SELESAI @ v5.97) | tiket lewat email/WA | tombol Bantuan di Kasir/Owner dengan konteks otomatis | eskalasi L1 → L2 → Teknis dan SLA jam kerja | masking data (BR-P09.1) | metrik P-11: error rate/p95, outbox macet per perangkat, crash-free sessions, DB per tenant, alert WA/Telegram, runbook | hook CI unggah build (P-10) | pendaftaran mitra mandiri, verifikasi, kontrak (P-12) | pembukuan platform (BR-P08.3) | Sentry & uptime (K29).

### F-g. Selisih dokumen & butir meragukan

- Teks PRD P-05 masih menyebut Midtrans/Xendit dan lainnya padahal D-82 hanya DOKU (selisih dokumen, bukan fitur hilang).
- **?** Ringkasan pagi INV-09, kamera web di opname INV-07, impor harga Excel PRD-09, SEO/SSR SLS-05, kanal email CRM-07, TAX-07 untuk penjualan non-grosir, peran per sektor di F-01.

### F-h. Urutan pengerjaan yang diusulkan (menunggu persetujuan pemilik produk)

1. ~~F2 jurnal umum manual (FIN-05)~~ — SELESAI @ v5.73. 2. ~~F1 akses dukungan berizin + alat bantu (PGL-16)~~ — SELESAI @ v5.76. 3. ~~Void item di laporan + opsi waste~~ — SELESAI @ v5.78; ~~notifikasi H-30/H-7 (F23)~~ — SELESAI @ v5.77; BR-05.4 lokasi produksi resep — SELESAI @ v5.79. 4. F4 + F15 di Aplikasi Owner. 5. ~~Laporan §10.10 (F-c)~~ — SELESAI @ v5.84. 6. F3 OTP WhatsApp registrasi.

Tiap butir dikerjakan terikat ID flow (`/mulai-flow`), dengan tes terdampak, PRD (versi baru + D-xxx), dan status di `CLAUDE.md`; setelah selesai, ubah statusnya di sini menjadi SELESAI @ versi.


## G. Verifikasi ulang PRD terhadap kode — 13 Oktober 2026 (PRD v6.16)

Lima penelusuran baca-kode (tes tidak dijalankan; "ADA" berarti kodenya ditemukan, bukan terbukti benar) membandingkan **seluruh 145 ID fitur §10**, flow P-xx/F-xx beserta aturan bisnisnya, dan fitur per sektor §9 dengan repo. Setiap klaim lama di bagian F diperiksa ulang.

**Hasil per kelompok ID**

| Kelompok | ID | ADA | SEBAGIAN | BELUM |
|---|---|---|---|---|
| PLT, CRM, EMP | 27 | 22 | 4 | 1 |
| FIN, TAX, INV | 34 | 32 | 1 | 1 |
| OWN, POS | 38 | 20 | 11 | 7 |
| PGL, SLS, PRD | 46 | 38 | 7 | 1 |
| **Jumlah** | **145** | **112** | **23** | **10** |

**Aturan bisnis:** dari 137 BR di flow, hanya **BR-P02.3** (override tarif pajak daerah oleh tenant + sinyal ke konsol + pemberitahuan tarif daerah) dan **BR-P08.3** (pembukuan pendapatan platform) yang benar-benar tidak ada. BR lain yang tak bersitasi di kode ternyata terimplementasi.

**10 ID yang BELUM:** EMP-07 PPh 21 & BPJS (K9) | INV-15 portal pemasok | OWN-06 cek stok di Owner | OWN-07 aksi cepat di Owner | OWN-09 anti-fraud di Owner | OWN-12 widget layar utama (ditunda) | OWN-13 langganan di Owner (ditunda) | POS-19 multibahasa | POS-24 mode LAN (ditunda) | PGL-23 portal mitra eksternal.

**23 ID yang SEBAGIAN (yang kurang saja)**

| ID | Yang kurang | Ukuran |
|---|---|---|
| PLT-01 | OTP WhatsApp saat daftar (infrastruktur WA sudah ada) | sedang |
| PLT-08 | pengaturan notifikasi per outlet/kanal | kecil |
| PLT-12 | tur produk interaktif, live chat | sedang / besar |
| CRM-06 | gift card bernilai (menunggu K4) | besar |
| TAX-07 | faktur pajak per transaksi kasir ritel (input NPWP/NIK pembeli, NSFP); hanya ada untuk faktur grosir | sedang |
| OWN-01 | PIN/biometrik perangkat (2FA dan Google sudah ada) | sedang |
| OWN-03 | pemicu push void/diskon/refund di atas batas dan pesanan online; selisih kas masih dari ringkasan terjadwal | sedang |
| OWN-04 | persetujuan PO dan penyesuaian stok dari Owner | sedang |
| OWN-05 | laba-rugi sederhana di Owner (mesinnya ada di web) | kecil–sedang |
| OWN-08 | mencabut perangkat dari Owner (aksinya ada di web/konsol) | kecil |
| POS-03 | denah meja seret-lepas | sedang |
| POS-05 | mode kasir Grosir dengan SO cepat di layar Jual (kini hanya lewat modul Salesman) | sedang |
| POS-20 | build & rilis Windows di CI, installer | sedang |
| POS-21 | build iOS di CI, signing, bukti build | sedang–besar |
| POS-22 | SDK vendor sungguhan dan pemindai bawaan (printer/layar kedua Sunmi/iMin sudah jalan lewat Bluetooth/USB generik) | besar |
| POS-23 | auto-updater (versi minimum wajib sudah ada); Owner tanpa cek versi minimum | sedang–besar |
| PGL-01 | allowlist IP (ditunda) | kecil |
| PGL-07 | kanal push/in-app dan peristiwa selain pengingat tagihan | sedang |
| PGL-14 | faktur pajak, ekspor Coretax, dan nota kredit untuk tagihan langganan (refund sudah ada) | sedang–besar |
| PGL-15 | SLA jam kerja/hari libur, tiket masuk dari WA/email, eskalasi L1/L2 | sedang–besar |
| PGL-18 | Kasir dan Owner belum membaca flag fitur (server sudah) | kecil–sedang |
| PGL-20 | error rate/p95, outbox macet per perangkat, crash-free, alert WA/Telegram | sedang |
| SLS-10 | integrasi API ojol/marketplace (kini input manual) | besar |

**Klaim lama di bagian F yang ternyata keliru atau usang**
- **PRD-09** impor harga Excel ("meragukan"): **ADA dan teruji** (`.xlsx`/CSV, riwayat harga bersumber Impor).
- **TAX-06** ("ekspor Coretax belum ada"): **ekspornya ada** (XML Faktur Pajak Keluaran + rekap nota retur); yang belum hanya uji impor ke Coretax sungguhan.
- **FIN-12**: format Accurate/Jurnal.id/Zahir memang tidak ada, tetapi PRD hanya menjanjikan CSV umum, jadi **ADA**.
- **POS-22**: jalur Sunmi/iMin ada (printer bawaan, layar kedua, deteksi), hanya bukan SDK vendor.
- **POS-23**: versi minimum wajib sudah ada dengan tesnya; yang belum hanya auto-updater.
- **OWN-01**: 2FA dan masuk dengan Google sudah ada; yang belum PIN/biometrik perangkat.
- **INV-09** ringkasan pagi: ada dan bertes. **INV-07** kamera web: tidak ada di web, tetapi ada di aplikasi Kasir (opname + pindai kamera).
- **CRM-07** kanal email: sengaja dimatikan D-33, bukan kekurangan.
- **PLT-06** format/prefiks penomoran dokumen tidak bisa diatur (otomatis tanpa celah), celah kecil bila PRD menuntutnya.
- Bagian F-d: **Apotek** HNA belum tersimpan tetapi `KalkulatorHja` ada, embalase/tuslah ada sebagai produk Jasa dan komponen racikan (nilai diisi manual); **Grosir** update harga massal sudah ada (hanya belum mode "semua produk satu kategori" sekali klik); **Bakery** diskon sore bisa dibuat lewat promo jadwal hari/jam (belum berbasis kedaluwarsa).

**Butir sektor yang terbukti BELUM** (klaim F-d benar): FnB minimum charge, reservasi meja + DP, editor denah seret-lepas (`PosisiX/PosisiY` belum dipakai) | Fashion koleksi/musim, grid stok ukuran × warna | Servis elektronik non-kendaraan (`PerintahKerja.IdKendaraan` wajib), resep/bahan untuk produk Jasa, rebooking/follow-up salon, DP reservasi, antrian walk-in + booking satu tampilan, komisi bertingkat salon | Grosir armada/rute/status surat jalan/konfirmasi terima, ongkir per jarak, harga per pelanggan individual.

**Celah baru yang belum tercatat sebelumnya**
- **Grosir menolak produk ber-batch/bernomor seri** (`PelacakanBelumDidukung` di `SimpanPesananGrosir`, hanya jenis Stok) dan belum ada refund kas retur grosir: berdampak ke apotek/elektronik grosir.
- **BR-04.4:** selisih harga faktur seluruhnya masuk `SelisihHpp` tanpa revaluasi persediaan sisa vs terjual (hanya Nota Debit yang memisahkannya).
- **Kasir tidak bisa memilih batch manual** (hanya FEFO otomatis); batch di order produksi belum.
- **Toko online tanpa SSR/JSON-LD/sitemap toko** (SLS-05): pratinjau tautan WhatsApp dan crawler tanpa JS tidak melihat meta toko.
- **Wizard onboarding F-01** belum membuat otomatis peran sektor (Pelayan, Apoteker, Salesman).
- **Ekspor "PDF"** sebenarnya cetak lewat peramban (belum ada pustaka PDF); invoice PDF langganan belum ada (P-08, F-19).
- **Tombol Bantuan dengan konteks otomatis** di Kasir/Owner belum ada; **status bengkel** di kode `Ditagih`, di PRD `Diambil` (selisih kecil).
- **Pesanan self-order lunas** belum otomatis masuk pesanan terbuka meja/dapur (kasir menerimanya dulu, tampaknya disengaja).

**Selisih teks PRD yang sudah dibetulkan di v6.16:** katalog gerbang pembayaran dan butir "Tahap berikutnya" P-05 (kini menyebut hanya DOKU sejak D-82), §9.2 layar panggil antrian (selesai v3.81), dan daftar "Menyusul" grosir (Salesman selesai v3.85–v3.88). **Tetap di PRD karena memang benar:** BR-00.5 dan Bagian 17 menulis OTP WhatsApp "menyusul/belum".

**Urutan yang disarankan (menunggu persetujuan pemilik produk)**
1. Sisi Owner yang mesinnya sudah ada di web, ukuran kecil–sedang: OWN-08 cabut perangkat, OWN-05 laba-rugi, OWN-09 anti-fraud, OWN-06 cek stok, OWN-04 persetujuan PO/penyesuaian.
2. Grosir mendukung batch/seri + refund kas retur grosir; pengiriman grosir (status surat jalan, armada, ongkir).
3. Harga manual kasir `pos.harga.timpa` (lapis 1 price engine).
4. PGL-18 flag di Kasir/Owner, PGL-20/P-11 observabilitas, jalur tiket P-09.
5. OTP WhatsApp (PLT-01) bila penyedia WA sudah siap; SEO toko online (SLS-05 opsi ringan lewat Blade).
6. Fitur sektor (reservasi meja + DP, minimum charge, grid ukuran × warna, servis non-kendaraan) menurut sektor tenant yang paling banyak mendaftar.

## H. Penutupan empat butir urutan G — 13 Oktober 2026 (PRD v6.17–v6.22)

Empat butir pertama "Urutan yang disarankan" bagian G dikerjakan (keputusan D-137 s.d. D-141). Hitungan di bawah hanya memasukkan yang selesai dan lolos tes. **Bagian Flutter yang hanya diverifikasi lewat paket Dart murni (klien API, domain, model) dihitung ADA; layar Flutter-nya belum dikompilasi** (SDK Flutter tidak muat di sandbox), jadi ditandai terpisah dan harus dibuktikan CI `melos run periksa`.

**Hasil per kelompok ID (145 ID)**

| Kelompok | ID | ADA terverifikasi | ADA, layar Flutter belum dikompilasi | SEBAGIAN | BELUM |
|---|---|---|---|---|---|
| PLT, CRM, EMP | 27 | 22 | 0 | 4 | 1 |
| FIN, TAX, INV | 34 | 32 | 0 | 1 | 1 |
| OWN, POS | 38 | 20 | 5 | 8 | 5 |
| PGL, SLS, PRD | 46 | 38 | 1 | 6 | 1 |
| **Jumlah** | **145** | **112** | **6** | **19** | **8** |

**Yang berpindah dari G**
- **OWN-04, OWN-05, OWN-08** (SEBAGIAN → ADA) dan **OWN-06, OWN-09** (BELUM → ADA): API `/api/pemilik/v1` + klien Dart + layar Owner. Layar belum dikompilasi.
- **PGL-18** (SEBAGIAN → ADA): flag fitur dibaca Kasir (`CekFlagKasir`) dan Owner, ditambah versi minimum Owner (`LayarWajibPerbarui`). Layar belum dikompilasi.
- **PGL-20 tetap SEBAGIAN:** error rate & p95 API, outbox perangkat macet, dan alert WhatsApp kritis selesai (v6.21). Belum: crash-free sessions, endpoint terlambat, galat sinkron terbanyak, Telegram & runbook, webhook keluar gagal per tenant, ukuran database per tenant.
- **PGL-15 tetap SEBAGIAN:** SLA jam kerja WIB + hari libur P-02, eskalasi L1 → L2 → Teknis, tombol Bantuan dengan konteks otomatis di Kasir & Owner selesai (v6.22). Belum: tiket masuk dari WhatsApp/email.
- **POS-23 tetap SEBAGIAN:** Owner kini punya cek versi minimum; auto-updater belum ada.
- **Grosir** (bukan ID tersendiri): produk ber-batch/seri, refund kas retur, armada & status pengiriman surat jalan selesai (v6.18). **Ongkos kirim grosir sengaja belum dikerjakan** (menunggu keputusan pemilik produk).
- **Harga manual kasir `pos.harga.timpa`** (lapis 1 price engine) selesai di server (tinjauan, bukan penolakan, untuk penjualan offline) dan di domain Kasir (v6.19, D-138).

**Celah dari G yang ditutup:** grosir menolak batch/seri, tombol Bantuan dengan konteks otomatis.

**Urutan yang tersisa dari G:** butir 5 (OTP WhatsApp PLT-01, SEO toko online SLS-05) dan butir 6 (fitur sektor).

## I. Fitur per sektor (butir 6 urutan G) dan verifikasi Flutter sungguhan — 14 Oktober 2026 (PRD v6.23–v6.26)

Empat fitur sektor dikerjakan (keputusan D-142 s.d. D-145): **servis non-kendaraan**, **grid ukuran × warna**, **minimum charge**, dan **reservasi meja + DP**. Fitur sektor bukan bagian dari 145 ID §10, jadi hitungan ID di bawah berubah karena alasan lain: **SDK Flutter ternyata bisa dijalankan di lingkungan agen** (`/opt/flutter`, `flutter pub get` berhasil), sehingga untuk pertama kali analisis statis dan tes widget Aplikasi Kasir dan Owner benar-benar dijalankan. Klaim "layar Flutter belum dikompilasi" di bagian H sudah tidak berlaku.

**Yang ditemukan begitu Flutter dijalankan (semuanya dari pekerjaan sebelumnya yang tidak pernah dikompilasi)**
- **Aplikasi Owner: 10 temuan analisis, semuanya diperbaiki**: `PerangkatPemilik` tidak diimpor di `LayarPerangkat` (aplikasi tidak bisa dikompilasi!), tiga berkas tes memakai `PenyimpanSesiMemori` tanpa impor, hasil `refresh` tidak dipakai, dan dua daftar yang belum memakai elemen null-aware.
- **Aplikasi Owner: 4 tes widget gagal, semuanya diperbaiki**: kartu skor anti-fraud meluap di 360 dp (judul dan skor kini membungkus), serta tiga tes yang mengasumsikan semua elemen daftar terbangun (digulir dulu) atau mencari teks ganda.
- **Aplikasi Kasir: analisis bersih (0 temuan)**, termasuk semua perubahan Dart dari v6.17–v6.22 dan H1–H3 di sesi ini.
- **Satu regresi saya sendiri tertangkap tes**: tombol "Reservasi meja" di kepala Riwayat mendorong baris riwayat keluar layar di 360 dp (empat tes 360 dp gagal). Tombol itu kini hanya tampil bila sektor outlet **sudah diketahui** FnB.

**Kondisi tes Flutter sesudah perbaikan**
- `Paket/KlienApi`, `Paket/MesinKasir`, `Paket/Inti`, `Paket/AdaptorPerangkat`: lulus semua.
- `Aplikasi/Pemilik`: lulus semua.
- `Aplikasi/Kasir`: lihat angka akhir di bawah; sisa kegagalan **sudah ada sebelum sesi ini** (dibuktikan dengan menjalankan tes yang sama di commit sebelum pekerjaan hari ini) dan tidak diubah:
  - **4 tes golden pixel** (`RuangKerja`, `Aktivasi`, `GoldenMasuk`, `GoldenRuangKerja`; selisih 0,03% = 288–325 piksel; tiga yang pertama dibuktikan gagal sama di commit sebelum sesi ini, yang keempat memakai acuan sejenis): gambar acuan dibuat di mesin lain dan rendering teks di Linux berbeda sedikit. Perlu diputuskan: buat ulang acuan di CI Linux (mengubah berkas acuan, bukan melemahkan tes) atau CI memakai mesin yang sama.
  - **`LayananStokTersedia_test` "offline atau galat"**: tes mengharapkan salinan stok tetap dipakai setelah jawaban 404 `FiturTidakTersedia`, sedangkan kodenya (PRD v5.11) sengaja mengosongkan salinan pada 404 ("rute tidak ada lagi"). Tes dan kode bertentangan sejak v5.11; **butuh keputusan pemilik produk** mana yang benar (404 karena fitur di luar paket vs 404 karena rute hilang).
  - **`Paket/SistemDesain`: 2 tes** (`KeadaanKosong` ikon tidak ditemukan, dan `TandaMuat` animasi dimatikan). Penyebab belum diselidiki; kemungkinan besar tes basi terhadap desain yang berubah (D-58, D-85).
- Satu tes `DaftarPrinter` gagal karena Dart membandingkan himpunan di dalam *record* lewat identitas; diperbaiki dengan membandingkan tiap komponen terpisah (kekuatan asersi sama).

**Hasil per kelompok ID (145 ID)** — enam ID yang di bagian H bertanda "layar belum dikompilasi" kini terverifikasi:

| Kelompok | ID | ADA terverifikasi | SEBAGIAN | BELUM |
|---|---|---|---|---|
| PLT, CRM, EMP | 27 | 22 | 4 | 1 |
| FIN, TAX, INV | 34 | 32 | 1 | 1 |
| OWN, POS | 38 | 25 | 8 | 5 |
| PGL, SLS, PRD | 46 | 39 | 6 | 1 |
| **Jumlah** | **145** | **118** | **19** | **8** |

"Terverifikasi" berarti: tes server dijalankan dan lulus, dan untuk bagian Flutter analisis statis serta tes widget aplikasinya dijalankan dan lulus (kecuali kegagalan lama di atas yang tidak menyentuh ID ini). Bukti bagian server: Pest `ReservasiMejaTes` (9 kasus), `MinimumChargeTes`, `GridVarianStokAwalTes`, `ServisPerangkatTes`, plus regresi `PreOrderTes`, `Sinkron*`, `RegistriAnonimisasiTes`, `PenghapusanDataTes`, tes arsitektur dan gerbang kontrak API.

**Empat fitur sektor — apa yang ada dan apa yang belum**

| Fitur | Sudah | Belum / di luar cakupan |
|---|---|---|
| Servis non-kendaraan (§9.6, D-142) | Unit servis `Perangkat` (IMEI/nomor seri opsional) pada alur perintah kerja yang sama, kasir mengikuti | Resep/bahan untuk produk Jasa; tabel belum diganti nama dari `Kendaraan` |
| Grid ukuran × warna (§9.4, D-143) | Matriks harga dan stok awal (satu dokumen) di detail induk varian | Koleksi/musim; markdown terjadwal per koleksi; grid di kasir (kasir memakai pemilih varian K-9) |
| Minimum charge (§9.1, D-144) | Minimum per meja dan area, selisih ditagih sebagai baris sistem, tinjauan server | Minimum yang berbeda per jam atau hari |
| Reservasi meja + DP (§9.1, D-145) | Reservasi per meja tanpa bentrok, DP kewajiban, kembali/hangus, pakai DP di kasir, void mengembalikan, butir Kotak Tindakan | **Reservasi online oleh tamu dan DP online (QRIS)**, pengingat WhatsApp, DP kedaluwarsa otomatis, denah seret-lepas (POS-03), satu reservasi untuk beberapa penjualan |

**Urutan yang tersisa dari G:** butir 5 (OTP WhatsApp PLT-01, SEO toko online SLS-05) dan sisa sektor di atas. Dua keputusan baru menunggu pemilik produk: nasib tes `LayananStokTersedia` (404) dan cara menangani golden pixel.

## J. Delapan butir "kecil sampai sedang" — 14 Oktober 2026 (PRD v6.27–v6.30)

Dikerjakan atas permintaan pemilik produk ("sekarang kerjakan Kecil sampai sedang"). Tiap butir punya tes server (Pest, MySQL), tes frontend (Vitest) atau tes Flutter yang dijalankan dan lulus; Pint, Larastan, tsc, ESLint, tes arsitektur, dan `CekKonvensi --berubah` hijau.

| ID | Yang kini ada | Yang masih belum |
|---|---|---|
| PGL-01 (v6.27, D-146) | Daftar IP/CIDR global untuk seluruh konsol, perantara di semua rute termasuk halaman masuk, hanya Super Admin, IP pelaku wajib termasuk, audit, perintah pemulihan `pengelola:kosongkan-allowlist-ip`. Menutup §25 no.13 | Daftar per peran atau per pengguna (sengaja tidak dibuat) |
| TAX-07 (v6.27, D-147) | Faktur Pajak per transaksi kasir dari detail penjualan di back-office, dihitung dari snapshot saat ekspor, ditolak dengan alasan bila bukan PPN murni 11/12, ekspor XML Coretax | Isi NPWP pembeli dari kasir; format XML belum divalidasi ke Coretax resmi (K22, K35) |
| PGL-20 lanjutan (v6.28, D-148) | Rute API terlambat (p95 per jam), penolakan sinkron terbanyak, webhook keluar gagal per tenant, tautan runbook per alert | Crash-free sessions, alert Telegram, ukuran database per tenant. Sejak v6.60 (D-173) alert error rate & latensi API baru terbuka setelah bertahan 2 menit dengan minimum 100 permintaan per 5 menit, dan tertutup setelah sehat 5 menit. **PGL-20 tetap SEBAGIAN** |
| PGL-15 (v6.28, D-149) | Tiket masuk dari email/WhatsApp lewat `POST /webhook/dukungan/masuk` bertanda tangan HMAC, pengirim wajib dikenal, balasan masuk ke tiket yang nomornya disebut | Lampiran; adaptor penyedia nyata belum diuji (hanya kontrak webhook dan tes); balasan tim ke tiket asal WhatsApp masih lewat email; butuh `DUKUNGAN_MASUK_RAHASIA` |
| OWN-03 (v6.29, D-150) | Kabar void/retur/diskon besar (batas per tenant, bawaan Rp 500.000) dan pesanan online masuk ke penerima menurut izin dan outlet | Push iOS butuh konfigurasi sertifikat (di luar kode) |
| PLT-08 (v6.29, D-150) | Matikan push per jenis, bisukan outlet per pengguna; Persetujuan tidak bisa dibisukan | — |
| PGL-07 (v6.29, D-150) | Kanal `Notifikasi` pada templat berversi, peristiwa `tiket.balasan-tim`, pengingat tagihan juga sebagai notifikasi aplikasi | Email balasan tiket masih tampilan tetap |
| POS-05 (v6.30, D-151) | SO cepat dari halaman Bayar di outlet Grosir (outbox `PesananGrosir.Buat`, offline, peringatan limit kredit) | Bayar uang muka SO dari layar yang sama. Layar Flutter diuji lewat analisis statis dan tes widget, belum di perangkat nyata |

**Hasil per kelompok ID (145 ID)** — tujuh dari delapan butir pindah ke ADA; PGL-20 tetap SEBAGIAN.

| Kelompok | ID | ADA terverifikasi | SEBAGIAN | BELUM |
|---|---|---|---|---|
| PLT, CRM, EMP | 27 | 23 | 3 | 1 |
| FIN, TAX, INV | 34 | 33 | 0 | 1 |
| OWN, POS | 38 | 27 | 6 | 5 |
| PGL, SLS, PRD | 46 | 42 | 3 | 1 |
| **Jumlah** | **145** | **125** | **12** | **8** |

**Temuan lama yang ikut diperbaiki**
- `tests/Arsitektur/PengelolaTes` gagal sejak v6.21: perantara `CatatMetrikApi` memakai domain Pengelola dari luar namespace yang diizinkan. Perantara dipindah ke `App\Http\Perantara\Pengelola`; tes tidak dilonggarkan.
- `SalesmanTes` masih menyatakan produk bernomor seri ditolak padahal v6.18 mengizinkannya; tes diselaraskan dengan perilaku yang disengaja.

**Menunggu keputusan pemilik produk (tidak berubah):** nasib tes `LayananStokTersedia` (404), cara menangani golden pixel, dan dua tes `Paket/SistemDesain` yang gagal (`KeadaanKosong` ikon, `TandaMuat` animasi).

## L. Tur produk interaktif (PLT-12) — 14 Oktober 2026 (PRD v6.31, D-152)

Dikerjakan atas permintaan pemilik produk ("kerjakan tur produknya aja"). Bagian "tur produk" dari PLT-12 selesai; **live chat belum**, jadi PLT-12 tetap SEBAGIAN (12 SEBAGIAN, 125 ADA, 8 BELUM tidak berubah).

| Yang kini ada | Yang masih belum |
|---|---|
| Tur keliling dashboard 7 langkah (menu, pencarian cepat, notifikasi, Bantuan, Kotak Tindakan), dialog modal yang bisa dioperasikan keyboard dan dibaca pembaca layar | Tur per halaman atau per fitur (mis. wizard produk, tutup shift) |
| Ditawarkan otomatis sekali di Beranda; hasilnya disimpan per pengguna di server (`TurProduk`, lintas perangkat); bisa diulang dari Bantuan › Tur singkat | Tur di aplikasi Kasir dan Owner (Flutter) |
| Langkah opsional dibuang bila targetnya tidak ada (edisi Lisensi, halaman selain Beranda); petugas dukungan tidak mencatat atas nama pemilik | **Live chat**: butuh vendor obrolan dan staf dukungan waktu nyata (keputusan pemilik produk) |

**Verifikasi:** Pest `TurProdukTes` (6 kasus: catat/idempoten, ulangi, kode tak dikenal 404, status ngawur, isolasi pengguna dan tenant, wajib masuk), Vitest `TurProdukTes.tsx` (16 kasus) plus seluruh suite Vitest (229 berkas, 1463 tes) karena tata letak bersama berubah, Larastan, Pint, tsc, ESLint, tes arsitektur, `RegistriAnonimisasiTes`. Tampilan dilihat di Chromium sungguhan pada 360, 768, dan 1280 px lewat halaman uji terpisah yang memakai komponen `BalonTur` dan CSS back-office asli (12 tangkapan: tanpa luapan, tanpa gulir horizontal, tanpa galat konsol); **belum** dilihat di dalam aplikasi penuh dengan data nyata, jadi sorotan pada menu samping sungguhan dan lembar Bantuan di HP sebaiknya diperiksa sekali sesudah rilis.

## M. P0/P1 yang tersisa — 14 Oktober 2026 (PRD v6.32–v6.36, D-153…D-157)

Dikerjakan atas permintaan pemilik produk ("OTP WhatsApp nanti aja, sisanya P0 dan P1 kerjakan"). Semua kode diuji lewat tes otomatis; tidak satu pun yang sudah dijalankan di perangkat, runner CI, atau toko aplikasi sungguhan.

| ID | Yang kini ada | Yang masih belum / catatan jujur |
|---|---|---|
| OWN-01 (v6.32) | Kunci PIN 6 angka + biometrik perangkat di Aplikasi Owner, kunci otomatis setelah 1 menit, ajakan di Beranda | OTP WhatsApp (PLT-01) **sengaja ditunda** atas permintaan pemilik produk. Biometrik belum diuji di ponsel sungguhan |
| OWN-07 (v6.32) | Layar Aksi cepat: ubah harga, tandai habis, promo aktif/nonaktif, pengeluaran + foto nota | Belum diuji di ponsel; foto nota memakai kamera/galeri bawaan |
| POS-03 (v6.32) | Letak meja seret-lepas di back-office, denah kanvas di layar Meja kasir (skema lokal 31) | Hanya letak meja; tidak ada dinding/dekorasi/lantai |
| CRM-06 (v6.33, D-153…D-155) | Gift card: jual dari back-office (J-16.10), kode tampil sekali, bayar di kasir saat online, void mengembalikan, kedaluwarsa otomatis, perpanjang/hanguskan/kembalikan | **Tidak ada:** jual gift card sebagai baris di kasir, refund retur ke kartu (ditolak), pakai offline, kartu fisik/QR cetak |
| POS-20 / POS-21 (v6.34, D-156) | Alur CI `Bangun Aplikasi` (Android AAB/APK, Windows + Inno Setup, iOS tanpa tanda tangan) | **Belum pernah berjalan di runner.** Android butuh keystore rilis; iOS butuh Apple Developer Program; Windows butuh sertifikat tanda tangan kode |
| POS-23 (v6.35, D-157) | Pembaruan otomatis Windows (unduh https + cocokkan SHA-256 + pasang senyap), checksum di konsol rilis dan API | Android/iOS tetap lewat toko aplikasi. Belum diuji dengan pemasang sungguhan |
| POS-22 | — | Butuh keputusan vendor perangkat (SDK Sunmi/iMin/dll.) dan perangkat uji |
| PLT-12 live chat | Selesai v6.42, lihat bagian N | — |
| PLT-01 OTP WhatsApp | — | Ditunda atas permintaan pemilik produk |

**Hasil per kelompok ID (145 ID), setelah v6.32–v6.37** — OWN-01, OWN-07, POS-03, dan CRM-06 pindah ke ADA. POS-20, POS-21, dan POS-23 sengaja tetap SEBAGIAN sampai alur `Bangun Aplikasi` terbukti berjalan di runner dan pembaruan Windows dicoba di perangkat sungguhan.

| Kelompok | ID | ADA | SEBAGIAN | BELUM |
|---|---|---|---|---|
| PLT, CRM, EMP | 27 | 24 | 2 | 1 |
| FIN, TAX, INV | 34 | 33 | 0 | 1 |
| OWN, POS | 38 | 30 | 4 | 4 |
| PGL, SLS, PRD | 46 | 42 | 3 | 1 |
| **Jumlah** | **145** | **129** | **9** | **7** |

(Angka di atas adalah keadaan sebelum bagian N; PLT-12 pindah ke ADA di bagian N, jadi angka terkini 130 / 8 / 7.)

- **9 SEBAGIAN:** PLT-01 (OTP WhatsApp, ditunda), PLT-12 (live chat), POS-20, POS-21, POS-23 (kode & tes ada, belum terbukti di runner/perangkat), POS-22 (SDK vendor), PGL-14 (faktur pajak & nota kredit tagihan langganan), PGL-20 (crash-free sessions, Telegram, ukuran database per tenant), SLS-10 (integrasi API ojol).
- **7 BELUM:** EMP-07 (PPh 21 & BPJS, K9), INV-15 (portal pemasok), OWN-12 (widget layar utama, ditunda), OWN-13 (langganan di Owner, ditunda), POS-19 (multibahasa), POS-24 (mode LAN, ditunda), PGL-23 (portal mitra eksternal).
- **CRM-06 dihitung ADA dengan catatan:** jual gift card sebagai baris di kasir, refund retur ke kartu, dan pakai offline belum ada (lihat tabel di atas).
- Angka di bagian G dan seterusnya (125 / 12 / 8) adalah keadaan sebelum bagian M dan tidak diubah sebagai catatan sejarah.

**Pra-ada, tidak diubah (menunggu keputusan pemilik produk):** empat golden pixel test Kasir dan satu test `KeadaanKosong` di `Paket/SistemDesain` gagal sebelum pekerjaan ini; tidak dilemahkan.

## N. Live chat dukungan (PLT-12) — 15 Oktober 2026 (PRD v6.42, D-163)

Dikerjakan atas permintaan pemilik produk ("kerjakan Live Chat"). **PLT-12 pindah SEBAGIAN → ADA: 130 ADA, 8 SEBAGIAN, 7 BELUM.** Dibangun sendiri di atas sistem tiket (obrolan = tiket berkanal `Obrolan`), tanpa vendor obrolan.

| Yang kini ada | Yang masih belum / catatan jujur |
|---|---|
| Panel obrolan di lembar Bantuan back-office tenant; inbox obrolan petugas di konsol (`/dukungan/obrolan`); kesiapan petugas + detak; polling 4 detik dengan penanda pesan terakhir; teks jujur saat tidak ada petugas (pesan tetap menjadi tiket, SLA berlaku); email tim dilewati bila ada petugas online, email balasan dilewati selagi pelapor membaca; audit akses lintas tenant sekali per 5 menit | Lampiran berkas di obrolan (pakai tiket biasa), obrolan di Aplikasi Kasir & Owner, indikator mengetik, suara/push untuk petugas baru (hanya judul tab), beban polling pada skala besar belum diuji, belum pernah dicoba dengan petugas sungguhan |

- **8 SEBAGIAN:** PLT-01 (OTP WhatsApp, ditunda), POS-20, POS-21, POS-23 (kode & tes ada, belum terbukti di runner/perangkat), POS-22 (SDK vendor), PGL-14 (faktur pajak & nota kredit tagihan langganan), PGL-20 (crash-free sessions, Telegram, ukuran database per tenant), SLS-10 (integrasi API ojol).
