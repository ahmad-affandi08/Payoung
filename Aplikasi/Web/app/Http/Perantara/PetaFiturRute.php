<?php

declare(strict_types=1);

namespace App\Http\Perantara;

/**
 * D-111 (BR-P04.3): satu-satunya peta rute → fitur paket yang ditegakkan server. Sebelumnya fitur berbayar hanya
 * digembok di menu (`fitur:` di `TataLetakAplikasi.tsx`), sehingga tenant Gratis/Starter bisa membuka URL-nya langsung.
 *
 * Aturan dibaca berurutan, **yang pertama cocok menang** (taruh pola yang lebih khusus di atas). Kolom:
 * - `Pola`: nama rute (wildcard `*`, seperti `Request::routeIs`);
 * - `Fitur`: kunci di `database/Data/KatalogFitur.json`;
 * - `Mode`: `tulis` = halaman dan data yang sudah ada tetap bisa dilihat (tenant yang turun paket tidak kehilangan
 *   akses ke datanya), tetapi membuat/mengubah ditolak (semua metode selain GET/HEAD, ditambah halaman formulir
 *   `*.buat` dan `*.ubah`); `semua` = fiturnya sendiri adalah halaman/laporan, jadi seluruh akses ditolak;
 * - `Nama`: nama fitur di pesan penolakan;
 * - `Query` (opsional): hanya berlaku bila parameter query cocok (mis. tab Anti-fraud di laporan penjualan).
 *
 * Tes `PenegakanFiturPaketTes` menjaga peta ini: setiap pola harus cocok dengan rute yang terdaftar, setiap kunci
 * harus ada di katalog, dan fitur yang digembok di menu harus punya aturan di sini.
 */
final class PetaFiturRute
{
    /**
     * @var list<array{Pola: string, Fitur: string, Mode: 'tulis'|'semua', Nama: string, Query?: array<string, string>}>
     */
    public const ATURAN = [
        // Persediaan
        ['Pola' => 'kelola.persediaan.opname.*', 'Fitur' => 'stok.opname', 'Mode' => 'tulis', 'Nama' => 'Stok opname'],
        ['Pola' => 'kelola.persediaan.transfer.*', 'Fitur' => 'stok.transfer', 'Mode' => 'tulis', 'Nama' => 'Transfer stok'],
        ['Pola' => 'kelola.persediaan.permintaan.*', 'Fitur' => 'stok.transfer', 'Mode' => 'tulis', 'Nama' => 'Transfer stok'],
        ['Pola' => 'kelola.gudang.simpan', 'Fitur' => 'stok.multi-gudang', 'Mode' => 'tulis', 'Nama' => 'Multi-gudang'],
        ['Pola' => 'kelola.persediaan.stok-awal.*', 'Fitur' => 'stok.dasar', 'Mode' => 'tulis', 'Nama' => 'Stok & belanja stok'],
        ['Pola' => 'kelola.persediaan.penyesuaian.*', 'Fitur' => 'stok.dasar', 'Mode' => 'tulis', 'Nama' => 'Stok & belanja stok'],
        ['Pola' => 'kelola.persediaan.produksi.*', 'Fitur' => 'stok.dasar', 'Mode' => 'tulis', 'Nama' => 'Stok & belanja stok'],
        ['Pola' => 'kelola.persediaan.bahan-terbuang.*', 'Fitur' => 'stok.dasar', 'Mode' => 'tulis', 'Nama' => 'Stok & belanja stok'],
        ['Pola' => 'kelola.pembelian.belanja-stok.*', 'Fitur' => 'stok.dasar', 'Mode' => 'tulis', 'Nama' => 'Stok & belanja stok'],

        // Pembelian, harga, dapur, karyawan
        ['Pola' => 'kelola.pembelian.pesanan.*', 'Fitur' => 'pembelian.po', 'Mode' => 'tulis', 'Nama' => 'Pesanan pembelian'],
        ['Pola' => 'kelola.pembelian.permintaan.*', 'Fitur' => 'pembelian.po', 'Mode' => 'tulis', 'Nama' => 'Pesanan pembelian'],
        ['Pola' => 'kelola.daftar-harga.*', 'Fitur' => 'harga.daftar-harga', 'Mode' => 'tulis', 'Nama' => 'Daftar harga'],
        ['Pola' => 'kelola.stasiun-dapur.*', 'Fitur' => 'pos.kds', 'Mode' => 'tulis', 'Nama' => 'Kitchen display (KDS)'],
        ['Pola' => 'kelola.karyawan.komisi.laporan*', 'Fitur' => 'karyawan.komisi', 'Mode' => 'semua', 'Nama' => 'Komisi karyawan'],
        ['Pola' => 'kelola.karyawan.komisi*', 'Fitur' => 'karyawan.komisi', 'Mode' => 'tulis', 'Nama' => 'Komisi karyawan'],

        // Grosir
        ['Pola' => 'kelola.grosir.*', 'Fitur' => 'piutang.grosir', 'Mode' => 'tulis', 'Nama' => 'Piutang & grosir'],

        // Invoice & penagihan B2B (F-21, D-166)
        ['Pola' => 'kelola.invoice.*', 'Fitur' => 'invoice.penagihan', 'Mode' => 'tulis', 'Nama' => 'Invoice & penagihan'],

        // Pelanggan
        ['Pola' => 'kelola.pelanggan.buat', 'Fitur' => 'pelanggan.dasar', 'Mode' => 'tulis', 'Nama' => 'Data pelanggan'],
        ['Pola' => 'kelola.pelanggan.simpan', 'Fitur' => 'pelanggan.dasar', 'Mode' => 'tulis', 'Nama' => 'Data pelanggan'],
        ['Pola' => 'kelola.pelanggan.perbarui', 'Fitur' => 'pelanggan.dasar', 'Mode' => 'tulis', 'Nama' => 'Data pelanggan'],
        ['Pola' => 'kelola.pelanggan.arsipkan', 'Fitur' => 'pelanggan.dasar', 'Mode' => 'tulis', 'Nama' => 'Data pelanggan'],
        ['Pola' => 'kelola.pelanggan.pulihkan', 'Fitur' => 'pelanggan.dasar', 'Mode' => 'tulis', 'Nama' => 'Data pelanggan'],
        ['Pola' => 'kelola.pelanggan.massal', 'Fitur' => 'pelanggan.dasar', 'Mode' => 'tulis', 'Nama' => 'Data pelanggan'],
        ['Pola' => 'kelola.pelanggan.gift-card.*', 'Fitur' => 'pelanggan.deposit', 'Mode' => 'tulis', 'Nama' => 'Deposit & gift card'],
        ['Pola' => 'kelola.pelanggan.impor.*', 'Fitur' => 'pelanggan.dasar', 'Mode' => 'tulis', 'Nama' => 'Data pelanggan'],

        // Akuntansi penuh (laba rugi masuk laporan lengkap, bukan akuntansi penuh)
        ['Pola' => 'kelola.akuntansi.laporan.laba-rugi*', 'Fitur' => 'laporan.lengkap', 'Mode' => 'semua', 'Nama' => 'Laporan lengkap'],
        ['Pola' => 'kelola.akuntansi.laporan.*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],
        ['Pola' => 'kelola.akuntansi.jurnal.*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],
        ['Pola' => 'kelola.akuntansi.jurnal-umum.*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],
        ['Pola' => 'kelola.akuntansi.akun.*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],
        ['Pola' => 'kelola.akuntansi.pemetaan.*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],
        ['Pola' => 'kelola.akuntansi.aset-tetap*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],
        ['Pola' => 'kelola.akuntansi.anggaran.*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],
        ['Pola' => 'kelola.akuntansi.tutup-buku*', 'Fitur' => 'akuntansi.penuh', 'Mode' => 'semua', 'Nama' => 'Akuntansi penuh'],

        // Laporan
        ['Pola' => 'kelola.laporan.penjualan*', 'Fitur' => 'keamanan.anti-fraud', 'Mode' => 'semua', 'Nama' => 'Anti-fraud', 'Query' => ['tab' => 'anti-fraud']],
        ['Pola' => 'kelola.laporan.penjualan*', 'Fitur' => 'laporan.insight', 'Mode' => 'semua', 'Nama' => 'Forecast & insight', 'Query' => ['tab' => 'prediksi']],
        ['Pola' => 'kelola.laporan.penjualan*', 'Fitur' => 'laporan.insight', 'Mode' => 'semua', 'Nama' => 'Forecast & insight', 'Query' => ['tab' => 'bundling']],
        ['Pola' => 'kelola.laporan.penjualan.insight-whatsapp', 'Fitur' => 'laporan.insight', 'Mode' => 'tulis', 'Nama' => 'Forecast & insight'],
        ['Pola' => 'kelola.laporan.pajak*', 'Fitur' => 'laporan.lengkap', 'Mode' => 'semua', 'Nama' => 'Laporan lengkap'],
        ['Pola' => 'kelola.laporan.pembelian*', 'Fitur' => 'laporan.lengkap', 'Mode' => 'semua', 'Nama' => 'Laporan lengkap'],
        ['Pola' => 'kelola.laporan.stok*', 'Fitur' => 'laporan.lengkap', 'Mode' => 'semua', 'Nama' => 'Laporan lengkap'],
        ['Pola' => 'kelola.laporan.apotek*', 'Fitur' => 'laporan.lengkap', 'Mode' => 'semua', 'Nama' => 'Laporan lengkap'],

        // API POS (aplikasi Kasir) dan API Owner
        ['Pola' => 'pos.dapur.*', 'Fitur' => 'pos.kds', 'Mode' => 'semua', 'Nama' => 'Kitchen display (KDS)'],
        ['Pola' => 'pos.gudang.opname*', 'Fitur' => 'stok.opname', 'Mode' => 'semua', 'Nama' => 'Stok opname'],
        ['Pola' => 'pos.gudang.transfer*', 'Fitur' => 'stok.transfer', 'Mode' => 'semua', 'Nama' => 'Transfer stok'],
        ['Pola' => 'pos.gudang.pesanan-pembelian', 'Fitur' => 'pembelian.po', 'Mode' => 'semua', 'Nama' => 'Pesanan pembelian'],
        ['Pola' => 'pos.gudang.penerimaan', 'Fitur' => 'pembelian.po', 'Mode' => 'semua', 'Nama' => 'Pesanan pembelian'],
        ['Pola' => 'pemilik.laporan.laba-rugi', 'Fitur' => 'laporan.lengkap', 'Mode' => 'semua', 'Nama' => 'Laporan lengkap'],
        ['Pola' => 'pemilik.laporan.anti-fraud', 'Fitur' => 'keamanan.anti-fraud', 'Mode' => 'semua', 'Nama' => 'Anti-fraud'],
        ['Pola' => 'pemilik.persetujuan-dokumen.pesanan-pembelian.*', 'Fitur' => 'pembelian.po', 'Mode' => 'semua', 'Nama' => 'Pesanan pembelian'],
        ['Pola' => 'pemilik.insight', 'Fitur' => 'laporan.insight', 'Mode' => 'semua', 'Nama' => 'Forecast & insight'],
    ];
}
