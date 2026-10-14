<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Enum;

/**
 * Jenis dokumen sumber sebuah `Jurnal` (`Jurnal.JenisSumber`, DesainF05a B.4). Flow berikutnya menambah case
 * (Penjualan, PenerimaanBarang, …). Tautan dibangun dari Uuid sumber tanpa kueri lintas domain.
 */
enum JenisSumberJurnal: string
{
    case StokAwal = 'StokAwal';
    case MutasiKas = 'MutasiKas';
    case Penjualan = 'Penjualan';
    // F-09 J-09.2 (void memakai jurnal pembalik ber-sumber `Penjualan`, kunci `Void`).
    case ReturPenjualan = 'ReturPenjualan';
    case TutupShift = 'TutupShift';
    // F-13a: transaksi kas & bank back-office (pengeluaran, penerimaan, transfer, dan pembaliknya).
    case TransaksiKasBank = 'TransaksiKasBank';
    // F-05b: transfer (J-05.2/J-05.3, susut penutup J-05.4), stok opname & penyesuaian stok (J-05.4/J-05.5).
    case TransferStok = 'TransferStok';
    case StokOpname = 'StokOpname';
    case PenyesuaianStok = 'PenyesuaianStok';
    // F-04 fase 1: GRN (J-04.1, belanja stok J-04.3), faktur (J-04.2), pembayaran hutang (J-04.4), retur (J-04.5).
    case PenerimaanBarang = 'PenerimaanBarang';
    case FakturPembelian = 'FakturPembelian';
    case PembayaranHutang = 'PembayaranHutang';
    case ReturPembelian = 'ReturPembelian';
    // F-12: pelunasan piutang (Dr kas/bank, Cr Piutang Usaha) dan pembatalannya.
    case PembayaranPiutang = 'PembayaranPiutang';
    // F-12 bagian 2: uang muka pre-order diterima (J-07.3) dan penyelesaian sisanya (dikembalikan/hangus).
    case PesananPenjualan = 'PesananPenjualan';
    // F-15 tutup tahun (J-15.1): jurnal penutup pendapatan, HPP & beban ke Laba Ditahan; IdSumber = tahun.
    case TutupTahun = 'TutupTahun';
    // F-18 bagian 3: kasbon karyawan (J-18.1) dan pelunasannya ke kas/bank.
    case Kasbon = 'Kasbon';
    case PelunasanKasbon = 'PelunasanKasbon';
    // F-18 bagian 3: pembayaran rekap gaji (gaji pokok + komisi + tambahan − potongan).
    case RekapGaji = 'RekapGaji';
    // F-16c bagian 4b: penerimaan klaim promo dari pemasok (J-16.5: Dr kas/bank, Cr HPP).
    case PenerimaanKlaimPemasok = 'PenerimaanKlaimPemasok';
    // F-16d bagian 1: isi deposit pelanggan dari POS (J-16.1) & pembatalannya; penarikan/penyesuaian deposit
    // back-office (sumber = baris `MutasiDeposit`).
    case IsiDeposit = 'IsiDeposit';
    case MutasiDeposit = 'MutasiDeposit';
    // CRM-06 gift card (D-153): penjualan kartu dari back-office (J-16.10), hangus, dan pengembalian uang (sumber = baris
    // `MutasiGiftCard`).
    case MutasiGiftCard = 'MutasiGiftCard';
    // F-16d bagian 2: pemakaian sesi paket dari POS (J-16.3) dan pengembalian/penghangusan sisa sesi (sumber = baris
    // `MutasiSesi`).
    case PemakaianSesi = 'PemakaianSesi';
    case MutasiSesi = 'MutasiSesi';
    // F-05e: order produksi (J-05.6: Dr persediaan barang jadi, Cr persediaan bahan + overhead dibebankan) dan
    // pembatalannya (jurnal pembalik).
    case OrderProduksi = 'OrderProduksi';
    // F-05f: bahan terbuang (J-05.4: Dr Susut & Barang Rusak, Cr persediaan) dan pembatalannya.
    case BahanTerbuang = 'BahanTerbuang';
    // Grosir (F-12, §9.7): surat jalan (J-12.1: HPP + Piutang Belum Difakturkan / persediaan + penjualan + PPN
    // keluaran) dan pembatalannya (J-12.3).
    case SuratJalan = 'SuratJalan';
    // Grosir (F-12, §9.7): faktur penjualan (J-12.2, reklasifikasi Piutang Usaha) dan pembatalannya.
    case FakturPenjualan = 'FakturPenjualan';
    // Grosir bagian 2 (J-12.4): retur grosir & nota kredit, dan pembatalannya.
    case ReturGrosir = 'ReturGrosir';
    // F-08 BR-08.4 (J-08.1): pencairan dana non-tunai ke rekening toko, dan pembatalannya.
    case Pencairan = 'Pencairan';

    /** E8: hapus-buku piutang yang tidak akan tertagih (Dr beban piutang tak tertagih, Cr piutang usaha). */
    case HapusBukuPiutang = 'HapusBukuPiutang';

    /** E8: uang QRIS tanpa penjualan yang diakui sebagai pendapatan lain (Dr kas/bank, Cr Pendapatan Lain). */
    case TagihanQris = 'TagihanQris';

    // F-17 toko online bagian 2: uang muka pesanan online diterima (J-17.1) dan dikembalikan (J-17.2).
    case PesananOnline = 'PesananOnline';

    // FIN-10 (v3.38): perolehan & pelepasan aset tetap, penyusutan bulanan.
    case AsetTetap = 'AsetTetap';

    case PenyusutanAset = 'PenyusutanAset';

    // F-05i (v3.40): setoran hasil penjualan barang titipan ke penitip (Dr Hutang Konsinyasi, Cr kas/bank).
    case PembayaranKonsinyasi = 'PembayaranKonsinyasi';

    // v3.41 (INV-14): biaya tambahan pembelian pihak ketiga (Dr persediaan/HPP, Cr kas/bank) dan pembatalannya.
    case BiayaTambahanPembelian = 'BiayaTambahanPembelian';

    // v6.07 (F-04 BR-04.10, J-04.7): nota debit pembelian (Dr hutang/kas-bank, Cr persediaan/HPP/PPN masukan) dan pembatalannya.
    case NotaDebitPembelian = 'NotaDebitPembelian';

    // v3.42 (F-12): pencairan giro/cek mundur ke rekening bank (sumber = baris `Giro`).
    case Giro = 'Giro';

    // FIN-05 (D-131): jurnal umum manual oleh Akuntan/Pemilik (dan pembaliknya).
    case JurnalUmum = 'JurnalUmum';

    // FnB reservasi meja (D-145): uang muka (DP) diterima, dan penyelesaian sisanya (kembali/hangus).
    case ReservasiMeja = 'ReservasiMeja';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::StokAwal => 'Stok awal',
            self::MutasiKas => 'Kas masuk/keluar',
            self::Penjualan => 'Penjualan',
            self::ReturPenjualan => 'Retur penjualan',
            self::TutupShift => 'Selisih kas tutup shift',
            self::TransaksiKasBank => 'Transaksi kas & bank',
            self::TransferStok => 'Transfer stok',
            self::StokOpname => 'Stok opname',
            self::PenyesuaianStok => 'Penyesuaian stok',
            self::PenerimaanBarang => 'Penerimaan barang',
            self::FakturPembelian => 'Faktur pembelian',
            self::PembayaranHutang => 'Pembayaran hutang',
            self::ReturPembelian => 'Retur pembelian',
            self::PembayaranPiutang => 'Pelunasan piutang',
            self::PesananPenjualan => 'Uang muka pre-order',
            self::TutupTahun => 'Tutup tahun',
            self::Kasbon => 'Kasbon karyawan',
            self::PelunasanKasbon => 'Pelunasan kasbon',
            self::RekapGaji => 'Rekap gaji',
            self::PenerimaanKlaimPemasok => 'Penerimaan klaim promo pemasok',
            self::IsiDeposit => 'Isi deposit pelanggan',
            self::MutasiDeposit => 'Penarikan/penyesuaian deposit',
            self::MutasiGiftCard => 'Penjualan/hangus/pengembalian gift card',
            self::PemakaianSesi => 'Pemakaian paket sesi',
            self::MutasiSesi => 'Pengembalian/hangus paket sesi',
            self::OrderProduksi => 'Order produksi',
            self::BahanTerbuang => 'Bahan terbuang',
            self::SuratJalan => 'Surat jalan grosir',
            self::FakturPenjualan => 'Faktur penjualan grosir',
            self::ReturGrosir => 'Retur grosir',
            self::Pencairan => 'Pencairan dana',
            self::HapusBukuPiutang => 'Hapus-buku piutang',
            self::TagihanQris => 'Uang QRIS tanpa penjualan',
            self::PesananOnline => 'Uang muka pesanan online',
            self::AsetTetap => 'Aset tetap',
            self::PenyusutanAset => 'Penyusutan aset tetap',
            self::PembayaranKonsinyasi => 'Setoran konsinyasi',
            self::BiayaTambahanPembelian => 'Biaya tambahan pembelian',
            self::NotaDebitPembelian => 'Nota debit pembelian',
            self::Giro => 'Pencairan giro',
            self::JurnalUmum => 'Jurnal umum',
            self::ReservasiMeja => 'Uang muka reservasi meja',
        };
    }

    /** Tautan back-office ke dokumen sumber; null bila Uuid kosong atau halaman dokumennya belum ada. */
    public function BuatTautan(?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return match ($this) {
            self::StokAwal => '/kelola/persediaan/stok-awal/'.$uuid,
            self::MutasiKas => '/kelola/kasir/mutasi-kas/'.$uuid,
            self::Penjualan => '/kelola/penjualan/'.$uuid,
            self::ReturPenjualan => '/kelola/penjualan/retur/'.$uuid,
            self::TutupShift => '/kelola/kasir/shift/'.$uuid,
            self::TransaksiKasBank => '/kelola/akuntansi/kas-bank/'.$uuid,
            self::TransferStok => '/kelola/persediaan/transfer/'.$uuid,
            self::StokOpname => '/kelola/persediaan/opname/'.$uuid,
            self::PenyesuaianStok => '/kelola/persediaan/penyesuaian/'.$uuid,
            self::PenerimaanBarang => '/kelola/pembelian/penerimaan/'.$uuid,
            self::FakturPembelian => '/kelola/pembelian/faktur/'.$uuid,
            self::PembayaranHutang => '/kelola/pembelian/pembayaran/'.$uuid,
            self::ReturPembelian => '/kelola/pembelian/retur/'.$uuid,
            self::PembayaranPiutang => '/kelola/piutang/pelunasan/'.$uuid,
            self::PesananPenjualan => '/kelola/pre-order/'.$uuid,
            self::TutupTahun => null,
            self::Kasbon, self::PelunasanKasbon => '/kelola/karyawan/kasbon',
            self::RekapGaji => '/kelola/karyawan/gaji/'.$uuid,
            self::PenerimaanKlaimPemasok => '/kelola/promo/klaim-pemasok',
            self::IsiDeposit => '/kelola/pelanggan/isi-deposit/'.$uuid,
            self::MutasiDeposit => '/kelola/pelanggan/mutasi-deposit/'.$uuid,
            self::MutasiGiftCard => '/kelola/pelanggan/mutasi-gift-card/'.$uuid,
            self::OrderProduksi => '/kelola/persediaan/produksi/'.$uuid,
            self::BahanTerbuang => '/kelola/persediaan/bahan-terbuang?cari='.$uuid,
            self::PemakaianSesi => '/kelola/pelanggan/pemakaian-sesi/'.$uuid,
            self::MutasiSesi => '/kelola/pelanggan/mutasi-sesi/'.$uuid,
            self::SuratJalan => '/kelola/grosir/surat-jalan/'.$uuid,
            self::FakturPenjualan => '/kelola/grosir/faktur/'.$uuid,
            self::ReturGrosir => '/kelola/grosir/retur/'.$uuid,
            self::Pencairan => '/kelola/akuntansi/pencairan/'.$uuid,
            self::HapusBukuPiutang => '/kelola/piutang/hapus-buku?cari='.$uuid,
            self::TagihanQris => '/kelola/akuntansi/qris-tanpa-penjualan?cari='.$uuid,
            self::PesananOnline => '/kelola/toko-online?cari='.$uuid,
            // Penyusutan memakai Uuid asetnya sebagai `UuidSumber`, jadi keduanya menuju rincian aset.
            self::AsetTetap, self::PenyusutanAset => '/kelola/akuntansi/aset-tetap/'.$uuid,
            self::PembayaranKonsinyasi => '/kelola/pembelian/konsinyasi/setoran/'.$uuid,
            self::BiayaTambahanPembelian => '/kelola/pembelian/biaya-tambahan/'.$uuid,
            self::NotaDebitPembelian => '/kelola/pembelian/nota-debit/'.$uuid,
            self::Giro => '/kelola/akuntansi/giro?cari='.$uuid,
            self::JurnalUmum => '/kelola/akuntansi/jurnal-umum/'.$uuid,
            self::ReservasiMeja => '/kelola/reservasi-meja?cari='.$uuid,
        };
    }
}
