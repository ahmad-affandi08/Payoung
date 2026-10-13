<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### F-04 · Supplier & Pembelian

**Tujuan:** Barang masuk tercatat benar, HPP akurat, hutang terkendali.
**Aktor:** Staf Gudang/Purchasing, Manajer, Owner (approval).

**Alur:**
```mermaid
flowchart LR
    PR[Permintaan Pembelian<br/>opsional] --> PO[Purchase Order]
    PO -->|approve| POS[PO Terkirim ke Supplier<br/>PDF/WA]
    POS --> GRN[Penerimaan Barang - GRN<br/>sebagian / penuh]
    GRN --> PI[Faktur Pembelian]
    PI --> AP[Hutang Usaha]
    AP --> PAY[Pembayaran Hutang]
    GRN -.-> RTR[Retur Pembelian]
    PI -.-> DN[Nota Debit]
```

**Langkah:**
1. (Opsional) Outlet membuat **Permintaan Pembelian** (selesai @ v6.06) atau sistem membuat draft dari *Smart Restock* (stok < min).
2. **PO**: pilih supplier, lokasi tujuan, item × qty × satuan × harga, diskon, PPN masukan, ongkir, termin (tunai/tempo N hari).
3. **Approval PO** jika total > batas yang dikonfigurasi (misal > Rp 5 juta butuh Owner).
4. Kirim PO ke supplier (PDF, WA link, email).
5. **GRN**: gudang menerima barang, input qty diterima per item (boleh parsial), batch & expired, foto surat jalan. **Stok bertambah saat GRN diposting.**
6. **Faktur Pembelian**: dicocokkan dengan PO & GRN (*3-way matching*). Selisih harga memicu penyesuaian HPP.
7. **Hutang** tercatat sesuai termin. Muncul di daftar "Jatuh Tempo".
8. **Pembayaran Hutang**: dari akun kas/bank, bisa sebagian, bisa banyak faktur sekaligus.
9. **Retur Pembelian**: barang rusak/salah dikembalikan, stok berkurang, hutang berkurang (atau refund). **Nota Debit** (v6.07): selisih harga/klaim tanpa barang kembali, hutang berkurang tanpa mutasi barang keluar.

**Pembelian langsung (UMKM mikro):** mode sederhana "Belanja Stok". Satu form berisi supplier (opsional), item, total, dan bayar tunai. Sistem otomatis membuat GRN + faktur + pembayaran dalam satu langkah.

**State Machine `PesananPembelian.Status`:** `Draf → MenungguPersetujuan → Disetujui → DiterimaSebagian → Diterima → Ditutup` (+ `Dibatalkan` hanya bila belum ada penerimaan barang).

**Aturan Bisnis:**
- BR-04.1 GRN tidak boleh melebihi qty PO kecuali toleransi (%) yang disetel.
- BR-04.2 HPP Moving Average dihitung ulang saat GRN diposting: `HPP_baru = (stok_lama × HPP_lama + qty_masuk × harga_masuk) / (stok_lama + qty_masuk)`. Harga masuk sudah termasuk alokasi ongkir/diskon (landed cost), tidak termasuk PPN masukan yang dapat dikreditkan.
- BR-04.3 Jika stok lama negatif (jual saat kosong), HPP baru = harga masuk, dan selisih HPP dibukukan ke akun "Selisih HPP".
- BR-04.4 Faktur dengan harga berbeda dari GRN: jika stok masih ada → revaluasi persediaan; jika sudah terjual → selisih ke HPP.
- BR-04.9 Retur pembelian atas faktur yang sudah dibayar (dan retur belanja stok tunai): nilai retur melebihi sisa hutang faktur → selebihnya `NilaiRefund`, diterima tunai ke akun kas/bank pilihan operator (J-04.6); sisa hutang tidak pernah negatif. Pembatalan retur membalik keduanya; pembayaran hutang yang fakturnya punya retur ber-refund tidak bisa dibatalkan lebih dulu.
- BR-04.10 Nota debit pembelian (v6.07, D-135) atas faktur: mengurangi hutang untuk selisih harga/klaim tanpa barang kembali. Nilai dibagi ke baris faktur sebanding sisa nilai; bagian stok yang masih ada menurunkan nilai persediaan (penilaian ulang), bagian yang sudah terjual mengurangi HPP (BR-04.4); PPN masukan dikontra sebanding PPN faktur; bagian melebihi sisa hutang = pengembalian uang (BR-04.9); di atas `BatasPersetujuanPo` butuh izin `pembelian.po.setujui`. Jurnal J-04.7. Pembatalan membalik semuanya selama stok belum bergerak.

**Dampak Jurnal:** lihat §11.3 (J-04.x).

**Rincian F-04 fase 1 (v1.51, back-office; diputuskan agen atas mandat D-12):**
- **Pemasok** (`/kelola/pembelian/pemasok`): kode, nama, kontak, alamat, NPWP, termin bawaan (tunai / tempo N hari), rekening; nonaktifkan bukan hapus bila sudah dipakai. Izin `pembelian.kelola`.
- **Pesanan pembelian (PO)** `PO/{OUTLET}/{YYMM}/{SEQ4}`: pemasok, lokasi stok tujuan, baris produk × jumlah × satuan pembelian (konversi ke satuan dasar) × harga, diskon baris, PPN masukan (bila pemasok PKP, tarif dari `TarifPajak`, CLAUDE.md #12), ongkir, termin. Status `Draf → MenungguPersetujuan → Disetujui → DiterimaSebagian → Diterima → Ditutup` (+ `Dibatalkan` bila belum ada penerimaan). Total di atas `BatasPersetujuanPo` (pengaturan pembelian, bawaan Rp 5.000.000, §19.2) butuh persetujuan pemegang izin baru `pembelian.po.setujui` (bawaan Pemilik & Admin) yang bukan pembuatnya; di bawah batas langsung `Disetujui`. Tampilan cetak PO (HTML siap cetak/PDF peramban); kirim WA/email menyusul.
- **Penerimaan barang (GRN)** `GR/{OUTLET}/{YYMM}/{SEQ4}`: dari PO (sisa per baris) atau tanpa PO; jumlah diterima per baris (parsial), batch & kedaluwarsa untuk produk batch, nomor seri untuk produk seri; BR-04.1 toleransi `ToleransiPenerimaanPersen` (bawaan 0). Posting = mutasi `PenerimaanPembelian` bernilai harga landed (harga − diskon + alokasi ongkir sebanding nilai, tanpa PPN masukan yang dapat dikreditkan) → HPP BR-04.2/04.3 oleh buku stok; jurnal J-04.1 (Dr persediaan, Cr `HutangBelumDifakturkan`). Dokumen terposting tidak diubah; pembatalan = pembalik bila stok belum terpakai.
- **Faktur pembelian** `FB/{YYMM}/{SEQ4}` (nomor faktur pemasok disimpan terpisah, unik per pemasok): memilih GRN yang belum difakturkan (3-way matching PO–GRN–faktur, tampilkan selisih jumlah & harga), PPN masukan, jatuh tempo dari termin. Jurnal J-04.2: Dr `HutangBelumDifakturkan` (nilai GRN) + Dr `PpnMasukan` + selisih harga (BR-04.4: bagian yang stoknya masih ada → persediaan, bagian yang sudah terjual → `Hpp`; fase 1 boleh seluruh selisih ke `SelisihHpp` dengan catatan bila pemisahan belum tersedia), Cr `HutangUsaha`.
- **Pembayaran hutang** `BH/{YYMM}/{SEQ4}`: dari akun kas/bank (`Akun.KasBank`), satu atau banyak faktur satu pemasok, boleh sebagian; J-04.4. Daftar hutang & jatuh tempo (umur 0–30/31–60/61–90/>90 hari).
- **Retur pembelian** `RB/{OUTLET}/{YYMM}/{SEQ4}`: dari GRN (jumlah ≤ diterima − sudah diretur), stok keluar `ReturPembelian` bernilai HPP penerimaan, mengurangi hutang faktur (atau hutang belum difakturkan bila belum difakturkan); J-04.5. Bagian nilai yang melebihi sisa hutang faktur (faktur sudah dibayar, termasuk belanja stok tunai) diterima sebagai uang ke akun kas/bank pilihan operator (BR-04.9, J-04.6, v5.62).
- **Nota debit pembelian** `ND/{YYMM}/{SEQ4}` (v6.07, BR-04.10, J-04.7): dari rincian faktur (*Buat nota debit*), jenis Selisih harga / Diskon susulan / Klaim barang rusak / Klaim kekurangan / Lainnya, nilai (sebelum PPN) dibagi ke baris faktur terpilih (bawaan semua) sebanding sisa nilai baris; tanpa barang keluar. Bagian stok masih ada → turun nilai persediaan (mutasi penilaian ulang `RevaluasiKeluar`/`RevaluasiMasuk`), bagian terjual → kurangi HPP; PPN masukan dikontra sebanding PPN faktur bila dikreditkan; mengurangi sisa hutang faktur, kelebihannya diterima sebagai uang ke kas/bank (BR-04.9). Nomor nota dari pemasok opsional. Pembatalan: pembalik jurnal + mutasi selama stok belum bergerak.
- **Belanja stok** (mode UMKM): satu form pemasok opsional, lokasi, baris, total, akun kas/bank pembayar → membuat GRN + faktur + pembayaran lunas sekaligus dalam satu transaksi; J-04.3.
- Semua dokumen di satu transaksi DB bersama stok & jurnal (aturan #9–#10), periode terkunci ditolak, daftar memakai `TabelData`, lampiran (surat jalan/faktur) privat opsional.
- **Keputusan implementasi F-04 (v1.52):** faktur mencakup penerimaan satu pemasok & satu outlet yang belum difakturkan; jumlah baris faktur tidak bisa diubah (jumlah diterima dikurangi retur), hanya harga & diskon; selisih harga BR-04.4 fase 1 seluruhnya ke `SelisihHpp` (pemisahan stok tersisa/terjual menyusul). Nomor faktur pemasok unik per pemasok. Retur pembelian dibuat dari halaman detail penerimaan. Pengaturan pembelian (`BatasPersetujuanPo`, `ToleransiPenerimaanPersen`) berizin `pembelian.po.setujui`. Peran bawaan `StafPembelian` punya `pembelian.kelola`. Cetak PO = halaman HTML siap cetak/PDF peramban.
