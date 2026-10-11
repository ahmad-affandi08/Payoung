<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

## 5. Sektor Usaha & Persona

### 5.1 Template Sektor

Template Sektor adalah paket konfigurasi yang diterapkan saat onboarding (dan bisa ditambah kemudian per outlet). Isinya: modul aktif, mode layar kasir, COA default, satuan default, pajak default, contoh kategori, dan laporan unggulan.

| Kode | Sektor | Contoh usaha | Mode kasir | Modul khas yang aktif |
|---|---|---|---|---|
| FNB-RST | F&B Restoran | Rumah makan, resto keluarga | `table` | Meja & denah, KDS/printer dapur, split/merge bill, service charge, PB1, resep & HPP |
| FNB-CAF | F&B Kafe/Kedai Kopi | Coffee shop, kedai | `quick` + `table` | Modifier (gula, es, size), antrian nomor order, self-order QR |
| FNB-QSR | F&B Cepat Saji/Kaki Lima | Ayam geprek, bakso, angkringan | `quick` | Nomor antrian, paket combo, layar besar tombol |
| FNB-BAK | Bakery & Kue | Toko roti, katering kue | `retail` + pre-order | Produksi harian, pre-order + DP, expired harian |
| RTL-GEN | Retail Umum/Kelontong | Toko kelontong, minimarket | `retail` | Barcode, harga bertingkat, multi-satuan (pcs/pak/dus), expired |
| RTL-FRS | Sayur, Buah & Sembako | Toko sayur, buah, bumbu, sembako, kios pasar | `retail` | Timbang per kg, satuan ikat/karung/butir, barcode timbangan, tanpa PPN bawaan, susut layu/busuk |
| RTL-FSH | Fashion & Aksesoris | Butik, distro | `retail` | Varian (ukuran × warna) matrix, musim/koleksi, retur tukar |
| RTL-ELC | Elektronik & Gadget | Toko HP, komputer | `retail` | Serial number/IMEI, garansi, servis |
| RTL-PHR | Apotek/Toko Obat | Apotek, toko obat berizin | `retail` | Batch & expired (FEFO), golongan obat, resep dokter, harga HNA/HJA |
| RTL-BLD | Bahan Bangunan | Toko besi, material | `retail` + `wholesale` | Multi-satuan dengan desimal (m, kg, batang), pengiriman, tempo |
| RTL-PET | Pet Shop & Grooming | Toko perlengkapan hewan, grooming, penitipan | `retail` + `service` | Pakan & kedaluwarsa, produk + jasa grooming, loyalti |
| RTL-FLR | Florist & Toko Hadiah | Toko bunga, buket, tanaman hias, hadiah | `retail` | Buket dirakit sesuai pesanan (non-stok), pre-order + DP, bunga layu sebagai susut |
| WHS-DST | Grosir & Distributor | Grosir sembako, distributor | `wholesale` | Sales order, harga per level pelanggan, piutang & tempo, salesman, kanvas |
| SVC-SLN | Salon/Barbershop/Spa | Salon, barbershop, spa | `service` | Booking, staf & komisi, paket membership/sesi |
| SVC-LDR | Laundry | Laundry kiloan/satuan | `service` + tracking | Tiket laundry, status proses, estimasi selesai, notifikasi ambil |
| SVC-WRK | Bengkel | Bengkel motor/mobil | `service` + part | Work order, jasa + sparepart, mekanik, riwayat kendaraan |
| SVC-FIT | Gym, Kebugaran & Kursus | Pusat kebugaran, kelas yoga/zumba, kursus | `service` | Membership & paket sesi, tiket harian, personal trainer + komisi, loyalti |
| SVC-CWS | Cuci Kendaraan | Cuci motor/mobil, steam, poles | `service` + `quick` | Layanan per jenis kendaraan, komisi crew, paket cuci, loyalti |
| SVC-GEN | Jasa Umum | Fotokopi, percetakan, rental | `service` | Order kustom, DP, status pengerjaan |

Mode kasir menentukan layout layar POS (§17.4). Di kode dan data, nilainya memakai enum `ModeKasir` (D-05) yang tertulis dalam kurung:

- `retail` (`Retail`): fokus scan barcode, daftar keranjang panjang.
- `quick` (`Cepat`): grid tombol produk besar, satu ketukan per item.
- `table` (`Meja`): denah meja, order terbuka per meja.
- `service` (`Layanan`): pilih layanan + staf + jadwal.
- `wholesale` (`Grosir`): input cepat SKU × qty, harga per level, tempo.

### 5.2 Persona

| Persona | Deskripsi | Kebutuhan utama | Perangkat |
|---|---|---|---|
| **Owner (Bu Rina)** | Pemilik 3 outlet kafe + 1 toko merchandise | Dashboard real-time, laporan laba, kontrol kebocoran, notifikasi | HP Android, laptop |
| **Manajer Outlet (Dimas)** | Mengelola 1 outlet, 8 staf | Jadwal shift, stok, approval void/diskon, opname | Tablet, PC |
| **Kasir (Sari)** | Lulusan SMA, 2 minggu training | Layar sederhana, cepat, tidak takut salah | Tablet Android 10" |
| **Staf Dapur/Barista** | Menyiapkan pesanan | KDS jelas, urutan order, tanda selesai | Tablet/monitor dapur |
| **Staf Gudang (Anto)** | Terima barang, transfer, opname | Scan barcode, form cepat, jelas selisih | HP Android |
| **Akuntan/Konsultan Pajak** | Eksternal, akses baca | Jurnal, buku besar, export e-Faktur, tutup buku | Laptop |
| **Pelanggan Akhir** | Pembeli | Struk digital, poin, self-order, booking | HP |
| **Tim Pengelola {{APP}}** (Super Admin, Keuangan, Dukungan, Teknis, Konten & Legal, Mitra & Penjualan, Analis) | Tim internal pengelola SaaS | Menyiapkan paket, template sektor & regulasi; mengelola tenant, tagihan, dukungan, rilis aplikasi, mitra (§8 Bagian A) | Laptop |
| **Mitra/Reseller** | Agen daerah & perujuk | Mendaftarkan dan mendampingi tenant, memantau komisi | HP, laptop |
