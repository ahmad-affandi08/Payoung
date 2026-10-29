# Keputusan yang menunggu pemilik produk

Satu-satunya daftar keputusan yang perlu dijawab pemilik produk. Agent menambah butir di sini setiap kali menemukan
hal yang tidak boleh diputuskan sendiri, lalu tetap melanjutkan pekerjaan lain. Jawab di butir masing-masing; butir
yang sudah dijawab dipindah ke bagian **Sudah diputuskan** beserta versi PRD yang menerapkannya.

Format tiap butir: pertanyaan, pilihan yang tersedia, usulan agent, dan apa yang tertahan sampai dijawab.

## Produk & fitur

| # | Keputusan | Pilihan | Usulan agent | Yang tertahan |
|---|---|---|---|---|
| K1 | **Gabung pelanggan ganda**: saldo deposit, piutang terbuka, poin, saldo sesi, dan riwayat belanja pelanggan yang digabung | (a) semua dipindahkan ke pelanggan tujuan lewat dokumen pemindahan (jurnal deposit seimbang, mutasi poin/sesi bertipe Gabung); (b) gabung ditolak selama salah satu masih punya saldo/piutang terbuka | (b) dulu (aman, tanpa jurnal baru), (a) menyusul | Fitur gabung pelanggan (F-16a) |
| K2 | **Bonus isi deposit** (mis. isi Rp 500.000 dapat Rp 25.000) | ada / tidak | Ada, opsional per tenant; bonus dicatat Dr Beban Promosi / Cr Deposit Pelanggan | F-16d bonus deposit |
| K3 | **Kedaluwarsa saldo deposit** | tidak ada / ada (berapa bulan, dan sisa diakui sebagai pendapatan lain) | Tidak ada (deposit = titipan uang pelanggan; menghanguskan berisiko keluhan konsumen) | F-16d kedaluwarsa deposit |
| K4 | **Gift card / voucher bernilai** dijual ke publik: boleh dipakai di semua outlet? ada kedaluwarsa? bisa diuangkan? | — | Semua outlet, kedaluwarsa opsional per kartu, tidak bisa diuangkan; akuntansi sama dengan deposit (Pendapatan Diterima Dimuka) | F-16d gift card |
| K5 | **Membership berbayar berbasis waktu** (iuran bulanan/tahunan): keuntungannya apa saja (harga tier, diskon, poin berlipat) dan pengakuan pendapatannya | — | Iuran = Pendapatan Diterima Dimuka diakui rata per bulan; keuntungan = tier khusus selama aktif | F-16d membership |
| K7 | Sub-merchant gerbang pembayaran di bawah platform | sekarang / nanti | Nanti | — |
| K9 | **PPh 21 & BPJS di rekap gaji** (EMP-07): hitung otomatis (TER PMK 168/2023) atau hanya kolom potongan manual | otomatis / manual | Manual dulu (kolom potongan PPh 21 & BPJS tercatat terpisah), otomatis setelah divalidasi konsultan pajak | Payroll penuh |
| K36 | **Kepercayaan pencocokan wajah absensi web** (D-37). Sidik wajah dihitung model di peramban HP karyawan; server membandingkan sidik itu dengan wajah terdaftar. HP yang dimodifikasi bisa mengirim sidik hasil curian dari foto, jadi pencocokan ini menahan titip absen biasa, bukan penyerang yang paham teknik | (a) terima, dengan swafoto tersimpan sebagai bukti untuk diperiksa pengelola; (b) server menghitung ulang sidik dari swafoto (butuh model wajah di server, beban CPU per absen); (c) tambah QR berganti di outlet sebagai bukti hadir kedua | (a) sekarang, (c) sudah tersedia sejak v4.23 (opsional per outlet), (b) hanya bila ada kasus nyata | Penguatan absensi web **Status kode (dicocokkan 12 Okt 2026):** opsi (a) berjalan dan opsi (c) QR berganti di layar outlet sudah tersedia (v4.23, `Outlet.WajibQrAbsensi`); yang menunggu tinggal konfirmasi pemilik. |
| K37 | **Ambang kemiripan wajah 0,60** belum dikalibrasi dengan data nyata. Terlalu rendah = wajah mirip lolos; terlalu tinggi = karyawan sah sering ditolak (cahaya, kacamata) | (a) rilis dengan 0,60 dan kumpulkan nilai kemiripan dari rekap selama 2–4 minggu; (b) tahan rilis sampai uji coba internal | (a); ambang bisa diubah lewat `AMBANG_KEMIRIPAN_WAJAH` tanpa rilis ulang; panel Kalibrasi pencocokan wajah (v4.24) menyediakan datanya | Kalibrasi ambang wajah |
| K38 | **Siapa yang melihat jarak & kemiripan wajah di rekap absensi.** Sekarang tampil bagi pemegang `karyawan.lihat` (Supervisor ikut melihat); koordinat tidak pernah ditampilkan | (a) tetap `karyawan.lihat`; (b) hanya `karyawan.kelola` | (a): angka itu bukti kehadiran yang dipakai supervisor menindaklanjuti absen | Rekap absensi |

## Situs, domain & merek

| # | Keputusan | Usulan agent |
|---|---|---|
| K10 | `robots.txt` & `sitemap.xml` masuk pengecualian konvensi URL? (sekarang `/peta-situs` + `robots.txt` statis) | Ya, keduanya nama standar web |
| K11 | Tautan struk digital/QR tetap di `dashboard.payoung.id/s/…` atau pindah ke `payoung.id` | Pindah ke `payoung.id` (lebih pendek di struk) |
| K12 | Pengalihan `www.payoung.id` → `payoung.id` | Diatur di hPanel |
| K13 | Nama subdomain konsol `console.` vs `consol.` | Samakan nilai `PENGELOLA_DOMAIN` |
| K14 | Isi pemasaran (teks, foto, testimoni nyata) | Diisi pemilik dari konsol |

## Infrastruktur & di luar kode

| # | Keputusan / tindakan | Catatan |
|---|---|---|
| K16 | Proteksi branch `main` + jadikan default | Pengaturan GitHub pemilik repo |
| K18 | Backup DB & berkas + uji restore berkala | — |
| K19 | Kunci unggah Android + Play App Signing | — |
| K20 | Isi `SITUS_KUNCI_SIDIK` di produksi | — |
| K21 | Naikkan CSP dari Report-Only ke penegak; nyalakan `TENANT_TOLAK_ID_BERBEDA=true` | Setelah log bersih beberapa minggu |
| K22 | Validasi ekspor XML Coretax ke aplikasi resmi (impor satu faktur) | Butuh akun Coretax pemilik usaha |
| K23 | **Kampanye pesan CRM-07 (v3.44)**: daftarkan templat pemasaran WhatsApp ke Meta (4 variabel: toko, nama, isi, tautan berhenti) lalu isi `NamaTemplatPromosi` di konsol Integrasi; tinjau batas agent (maks. 5.000 penerima/kampanye, 25 pesan/menit, jam tenang 08.00–21.00, fitur tanpa kunci paket khusus) | Batas & kunci paket bisa diubah bila pemilik menghendaki kampanye jadi fitur berbayar |
| K24 | **Mitra & referral P-12 (v3.50)**: (a) besaran komisi bawaan mitra reseller & referral, (b) perlakuan pajak komisi — PPh 21 bukan pegawai (mitra perorangan) / PPh 23 (mitra badan), tarif & bukti potong, dan apakah Payoung menanggung atau memotong, (c) bentuk imbalan referral (kredit langganan vs uang tunai) | Agent: persen komisi diisi per mitra di konsol (bawaan 0), potongan pajak dicatat manual per pencairan oleh Keuangan sampai pemilik & konsultan pajak memutuskan; referral sementara dibayar sebagai komisi uang |
| K29 | **Pelaporan galat pihak ketiga (K-21, v3.73)**: pakai Sentry SaaS (server di luar negeri → transfer data pribadi lintas negara, UU PDP Pasal 56, perlu DPA & penilaian), Sentry self-hosted di server Payoung, atau cukup kanal mandiri | Agent: kanal mandiri dulu — aplikasi kasir menyimpan log lokal tersaring PII dan mengirim galat ke `POST /api/pos/v1/perangkat/galat` → log harian `galat-perangkat` (30 hari). Sentry dipasang setelah pemilik memilih & mengisi DSN **Status kode (dicocokkan 12 Okt 2026):** kanal mandiri sudah berjalan (v3.73, log `galat-perangkat`); paket Sentry belum dipasang dan menunggu pilihan ini. |
| K35 | **Nota retur pajak di Coretax (v4.08)**: konfirmasi di akun Coretax pemilik apakah tersedia impor massal Retur Pajak Keluaran (dan formatnya), dan apakah pembeli grosir umumnya PKP (retur dibuat pembeli) atau bukan (retur dibuat toko) | Agent: rekap CSV per barang (NSFP asal, DPP, DPP nilai lain 11/12, PPN) di Laporan › Pajak; XML impor retur dibuat setelah formatnya dipastikan bersama K22 |

## Sudah diputuskan

| # | Keputusan | Jawaban pemilik | Diterapkan |
|---|---|---|---|
| K26 | Format nomor antrian bila satu outlet punya lebih dari satu perangkat kasir | Pemilik memilih mengerjakan K26 dengan usulan (b) (29 Okt 2026): awalan huruf per perangkat diatur di back-office | PRD v7.59, D-243 |
| K27 | Enkripsi basis data lokal kasir untuk semua perangkat | Opsi (a) semua perangkat; tidak ada D-xx tertulis, diterapkan agen dan cukup diketahui | PRD v3.57 (K-7), SQLite3 Multiple Ciphers |
| K15 | Lisensi repo (`composer.json` menyebut MIT padahal produk komersial) | Ganti ke proprietary | Sudah: `LICENSE` berisi lisensi proprietary dan `composer.json` bertuliskan `proprietary`. Sisa: tinjauan hukum atas naskahnya |
| K33 | Kasir yang izinnya berubah saat offline (pemindaian v4.03) | Mengikuti rekomendasi: `MutasiKas` diterima + ditandai tinjauan `IzinBerubah`, `Shift.Buka` tetap ditolak | PRD v5.55, D-115 |
| K34 | Nomor WhatsApp per toko | Pemilik memilih gabungan (opsi 3): kuota nomor Payoung ditegakkan + WhatsApp milik tenant tanpa kuota; notifikasi Payoung → pemilik tetap dari nomor Payoung | PRD v5.45, D-108 |
| K17 | MySQL 8 atau MariaDB 11.8 di produksi | "hosting menggunakan mysql" (3 Okt 2026) | Tanpa perubahan kode (test & CI memakai MySQL 8) |
| D-35 | Dashboard dijual sebagai lisensi pasang sendiri | "mereka beli dashboardnya aja, pasang di server mereka sendiri, domain mereka sendiri" + satu usaha, semua fitur, berkas bertanda tangan offline, "sekali beli selamanya" (3 Okt 2026) | PRD v4.12 (bagian 1); data master, integrasi, aplikasi & panduan instalasi menyusul |
| D-36 | Edisi terkunci saat build, nama jual, masa pembaruan | "setuju" atas usulan: paket pembeli dengan edisi Lisensi tertanam & tanpa rute konsol; Payoung Cloud (SaaS) / Payoung Mandiri (pasang sendiri); lisensi selamanya, pembaruan & dukungan gratis 1 tahun lalu pemeliharaan tahunan opsional (3 Okt 2026) | PRD v4.17; angka biaya pemeliharaan & kunci publik penerbit masih menunggu pemilik produk |
| D-37 | Absensi HP pribadi lewat web (PWA halaman absensi), radius wajib, wajah wajib, tanpa PIN | "lebih memilih web … mobile first … PWA cukup aplikasi absensinya", "absensi tetep berdasarkan radius, pake validasi wajah juga wajib", "setuju" (3 Okt 2026) | PRD v4.19 (server), v4.20 (PWA), v4.21 (back-office), v4.22 (perbaikan tinjauan), v4.23 (QR berganti di outlet); panel kalibrasi v4.24; angka ambang menunggu K37; **diubah D-180 (v6.68):** tautan rahasia, PWA, dan QR layar outlet diganti login biasa |
| D-180 | Absensi lewat login mobile first: penanda Ikut absensi per karyawan, wajah selalu wajib, lokasi hanya menandai, foto bukti opsional, minta koreksi ke manajer; layar QR outlet, tautan rahasia, dan absen PIN kasir dihapus | "layar di outlet itu ga perlu … mereka login dan ada page mobile first", "login tetap menggunakan login itu … validasi wajah wajib, cuma di pengaturannya bisa diatur wajib foto atau engga", "kerjakan semua lalu build dan push ke main" (18 Okt 2026) | PRD v6.68 |
| D-38 | Audit kemudahan pakai: four-eyes menyesuaikan jumlah penyetuju, 2FA Bisnis ditunda selama trial, PIN tetap untuk void & retur (lainnya boleh persetujuan berlaku sementara; jarak jauh tetap), menu disaring per sektor | "lanjut 1-4 tapi untuk void dan return tetep butuh pin, dan tetep ada approval jarak jauh bagi yang berhak, tautan unduh aplikasi ga perlu dulu" (3 Okt 2026) | PRD v4.27 dst. (dikerjakan berurutan) |
| D-34 | Four-eyes data master untuk Super Admin | "untuk superadmin di konsol itu tidak berlaku tinjau meninjau" (3 Okt 2026) | PRD v4.10 |
| K28 | Retur tanpa struk | "K28 Return pake PIN owner dan yang berhak" (3 Okt 2026) → pilihan (b): wajib PIN Pemilik atau pengguna ber-izin baru `penjualan.retur.tanpa-struk` (bawaan Pemilik & Admin), nilai = harga jual berlaku + pajak tarif berlaku, refund hanya tukar barang/deposit (tanpa uang tunai), hanya produk berstok biasa (bukan batch/seri), batas Rp 1.000.000 per outlet per hari (di atasnya tetap diterima + tinjauan) | PRD v4.01 (server & back-office), v4.02 (aplikasi Kasir) |
| K6 | Refund di sisi gerbang pembayaran (QRIS/VA) saat retur/void | "K6 Manual dulu" (3 Okt 2026) → refund di dasbor gerbang dilakukan manual oleh toko; Payoung mencatat refund retur/void (BR-09.2) seperti sekarang, tanpa panggilan API refund gerbang | PRD v4.01 (tanpa perubahan kode; perilaku F-09 yang ada) |
| K30 | Modul Salesman: (a) nomor HP & alamat pelanggan di aplikasi salesman, (b) semua pelanggan vs penugasan, (c) izin salesman berubah setelah offline | "Berikan yang terbaik" (2 Okt 2026) → (a) nomor HP **penuh + alamat** (salesman perlu menghubungi & mendatangi toko; hanya untuk izin `salesman.kunjungan`, DB lokal terenkripsi K-7); (b) semua pelanggan aktif, penugasan menyusul bila diminta; (c) cukup log audit, draf tetap dikonfirmasi back-office | PRD v3.86 |
| K31 | Izin lokasi di semua versi Android & perilaku perangkat berjenis Salesman | "Kerjakan yang terbaik" (2 Okt 2026) → (a) satu aplikasi; izin lokasi hanya saat dipakai, untuk mencatat kunjungan; deklarasi Play Console: lokasi perkiraan & akurat, saat aplikasi dipakai, tujuan "mencatat lokasi kunjungan pelanggan oleh salesman", tidak dibagikan ke pihak ketiga; semua pengguna di perangkat Salesman masuk ruang kerja Salesman (kanvas memakai perangkat Kasir di outlet kanvas) | PRD v3.88 |
| K25 | Sektor Apotek & Bengkel: dijual sekarang atau belum | "Apotek dan Bengkel, ingat bengkel jual sparepart dan jual jasa juga" (2 Okt 2026) → dibangun sekarang; Bengkel = Perintah Kerja berisi **jasa + sparepart** (sparepart mengurangi stok, jasa ber-mekanik untuk komisi), Apotek = golongan obat, resep, peran Apoteker, batch/kedaluwarsa, embalase/tuslah | PRD v3.89+ |
| K32 | Rincian kasir Bengkel & Apotek bagian 2 | Arahan "kerjakan yang terbaik" → (1) diskon baris WO masuk sebagai diskon manual (tetap tunduk batas diskon BR-07.3; diskon besar butuh PIN penyetuju — dianggap aman karena kasir tetap memeriksa); (2) mengubah jumlah baris WO di kasir mengikuti harga mesin harga seperti pre-order; (3) catatan baris WO tidak dicetak di struk (bisa catatan internal mekanik); (4) lencana golongan: K (keras), K · OWA, P (psikotropika), N (narkotika), Bebas, B. terbatas — teks selalu tampil; (5) persetujuan apoteker wajib PIN di tempat, tanpa persetujuan jarak jauh (apoteker harus menyerahkan langsung); (6) tombol "Servis siap tagih" tampil di semua outlet sampai ada penanda sektor di data-awal | PRD v3.90 |
