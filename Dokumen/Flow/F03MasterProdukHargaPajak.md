<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### F-03 · Master Produk, Harga & Pajak

**Tujuan:** Katalog lengkap yang mendukung semua sektor.

**Jenis produk (`Produk.Jenis`, enum):**

| Tipe | Keterangan | Punya stok? | Contoh |
|---|---|---|---|
| `Stok` | Barang dagang biasa | Ya | Sabun, kaos |
| `IndukVarian` | Induk varian (tidak dijual langsung) | Tidak (anak yang punya stok) | Kaos → S/M/L × Merah/Biru |
| `Resep` | Produk jadi dari resep; stok bahan berkurang saat terjual | Tidak (bahan yang berkurang) | Es kopi susu |
| `Produksi` | Diproduksi dulu (batch), lalu punya stok | Ya | Roti, kue |
| `Paket` | Paket beberapa produk; stok komponen berkurang | Tidak | Paket hemat |
| `Jasa` | Jasa | Tidak | Potong rambut, cuci motor |
| `NonStok` | Tanpa stok | Tidak | Biaya kirim, kantong plastik gratis |
| `BahanBaku` | Bahan baku (tidak tampil di POS) | Ya | Susu, gula, biji kopi |
| `Konsinyasi` | Titipan | Ya (bukan aset) | Kue titipan |

**Atribut penting produk:**
- SKU (unik per tenant), barcode (bisa banyak per produk/satuan), nama, nama struk (pendek), kategori (bertingkat), brand, gambar.
- **Satuan & konversi:** satuan dasar (pcs) + satuan alternatif (pak = 10 pcs, dus = 12 pak). Harga & barcode boleh berbeda per satuan. Qty desimal diizinkan per produk (kg, meter).
- **Modifier group:** misal "Level Gula" (wajib, pilih 1), "Topping" (opsional, maks 3, masing-masing berharga & opsional mengurangi stok bahan).
- **Resep/BOM:** daftar bahan × qty × satuan, termasuk *yield* & *waste %*. Contoh: 1 cup Es Kopi Susu = 18 g kopi + 150 ml susu + 20 ml gula aren + 1 cup + 1 sedotan.
- **Pelacakan (`Produk.Pelacakan`):** `Tidak` | `Batch` (dengan tanggal kedaluwarsa) | `Seri`.
- **Pajak:** kategori pajak produk (Kena PPN, Bebas PPN, Kena PB1, Non-pajak) dan flag harga *termasuk pajak* / *belum termasuk pajak*.
- **HPP:** metode per tenant: **Moving Average (default)** atau **FIFO**.
- Min/Max stok per lokasi (untuk restock), flag "tampil di POS", "tampil di toko online", "boleh jual saat stok kosong".

**Harga (Price Engine):**

Harga final ditentukan berlapis (prioritas tinggi ke rendah):
1. Harga manual kasir (butuh izin `pos.harga.timpa`; selesai v6.19, D-138: alasan wajib, penyetuju ber-PIN bagi kasir tanpa izin, harga asli tersimpan, penjualan offline yang melanggar diterima sebagai tinjauan)
2. Promo aktif (Promo Engine, F-16)
3. **Price List** yang cocok (kombinasi outlet × channel × tier pelanggan × rentang waktu)
4. Harga bertingkat qty (tiered): 1–11 = Rp 5.000, 12+ = Rp 4.500
5. Harga dasar produk per satuan

**Aturan Bisnis:**
- BR-03.1 SKU unik per tenant. Barcode unik per tenant (boleh sama lintas tenant). Barcode boleh diisi dengan mengetik, pemindai USB (Enter), atau kamera perangkat, baik di Ubah produk maupun langsung di halaman detail (v6.58, D-171).
- BR-03.2 Produk yang sudah punya transaksi tidak bisa dihapus, hanya diarsipkan. Pengecualian (D-218): Pemilik boleh menghapus permanen produk beserta seluruh riwayatnya lewat dialog khusus.
- BR-03.3 Perubahan harga dicatat di tabel `RiwayatHarga` (kapan, siapa, lama/baru).
- BR-03.4 Perubahan resep **tidak** mengubah transaksi lampau (resep di-snapshot saat penjualan untuk kalkulasi HPP).
- BR-03.5 HPP produk resep = Σ (qty bahan × HPP bahan saat itu) / yield.
- BR-03.6 Import massal memakai validasi baris per baris dengan laporan error yang bisa diunduh. Import besar diproses di antrian (queue).

**Rincian F-03 (v1.31, diputuskan agen atas mandat D-12):**
- SKU otomatis `PRD-000001` bila kosong dan barcode internal EAN-13 berawalan `20` (dapat dikonfigurasi; parsing barcode timbangan 2x memakai awalan lain per tenant di F-07), keduanya dari `NomorUrutKatalog` di bawah kunci tenant sehingga kirim ganda tidak menggandakan. SKU/barcode dibandingkan tanpa beda huruf besar/kecil.
- `BatasSku` menghitung produk aktif saja: produk diarsipkan, induk varian, dan produk terhapus tidak dihitung. Ditegakkan saat buat, pulihkan, generasi varian, dan impor (berhenti rapi di batas; bisa dilanjutkan setelah kuota bertambah).
- Varian: maks. 3 atribut × 20 nilai, maks. 100 kombinasi per generasi; atribut baru tidak bisa ditambahkan ke induk yang sudah punya anak. Kategori maks. 3 tingkat.
- Hapus (BR-03.2): hanya bila tidak dipakai (bahan resep versi terbaru, bahan pilihan, komponen paket, anak varian terpakai; F-05/F-07 menambah pemeriksa lewat kontrak `PemeriksaPemakaianProduk`); soft delete dengan SKU & kunci varian dikosongkan. Selain itu hanya diarsipkan. Setiap penghapusan meninggalkan jejak `PenghapusanKatalog` untuk POS.
- Harga (BR-03.3): semua perubahan harga dasar, bertingkat, daftar harga, tambah cepat F-01, impor, varian, dan satuan yang dibuang tercatat di `RiwayatHarga` dengan `Sumber`. Harga pilihan (modifier) dicatat di `LogAudit` (lama/baru), bukan `RiwayatHarga`.
- Penentu harga lapis 3–5 (daftar harga → harga bertingkat → harga dasar per satuan) identik di server (`PenentuHarga`) dan `MesinKasir` Dart, diuji test vector `Spesifikasi/VektorUjiKalkulasi/Harga/` (11 berkas, 49 kasus). Pemilihan daftar harga: prioritas, lalu kespesifikan, lalu Uuid terkecil; batas waktu `MulaiPada` inklusif, `SelesaiPada` eksklusif.
- `HargaTermasukPajak` per produk bersifat override (null = ikut outlet); F-07 wajib mendukung baris inklusif & eksklusif campuran dalam satu dokumen dan menambah vektor untuknya.
- Kategori pajak produk: KenaPpn, BebasPpn, KenaPbjt, NonPajak, Lainnya (pajak lain/daerah), diturunkan dari jenis pajak kelompok (tidak ada angka tarif di kode).
- Resep: rumus susut `JumlahKotor = JumlahBersih ÷ (1 − Susut/100)`, susut 0 ≤ s < 100; JumlahKotor skala 4 (sama dengan jumlah yang dipotong dari stok), subtotal & HPP satuan skala 6. HPP resep = Σ(JumlahKotor × HPP bahan) ÷ JumlahHasil (BR-03.5); tampil "HPP belum tersedia" sampai HPP bahan ada (F-05a). Resep melingkar ditolak.
- Impor: xlsx/csv (jenis diperiksa dari isi), maks. 10 MB & 20.000 baris, di atas 300 baris lewat antrean per potongan 50 dan bisa dilanjutkan; mengulang berkas yang sama tidak menggandakan. Preset majoo/Moka/Pawoon bertanda asumsi ("Periksa pemetaan kolom sebelum mengimpor") sampai dicocokkan dengan berkas ekspor asli. Angka Indonesia: koma = desimal; titik = ribuan (berkelompok 3) kecuali satu titik diikuti tepat 2 angka pada uang; tanpa float. Kolom harga tanpa izin `produk.harga.ubah` diabaikan dengan peringatan; Harga Modal & Stok diabaikan (masuk F-05a). Berkas disimpan privat 30 hari. Ekspor memakai kolom templat impor (round trip) dan menetralkan sel berawalan `= + - @`.
- Gambar produk di disk privat, diubah ukuran (besar 800 px, kecil 256 px), nama berversi; URL publik menyusul F-17.
