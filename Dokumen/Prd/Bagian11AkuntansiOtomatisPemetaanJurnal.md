<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

## 11. Akuntansi Otomatis & Pemetaan Jurnal

### 11.1 Prinsip

- **Double-entry penuh.** Setiap jurnal wajib seimbang (Σ debit = Σ kredit). Ini dicek oleh constraint aplikasi dan test invariant.
- Jurnal dibuat oleh `AturanPosting` per jenis peristiwa. Pemetaan akun disimpan di tabel `PemetaanAkun` (default dari template, bisa diubah Akuntan).
- Jurnal otomatis **tidak bisa diedit**. Koreksi dilakukan dengan membatalkan dokumen sumber (jurnal pembalik otomatis).
- Mode posting: **real-time per transaksi** (default). Untuk tenant bervolume tinggi tersedia opsi **ringkasan per shift** (satu jurnal per shift per outlet) agar tabel jurnal tidak membengkak.
- Standar pelaporan: **SAK EMKM** (default UMKM) dengan opsi struktur akun sesuai **SAK EP** untuk entitas lebih besar.

### 11.2 Bagan Akun (COA) Default Inti

| Kode | Nama Akun | Tipe |
|---|---|---|
| 1-1100 | Kas Outlet (per outlet) | Aset |
| 1-1150 | Kas Brankas | Aset |
| 1-1200 | Bank (per rekening) | Aset |
| 1-1300 | Piutang Pencairan (QRIS/EDC/Gateway/Ojol) | Aset |
| 1-1400 | Piutang Usaha | Aset |
| 1-1450 | Piutang Karyawan (Kasbon) | Aset |
| 1-1460 | Piutang Klaim Promosi Pemasok (v1.93) | Aset |
| 1-1500 | Persediaan Barang Dagang | Aset |
| 1-1510 | Persediaan Bahan Baku | Aset |
| 1-1520 | Persediaan Barang Dalam Perjalanan (transfer) | Aset |
| 1-1600 | PPN Masukan | Aset |
| 1-1700 | Uang Muka Pembelian | Aset |
| 1-2000 | Aset Tetap / 1-2900 Akumulasi Penyusutan | Aset |
| 2-1100 | Hutang Usaha | Kewajiban |
| 2-1150 | Hutang Belum Difakturkan (GRNI) | Kewajiban |
| 2-1200 | Hutang Konsinyasi | Kewajiban |
| 2-1300 | PPN Keluaran | Kewajiban |
| 2-1310 | Hutang PB1/PBJT | Kewajiban |
| 2-1400 | Uang Muka Pelanggan (DP) | Kewajiban |
| 2-1500 | Saldo Deposit Pelanggan / Gift Card | Kewajiban |
| 2-1600 | Pendapatan Diterima Dimuka (paket sesi) | Kewajiban |
| 2-1700 | Hutang Service Charge (jika dibagikan ke karyawan) | Kewajiban |
| 3-1000 | Modal Pemilik | Ekuitas |
| 3-2000 | Ekuitas Saldo Awal | Ekuitas |
| 3-3000 | Laba Ditahan | Ekuitas |
| 3-4000 | Prive | Ekuitas |
| 4-1000 | Penjualan | Pendapatan |
| 4-1100 | Diskon Penjualan (kontra) | Pendapatan |
| 4-1200 | Retur Penjualan (kontra) | Pendapatan |
| 4-2000 | Pendapatan Jasa | Pendapatan |
| 4-3000 | Pendapatan Service Charge | Pendapatan |
| 4-9000 | Pendapatan Lain (selisih kas lebih, pembulatan) | Pendapatan |
| 5-1000 | Harga Pokok Penjualan | HPP |
| 5-1100 | Selisih HPP / Penyesuaian Persediaan | HPP |
| 5-1200 | Susut & Barang Rusak | HPP |
| 6-1000 | Beban Gaji & Komisi | Beban |
| 6-2000 | Beban Sewa, Listrik, Air, Internet | Beban |
| 6-3000 | Beban Biaya Pembayaran (MDR QRIS/EDC, komisi ojol) | Beban |
| 6-4000 | Beban Promosi (promo dibiayai marketing, opsi) | Beban |
| 6-5000 | Beban Penyusutan | Beban |
| 6-9000 | Beban Selisih Kas / Lain-lain | Beban |

Ekstensi sektor, contoh: F&B menambah `4-1010 Penjualan Makanan`, `4-1020 Penjualan Minuman`. Grosir menambah akun ongkir dan potongan tunai. Jasa menambah akun per jenis layanan.

### 11.3 Pemetaan Event → Jurnal

| Kode | Event | Debit | Kredit |
|---|---|---|---|
| J-05.1 | Stok awal (F-05a: + `SelisihHpp` bila ada selisih BR-04.3; pembatalan = jurnal pembalik) | Persediaan | Ekuitas Saldo Awal |
| J-04.1 | GRN diposting (sebelum faktur) | Persediaan | Hutang Belum Difakturkan (GRNI) |
| J-04.2 | Faktur pembelian | GRNI + PPN Masukan | Hutang Usaha |
| J-04.3 | Belanja stok tunai (mode UMKM) | Persediaan (+ PPN Masukan) | Kas/Bank |
| J-04.4 | Bayar hutang | Hutang Usaha | Kas/Bank |
| J-04.5 | Retur pembelian | Hutang Usaha | Persediaan (+ PPN Masukan kontra) |
| J-04.6 | Retur pembelian dengan pengembalian uang (BR-04.9) | Hutang Usaha (bagian pemotong) + Kas/Bank (refund) | Persediaan (+ PPN Masukan kontra) |
| J-04.7 | Nota debit pembelian (BR-04.10, v6.07) | Hutang Usaha (bagian pemotong) + Kas/Bank (refund) | Persediaan (stok masih ada) + HPP (barang terjual) + PPN Masukan kontra |
| J-07.1 | Penjualan (pendapatan) | Kas / Piutang Pencairan / Piutang Usaha / Uang Muka Pelanggan / Deposit Pelanggan (sesuai metode) + Diskon Penjualan | Penjualan / Pendapatan Jasa + Pendapatan Service Charge + PPN Keluaran / Hutang PB1 + Pendapatan Lain (pembulatan) |
| J-07.2 | Penjualan (HPP) | HPP | Persediaan (barang/bahan) |
| J-12.1 | Surat jalan grosir diposting (penyerahan BKP, BR-12.2) | HPP + Piutang Belum Difakturkan (+ Diskon Penjualan) | Persediaan + Penjualan + PPN Keluaran |
| J-12.2 | Faktur penjualan grosir diposting (reklasifikasi, tanpa pendapatan baru) | Piutang Usaha | Piutang Belum Difakturkan |
| J-12.3 | Surat jalan grosir dibatalkan (belum difakturkan) | jurnal pembalik J-12.1 | jurnal pembalik J-12.1 |
| J-12.4 | Retur grosir diposting (BR-12.7; nota kredit bila sudah difakturkan) | Retur Penjualan + PPN/PB1 (kontra) + Persediaan | Piutang Usaha **atau** Piutang Belum Difakturkan ; HPP ; Diskon Penjualan |
| J-12.5 | Hapus-buku piutang diposting (BR-12.8) | Beban Piutang Tak Tertagih | Piutang Usaha |
| J-08.2 | Uang QRIS tanpa penjualan diakui sebagai pendapatan lain (BR-08.8) | Kas / Bank (akun pilihan operator) | Pendapatan Lain |
| J-07.3 | DP pre-order diterima | Kas | Uang Muka Pelanggan |
| J-09.1 | Void | Pembalik penuh J-07.1 & J-07.2 | |
| J-09.2 | Retur penjualan | Retur Penjualan + PPN/PB1 (kontra) ; Persediaan | Kas/Piutang/Nota Kredit ; HPP |
| J-08.1 | Pencairan QRIS/EDC/gateway/ojol masuk rekening (BR-08.4) | Bank + Beban Biaya Pembayaran (potongan) | Piutang Pencairan / akun kliring metode ; Pendapatan Lain (bila setoran lebih besar dari nilai transaksi) |
| J-06.1 | Kas keluar (beban; F-06: akun dari `KategoriKas`) | Beban terkait | Kas Outlet |
| J-06.2 | Kas masuk non-penjualan (F-06) | Kas Outlet | Akun dari `KategoriKas` (pendapatan lain, ekuitas, dll.) |
| J-11.1 | Selisih kas kurang | Beban Selisih Kas | Kas Outlet |
| J-11.2 | Selisih kas lebih | Kas Outlet | Pendapatan Lain |
| J-11.3 | Setoran kas ke bank (F-06: `MutasiKas` jenis Setoran ke Kas Brankas) | Bank/Kas Brankas | Kas Outlet |
| J-05.2 | Transfer stok dikirim | Persediaan Dalam Perjalanan | Persediaan (lokasi asal) |
| J-05.3 | Transfer stok diterima | Persediaan (lokasi tujuan) | Persediaan Dalam Perjalanan |
| J-05.4 | Opname/penyesuaian kurang, bahan terbuang (F-05f v2.30) | Selisih HPP / Susut & Barang Rusak | Persediaan |
| J-05.5 | Opname/penyesuaian lebih | Persediaan | Selisih HPP |
| J-05.6 | Produksi (F-05e v2.29: persediaan hasil per jenis produk; overhead ke `OverheadProduksiDibebankan`) | Persediaan Barang Jadi | Persediaan Bahan Baku (+ Overhead Dibebankan) |
| J-05.7 | Konsinyasi terjual | HPP | Hutang Konsinyasi |
| J-16.1 | Top-up deposit / beli gift card | Kas | Saldo Deposit Pelanggan |
| J-16.2 | Beli paket sesi (v2.12: sebesar nilai bersih baris; pajak & pembayaran seperti J-07.1) | Kas | Pendapatan Diterima Dimuka |
| J-16.3 | Pemakaian sesi (v2.12: NilaiTersisa × n ÷ SisaSesi, sesi terakhir = sisa) | Pendapatan Diterima Dimuka | Pendapatan Jasa |
| J-16.4 | Penukaran poin (sebagai diskon) | Diskon Penjualan | (bagian dari J-07.1) |
| J-16.5 | Penerimaan klaim promo dari pemasok (v1.91; v1.93) | Kas/Bank | Piutang Klaim Promosi Pemasok (klaim sebelum v1.93: HPP) |
| J-16.6 | Klaim promo pemasok saat penjualan (v1.93, akrual) | Piutang Klaim Promosi Pemasok | HPP (imbalan pemasok mengurangi biaya pokok, PSAK 72) |
| J-16.7 | Klaim promo dipotong dari hutang pemasok (v1.94) | Hutang Usaha | Piutang Klaim Promosi Pemasok (klaim sebelum v1.93: HPP) |
| J-16.8 | Sisa paket sesi dikembalikan ke pelanggan (v2.12) | Pendapatan Diterima Dimuka | Kas/Bank |
| J-16.9 | Sisa paket sesi hangus (masa berlaku lewat / dihanguskan, v2.12) | Pendapatan Diterima Dimuka | Pendapatan Lain-lain |
| J-17.1 | Uang muka pesanan toko online diterima lewat QRIS web (v2.87) | akun kliring metode gerbang / Piutang Pencairan | Uang Muka Pelanggan |
| J-17.2 | Uang muka pesanan toko online dikembalikan ke pelanggan (v2.87) | Uang Muka Pelanggan | Kas/Bank |
| J-18.1 | Kasbon karyawan | Piutang Karyawan | Kas |
| J-18.2 | Bayar rekap gaji | Beban Gaji & Komisi (gaji kotor) | Piutang Karyawan (potongan kasbon), Pendapatan Lain (potongan lain), Kas/Bank (gaji bersih) |
| J-15.1 | Tutup tahun | Semua akun Pendapatan | Semua akun Beban & HPP, selisih ke Laba Ditahan |

> Catatan akuntansi poin loyalti: v1 memperlakukan poin sebagai diskon saat ditukar (pendekatan sederhana UMKM). Opsi akrual liabilitas poin (sesuai standar pengakuan pendapatan) disiapkan di fase 3 untuk tenant yang membutuhkan.
