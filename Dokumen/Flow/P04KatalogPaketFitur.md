<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### P-04 · Katalog Paket & Fitur

**Tujuan:** Paket langganan, batas pemakaian, dan fitur ditentukan lewat data, bukan hard-code, sehingga harga dan isi paket bisa diubah tanpa rilis kode.
**Aktor:** Super Admin, Keuangan.

**Komponen:**
- **Katalog fitur**: setiap fitur punya kunci unik (misal `pos.mode-meja`, `promo.mesin`, `api.publik`, `persetujuan.jarak-jauh`), nama, modul, keterangan.
- **Paket**: kode, nama, harga bulanan & tahunan, masa trial, fitur yang termasuk, dan **batas**: jumlah outlet, perangkat per outlet, pengguna, SKU, kuota pesan WA, penyimpanan file.
- **Add-on**: fitur atau batas tambahan yang bisa dibeli terpisah (outlet tambahan, self-order QR, WA, insight).
- **Kupon langganan**: kode, diskon (% atau nominal), durasi (bulan), kuota, paket yang berlaku, masa berlaku.

**Evaluasi fitur untuk tenant:**
```
FiturAktif(tenant, kunci) =
    (fitur ada di paket tenant  ATAU  add-on aktif  ATAU  override pengelola aktif)
    DAN flag fitur global mengizinkan (P-10)
    DAN template/outlet mengaktifkan modul tersebut
```

**Aturan Bisnis:**
- BR-P04.1 Perubahan harga paket hanya berlaku untuk **tagihan berikutnya**. Tenant lama dapat dikunci pada harga lama (*grandfathering*) sesuai pilihan saat perubahan.
- BR-P04.2 Paket yang diarsipkan tidak bisa dipilih tenant baru, tetapi tenant yang sudah memakainya tidak terdampak.
- BR-P04.3 Batas ditegakkan di backend (perantara `PastikanBatasPaket`) dan dikirim ke aplikasi lewat `konfigurasi-aplikasi`. Penegakan di aplikasi hanya untuk UX, server tetap penentu.
- BR-P04.4 Saat downgrade melebihi batas (misal 5 outlet ke paket 1 outlet), **data tidak dihapus**. Tenant diminta memilih outlet/perangkat yang tetap aktif, sisanya menjadi hanya-baca. *Implementasi (v5.57, D-117):* turun paket mandiri berlaku pada perpanjangan berikutnya; outlet dan anggota yang tidak dipilih diarsipkan/dinonaktifkan (setara hanya-baca: tidak bisa dipakai bertransaksi, datanya tetap ada dan bisa dipulihkan).
- BR-P04.5 Harga paket berversi (`HargaPaket`): Keuangan/Super Admin menyusun & mengajukan, **1 penyetuju Super Admin** yang bukan penyusun (four-eyes §19.3); sejak D-34 (v4.10) ajuan Super Admin langsung terbit dan Super Admin boleh menyetujui draf yang ia susun. `BerlakuMulai` tidak boleh di masa lalu. Harga terbit tidak diubah; harga baru mengakhiri harga lama sehari sebelumnya. `TerapkanKePelangganLama = false` berarti langganan yang sudah berjalan tetap memakai harga lama (grandfathering).
- BR-P04.6 Paket hanya bisa **diaktifkan** bila punya harga terbit atau ditandai `HargaNegosiasi` (Enterprise). Mengubah fitur/batas paket **Aktif** hanya oleh Super Admin dengan alasan wajib dan tercatat di audit, karena langsung berdampak ke tenant.
- BR-P04.7 Penegakan batas (`PastikanBatasPaket`), `konfigurasi-aplikasi`, downgrade (BR-P04.4), dan pemakaian kupon dibangun bersama `Langganan` (F-00/F-19/P-08). P-04 menyediakan data katalog dan `EvaluatorFitur` murni (fitur aktif & batas efektif = paket + add-on + override).
- BR-P04.8 **Kuota transaksi penjualan per bulan** (`Paket.KuotaTransaksiBulanan`, D-184) adalah batas paket seperti batas lain: diatur dari konsol (Katalog › Paket & Add-on, override per tenant), null = tak terbatas (bawaan semua paket), 0 = tidak ada transaksi baru. Dihitung langsung dari `Penjualan` bulan kalender WIB menurut `TanggalBisnis` (semua status, termasuk Void; retur tidak). **Penegakan lunak, tidak pernah menolak data yang sudah terjadi:** `sinkron/kirim` selalu menerima penjualan dari perangkat (uang sudah di laci, K33/D-115). Yang ditahan hanya transaksi *baru*: layar jual & meja aplikasi Kasir (payload `konfigurasi-aplikasi` → `Kuota.Transaksi`: `Periode`, `Terpakai`, `Batas`, `Sisa`, `Persen`, `Status` TanpaBatas/Normal/HampirHabis [≥ 80%]/Habis; aplikasi menambah penjualan lokal yang belum tercakup hitungan server sehingga tetap terkunci tepat waktu saat offline) dan pesanan toko online/kios/self-order. Layar lain (shift, kas, riwayat, stok, sinkron) tetap bisa dipakai supaya shift bisa ditutup. Peringatan: Kotak Tindakan `kuota.transaksi` (Perhatian/Penting) + notifikasi `KuotaTransaksi`, panel di Langganan, baris Pemakaian di tampilan 360° konsol. Tenant tanpa langganan tidak dikenai kuota.
