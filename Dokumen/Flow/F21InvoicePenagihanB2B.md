<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### F-21 · Invoice & Penagihan B2B

> Untuk usaha yang **menagih** tanpa menjual lewat kasir: konsultan, agensi, kontraktor, distributor B2B. Barang berstok tetap lewat grosir (§9.7, F-12). Keputusan: **D-166**.

- **Jenis**: invoice penjualan (jasa & barang tanpa stok) dan **invoice uang muka (DP)** yang dipotong dari invoice akhir.
- **Alur**: Draf (ubah bebas, belum bernomor/berjurnal/berpiutang) → **Terbit** (nomor `INV/…`, jurnal J-21.1/J-21.2, `Piutang` berjatuh tempo = tanggal + termin) → dibayar (pelunasan piutang F-12, boleh sebagian, boleh dengan PPh dipotong pelanggan) atau **Dibatalkan** (jurnal pembalik).
- **BR-21.1** Hanya jasa & produk tanpa stok atau baris bebas; barang berstok ditolak (alasan ditulis, arahkan ke grosir).
- **BR-21.2** Total, pajak, PPh, dan potongan DP dihitung server; klien tidak mengirim angka turunan. Tarif pajak dari `TarifPajak` bertanggal berlaku.
- **BR-21.3** Invoice terbit tidak bisa diubah/dihapus (aturan #8). Koreksi = batalkan (jurnal pembalik) lalu buat baru. Batal ditolak bila piutang sudah dibayar (batalkan dulu pembayarannya) atau DP sudah dipakai invoice lain.
- **BR-21.4** Invoice tidak boleh bertanggal masa depan dan tidak boleh di periode terkunci.
- **BR-21.5** DP: PPN terutang saat DP diterima (UU PPN Pasal 11 ayat 1 huruf b); saat invoice akhir memotong DP, PPN DP **dikurangkan** dari PPN invoice akhir sehingga tidak terhitung dua kali. DP dipesan oleh draf invoice akhir sejak dipilih dan dilepas bila draf diganti/dibatalkan.
- **BR-21.6** PPh yang dipotong pelanggan: kode & tarif diisi pengguna; dicatat saat pembayaran sebagai PPh Dibayar Dimuka beserta nomor bukti potong. Tidak ada tarif bawaan sistem.
- **BR-21.7** Halaman publik `/i/{kode}`: kode rahasia tidak dapat ditebak dari nomor/Uuid; draf = 404; dibatalkan tetap terbuka bertanda Dibatalkan; hanya data yang pantas dibaca pembeli.
- **BR-21.8** Kirim lewat WhatsApp saja (D-33). Pengingat hanya untuk invoice yang masih bersisa; satu kali per tahap.
- **Back-office**: `/kelola/invoice` (daftar `TabelData`, form draf, detail, cetak A4/PDF, catat pembayaran, kirim/ingatkan, salin tautan). Izin `invoice.kelola` (Manajer Outlet, Akuntan, Pemilik, Admin); fitur paket `invoice.penagihan`.
- **Bagian berikutnya**: tautan bayar QRIS & lunas otomatis, pengingat otomatis H-3/H0/H+3, invoice berulang & bulk, penawaran → invoice, kuitansi, e-Meterai, laporan PPh 23, sektor "Perusahaan".
