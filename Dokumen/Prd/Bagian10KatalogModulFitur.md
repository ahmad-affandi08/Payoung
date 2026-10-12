<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

## 10. Katalog Modul & Fitur

Prioritas: **P0** = MVP wajib, **P1** = penting (fase 2), **P2** = pembeda (fase 3), **P3** = lanjutan (fase 4).

### 10.0 Modul Platform Pengelola

Dipakai tim internal {{APP}} (§8 Bagian A, §13.8, §19.3).

| ID | Fitur | Flow | Prioritas / Fase |
|---|---|---|---|
| PGL-01 | Akun tim internal, peran, 2FA wajib, pembatasan IP, log audit pengelola | P-01 | P0 · Fase 0 |
| PGL-02 | Master wilayah, tarif PPN & PBJT bertanggal berlaku dengan alur tinjauan, hari libur, referensi bank/EDC, satuan standar | P-02 | P0 · Fase 0 |
| PGL-03 | Template sektor berversi + validasi otomatis + pratinjau sandbox (3 template MVP) | P-03 | P0 · Fase 0 (template lain bertahap) |
| PGL-04 | Katalog fitur, paket, batas, add-on, kupon langganan, evaluasi `FiturAktif` | P-04 | P0 · Fase 0 (kupon: P1) |
| PGL-05 | Konfigurasi integrasi platform (email, CAPTCHA, storage di Fase 0. Gateway billing, WA, FCM menyusul) + tes koneksi | P-05 | P0 · Fase 0–2 |
| PGL-06 | Dokumen legal berversi + persetujuan tenant | P-06 | P0 · Fase 0 |
| PGL-07 | Template email/WA/push/in-app + help center | P-06 | P1 · Fase 1–2 |
| PGL-08 | Daftar & 360° tenant, perpanjang trial, override, tangguhkan/aktifkan, catatan internal | P-07 | P0 · Fase 0 |
| PGL-09 | Skor kesehatan tenant & tugas otomatis | P-07 | P1 · Fase 2 |
| PGL-10 | Permintaan penghapusan data (UU PDP) | P-07 | P1 · Fase 2 |
| PGL-11 | Tagihan langganan + verifikasi transfer manual | P-08 | P0 · Fase 0 |
| PGL-12 | Pembayaran gateway otomatis + dunning otomatis | P-08 | P1 · Fase 2 |
| PGL-13 | Laporan MRR/ARR/churn/piutang langganan | P-08 | P1 · Fase 2 |
| PGL-14 | Faktur pajak langganan (export Coretax), refund & nota kredit | P-08 | P2 · Fase 3 |
| PGL-15 | Tiket dukungan + SLA per paket | P-09 | P0 (dasar) · Fase 0 → P1 (SLA & kanal WA) · Fase 2 |
| PGL-16 | Akses dukungan berizin (baca saja / baca & ubah) + alat bantu dukungan | P-09 | P0 · Fase 1 (sebelum beta tertutup). **Akses dukungan & akses darurat selesai v5.74**; alat bantu menyusul |
| PGL-17 | Manajemen rilis aplikasi, rollout, versi minimum | P-10 | P0 · Fase 1 |
| PGL-18 | Flag fitur (global/paket/tenant/persentase) & kill switch | P-10 | P1 · Fase 1 |
| PGL-19 | Pengumuman, banner pemeliharaan, catatan rilis — v3.45 (Aplikasi Pemilik v3.47) | P-10 | P1 · Fase 2 |
| PGL-20 | Dasbor operasional (scheduler, antrean, job gagal, outbox macet, backup) + alert | P-11 | P0 (dasar) · Fase 0 → lengkap Fase 2 |
| PGL-21 | Manajemen insiden & halaman status publik | P-11 | P1 · Fase 2 |
| PGL-22 | Mitra, atribusi, komisi, pencairan | P-12 | P2 · Fase 3 |
| PGL-23 | Portal mitra `/mitra` | P-12 | P2 · Fase 3 |
| PGL-24 | Analitik platform: funnel daftar → transaksi pertama → bayar, adopsi fitur per sektor, retensi kohort | — | P1 · Fase 2 |

### 10.1 Modul Platform & Tenant

| ID | Fitur | Prioritas |
|---|---|---|
| PLT-01 | Registrasi, login, verifikasi email/WA OTP, lupa password | P0 |
| PLT-02 | Multi-tenant (isolasi data per tenant) | P0 |
| PLT-03 | Pemilih tenant (user multi-tenant) | P1 |
| PLT-04 | 2FA (TOTP) untuk Owner/Admin | P1 |
| PLT-05 | Onboarding wizard + template sektor | P0 |
| PLT-06 | Pengaturan usaha, logo, struk, penomoran dokumen | P0 |
| PLT-07 | Audit log & activity log | P0 |
| PLT-08 | Notifikasi in-app + email + WA | P1 |
| PLT-09 | Pusat unduhan (export antrian) | P0 |
| PLT-10 | Import massal (produk, pelanggan, supplier, stok awal) | P0 |
| PLT-11 | Platform Pengelola (lihat §10.0 dan §8 Bagian A) | P0 |
| PLT-12 | Help center in-app, tur produk, live chat | P1 |

### 10.2 Modul POS

| ID | Fitur | Prioritas |
|---|---|---|
| POS-01 | Mode retail (scan barcode, keyboard shortcut) | P0 |
| POS-02 | Mode quick (grid tombol) | P0 |
| POS-03 | Mode table (denah meja, open bill) | P1 |
| POS-04 | Mode service (layanan + staf + booking) | P1 |
| POS-05 | Mode wholesale (SO cepat, harga level) | P1 |
| POS-06 | Varian, modifier, catatan item | P0 |
| POS-07 | Diskon item/order dengan batas per role + approval PIN | P0 |
| POS-08 | Parkir/hold transaksi | P0 |
| POS-09 | Split payment | P0 |
| POS-10 | Split/merge bill, pindah meja | P1 |
| POS-11 | Cetak struk thermal 58/80 mm, struk digital (link/QR/WA) | P0 |
| POS-12 | Buka/tutup shift, kas masuk/keluar, blind close | P0 |
| POS-13 | Void & retur dengan alasan & approval | P0 |
| POS-14 | Offline-first penuh | P0 |
| POS-15 | Customer display (layar kedua) | P1 |
| POS-16 | Barcode timbangan | P1 |
| POS-17 | Buka laci kas (via printer) + log | P0 |
| POS-18 | Mode latihan (training mode, tidak memengaruhi data) | P1 |
| POS-19 | Multi-bahasa layar kasir (ID/EN) | P2 |
| POS-20 | Aplikasi Flutter Android + Windows (rilis pertama) | P0 |
| POS-21 | Aplikasi Flutter iOS/iPadOS | P0 (akhir Fase 1) |
| POS-22 | Dukungan **semua** perangkat POS Android all-in-one (printer, laci, layar pelanggan, scanner bawaan) via adaptor vendor (§17.2.5) | P0 (Sunmi, iMin, adaptor generik) → P1 (vendor lain) |
| POS-23 | Update otomatis aplikasi (store & desktop auto-updater) + versi minimum wajib | P0 |
| POS-24 | Mode LAN Lokal / Outlet Hub (X17) | P2 |
| POS-25 | Modul Gudang di aplikasi (scan GRN, transfer, opname via kamera/scanner) | P1 |


### 10.2a Modul Aplikasi Owner (Flutter, Android & iOS)

| ID | Fitur | Prioritas |
|---|---|---|
| OWN-01 | Login aman (email/WA OTP + PIN/biometrik perangkat), pilih tenant & outlet | P0 |
| OWN-02 | Dashboard real-time: omzet, laba kotor, transaksi, rata-rata keranjang, per outlet & konsolidasi, perbandingan kemarin/minggu lalu | P0 |
| OWN-03 | Notifikasi push: selisih kas tutup shift, void/diskon/refund di atas batas, stok kritis, perangkat offline lama, piutang jatuh tempo, order online masuk | P0 |
| OWN-04 | **Approval jarak jauh** (void, diskon, refund, kas keluar, PO, penyesuaian stok) satu ketukan dengan detail & alasan | P0 |
| OWN-05 | Laporan ringkas: penjualan per produk/kategori/jam/kasir/channel, laporan shift, L/R sederhana | P0 |
| OWN-06 | Cek stok & nilai persediaan per outlet, riwayat kartu stok | P1 |
| OWN-07 | Aksi cepat: ubah harga, tandai produk habis (86), aktif/nonaktif promo, tambah pengeluaran dengan foto nota | P1 |
| OWN-08 | Status perangkat POS per outlet (online, outbox tertunda, versi app) & cabut perangkat | P1 |
| OWN-09 | Laporan anti-fraud & skor risiko kasir | P1 |
| OWN-10 | Pantau karyawan: absensi, komisi, target | P2 |
| OWN-11 | Insight mingguan otomatis (tren, produk turun, saran restock) | P2 |
| OWN-12 | Widget layar utama (omzet hari ini) Android & iOS | P2 |
| OWN-13 | Kelola langganan & tagihan {{APP}} | P2 |

### 10.3 Modul Produk & Harga

| ID | Fitur | Prioritas |
|---|---|---|
| PRD-01 | Produk, kategori bertingkat, brand, gambar | P0 |
| PRD-02 | Multi-satuan & konversi, multi-barcode | P0 |
| PRD-03 | Varian matrix | P0 |
| PRD-04 | Modifier group | P0 |
| PRD-05 | Resep/BOM + HPP resep | P0 |
| PRD-06 | Bundle/paket | P1 |
| PRD-07 | Price list (outlet × channel × tier × waktu) | P1 |
| PRD-08 | Harga bertingkat qty | P1 |
| PRD-09 | Update harga massal, riwayat harga | P1 |
| PRD-10 | Cetak label harga/barcode | P1 |
| PRD-11 | Ketersediaan per outlet & per channel, tandai habis (86) | P0 |

### 10.4 Modul Inventori & Pembelian

| ID | Fitur | Prioritas |
|---|---|---|
| INV-01 | Ledger stok, stok per lokasi, kartu stok | P0 |
| INV-02 | Stok awal (input/import) | P0 |
| INV-03 | Belanja stok sederhana (mode UMKM) | P0 |
| INV-04 | Supplier, PO, approval, GRN parsial, faktur, hutang | P0 |
| INV-05 | Retur pembelian | P1 |
| INV-06 | Transfer antar lokasi/outlet (in-transit) | P0 |
| INV-07 | Stock opname (blind count, multi-penghitung, scan) | P0 |
| INV-08 | Penyesuaian stok & waste | P0 |
| INV-09 | Batch & expired (FEFO), notifikasi | P1 |
| INV-10 | Serial/IMEI | P1 |
| INV-11 | Produksi/rakitan | P1 |
| INV-12 | Konsinyasi (selesai v3.40) | P2 |
| INV-13 | Smart restock & forecast → draft PO | P2 |
| INV-14 | Landed cost (alokasi ongkir/bea ke HPP; biaya pihak ketiga setelah GRN selesai v3.41) | P2 |
| INV-15 | Portal supplier (lihat PO, konfirmasi) | P3 |

### 10.5 Modul Penjualan Lanjutan & Channel

| ID | Fitur | Prioritas |
|---|---|---|
| SLS-01 | Sales order, DO, invoice (grosir) | P1 |
| SLS-02 | Pre-order & DP | P1 |
| SLS-03 | KDS & printer dapur per station | P1 |
| SLS-04 | Self-order QR meja | P1 |
| SLS-05 | Toko online `/{slugTenant}` | P2 |
| SLS-06 | Pengiriman & kurir internal | P2 |
| SLS-07 | Booking & antrian (jasa) | P1 |
| SLS-08 | Work order (bengkel/servis) | P2 |
| SLS-09 | Tiket laundry & tracking | P1 |
| SLS-10 | Integrasi ojol/marketplace | P3 |
| SLS-11 | Modul Salesman (Flutter) & kunjungan | P2 |

### 10.6 Modul Pelanggan & Marketing

| ID | Fitur | Prioritas |
|---|---|---|
| CRM-01 | Data pelanggan, riwayat, tag | P0 |
| CRM-02 | Tier & level harga pelanggan | P1 |
| CRM-03 | Poin loyalti & penukaran | P1 |
| CRM-04 | Deposit/saldo & paket sesi | P1 |
| CRM-05 | Promo engine | P1 |
| CRM-06 | Voucher & gift card | P1 |
| CRM-07 | Broadcast WA/email bersegmen (RFM) — v3.44 | P2 |
| CRM-08 | Feedback/rating pasca transaksi | P2 |

### 10.7 Modul Karyawan

| ID | Fitur | Prioritas |
|---|---|---|
| EMP-01 | Data karyawan, role, PIN | P0 |
| EMP-02 | Jadwal shift kerja | P1 |
| EMP-03 | Absensi (selfie + geofence) | P1 |
| EMP-04 | Komisi | P1 |
| EMP-05 | Target penjualan | P2 |
| EMP-06 | Rekap gaji & kasbon | P2 |
| EMP-07 | Payroll penuh (PPh 21, BPJS) | P3 |

### 10.8 Modul Keuangan & Akuntansi

| ID | Fitur | Prioritas |
|---|---|---|
| FIN-01 | COA per template sektor | P0 |
| FIN-02 | Jurnal otomatis (penjualan, pembelian, stok, kas) | P0 |
| FIN-03 | Akun kas/bank, transfer, pengeluaran operasional | P0 |
| FIN-04 | Piutang & hutang + aging | P1 |
| FIN-05 | Jurnal umum manual | P1 |
| FIN-06 | Buku besar, neraca saldo | P0 |
| FIN-07 | Laba Rugi, Neraca, Arus Kas | P0 (L/R), P1 (Neraca, Arus Kas) |
| FIN-08 | Tutup periode & kunci | P1 |
| FIN-09 | Rekonsiliasi bank (import mutasi) | P2 |
| FIN-10 | Aset tetap & penyusutan | P2 |
| FIN-11 | Anggaran vs realisasi | P3 |
| FIN-12 | Export ke software akuntansi (CSV umum) | P2 |

### 10.9 Modul Pajak

| ID | Fitur | Prioritas |
|---|---|---|
| TAX-01 | Master tarif pajak (PPN, PB1/PBJT, dll.) yang bisa diubah & berlaku per tanggal | P0 |
| TAX-02 | Harga inklusif/eksklusif pajak | P0 |
| TAX-03 | PPN dengan DPP nilai lain | P0 |
| TAX-04 | Laporan PB1/PBJT per outlet per bulan | P0 |
| TAX-05 | Laporan PPN keluaran/masukan | P1 |
| TAX-06 | Export e-Faktur/Coretax (format impor yang berlaku) | P2 |
| TAX-07 | Faktur pajak per transaksi (atas permintaan pembeli ber-NPWP) | P2 |

### 10.10 Modul Laporan & Analitik

| Kelompok | Laporan | Prioritas |
|---|---|---|
| Penjualan | Ringkasan harian, per produk, per kategori, per jam (heatmap), per kasir, per channel, per metode bayar, per pelanggan, diskon & promo, void & retur | P0 |
| Shift & Kas | Laporan shift (X/Z), selisih kas, kas masuk/keluar | P0 |
| Stok | Posisi stok, kartu stok, mutasi, nilai persediaan, stok kritis, slow/fast moving, analisis ABC, expired | P0/P1 |
| Pembelian | Per supplier, per produk, PO outstanding, hutang & aging | P1 |
| F&B | Food cost teoretis vs aktual, menu engineering, waktu saji KDS | P1/P2 |
| Karyawan | Komisi, absensi, performa kasir | P1 |
| Keuangan | L/R, Neraca, Arus Kas, Buku Besar, Neraca Saldo | P0/P1 |
| Pajak | PB1/PBJT, PPN | P0/P1 |
| Anti-Fraud | Void/diskon/refund per kasir, selisih kas, buka laci tanpa transaksi, pola anomali | P1 |
| Konsolidasi | Semua outlet, perbandingan outlet, brand | P1 |
| Insight | Prediksi penjualan & restock, rekomendasi harga/bundling | P2 |
