<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Enum;

/**
 * Jenis dokumen sumber sebuah `MutasiStok` (`MutasiStok.JenisReferensi`, DesainF05a B.4). Tautan ke dokumen sumber
 * dibangun dari Uuid tanpa kueri lintas domain; hanya dokumen yang halamannya sudah ada yang punya tautan.
 */
enum JenisReferensiMutasi: string
{
    case StokAwal = 'StokAwal';
    case PenerimaanBarang = 'PenerimaanBarang';
    case ReturPembelian = 'ReturPembelian';
    case Penjualan = 'Penjualan';
    case ReturPenjualan = 'ReturPenjualan';
    case VoidPenjualan = 'VoidPenjualan';
    case TransferStok = 'TransferStok';
    case StokOpname = 'StokOpname';
    case PenyesuaianStok = 'PenyesuaianStok';
    case Produksi = 'Produksi';
    case BahanTerbuang = 'BahanTerbuang';
    case Konsinyasi = 'Konsinyasi';

    // Grosir (F-12, §9.7, BR-12.2): penyerahan barang grosir lewat surat jalan; mutasinya `JenisMutasi::Penjualan`
    // karena efek stoknya memang penjualan, yang membedakan dokumennya adalah jenis referensi ini.
    case SuratJalan = 'SuratJalan';
    // Grosir bagian 2 (BR-12.7): barang kembali dari pembeli grosir; mutasinya `JenisMutasi::ReturPenjualan` karena
    // efek stoknya memang retur penjualan.
    case ReturGrosir = 'ReturGrosir';
    // v3.41 (INV-14): biaya tambahan pembelian dari pihak ketiga (ekspedisi, bea masuk) yang menilai ulang stok.
    case BiayaTambahanPembelian = 'BiayaTambahanPembelian';
    // v6.07 (F-04 BR-04.10): nota debit pembelian menurunkan nilai stok yang masih ada lewat penilaian ulang.
    case NotaDebitPembelian = 'NotaDebitPembelian';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::StokAwal => 'Stok awal',
            self::PenerimaanBarang => 'Penerimaan barang',
            self::ReturPembelian => 'Retur pembelian',
            self::Penjualan => 'Penjualan',
            self::ReturPenjualan => 'Retur penjualan',
            self::VoidPenjualan => 'Void penjualan',
            self::TransferStok => 'Transfer stok',
            self::StokOpname => 'Stok opname',
            self::PenyesuaianStok => 'Penyesuaian stok',
            self::Produksi => 'Produksi',
            self::BahanTerbuang => 'Bahan terbuang',
            self::Konsinyasi => 'Konsinyasi',
            self::SuratJalan => 'Surat jalan grosir',
            self::ReturGrosir => 'Retur grosir',
            self::BiayaTambahanPembelian => 'Biaya tambahan pembelian',
            self::NotaDebitPembelian => 'Nota debit pembelian',
        };
    }

    /** Tautan back-office ke dokumen sumber; null bila halaman dokumennya belum ada atau Uuid kosong. */
    public function BuatTautan(?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return match ($this) {
            self::StokAwal => '/kelola/persediaan/stok-awal/'.$uuid,
            self::Penjualan => '/kelola/penjualan/'.$uuid,
            // F-09: mutasi void memakai Uuid penjualan yang dibatalkan (void 1:1 dengan penjualan).
            self::VoidPenjualan => '/kelola/penjualan/'.$uuid,
            self::ReturPenjualan => '/kelola/penjualan/retur/'.$uuid,
            // F-05b.
            self::TransferStok => '/kelola/persediaan/transfer/'.$uuid,
            self::StokOpname => '/kelola/persediaan/opname/'.$uuid,
            self::PenyesuaianStok => '/kelola/persediaan/penyesuaian/'.$uuid,
            // F-04 fase 1.
            self::PenerimaanBarang => '/kelola/pembelian/penerimaan/'.$uuid,
            self::ReturPembelian => '/kelola/pembelian/retur/'.$uuid,
            // F-05e.
            self::Produksi => '/kelola/persediaan/produksi/'.$uuid,
            self::SuratJalan => '/kelola/grosir/surat-jalan/'.$uuid,
            self::ReturGrosir => '/kelola/grosir/retur/'.$uuid,
            self::BahanTerbuang => '/kelola/persediaan/bahan-terbuang?cari='.$uuid,
            // F-05i (v3.40) & v3.41.
            self::Konsinyasi => '/kelola/pembelian/konsinyasi/dokumen/'.$uuid,
            self::BiayaTambahanPembelian => '/kelola/pembelian/biaya-tambahan/'.$uuid,
            self::NotaDebitPembelian => '/kelola/pembelian/nota-debit/'.$uuid,
        };
    }
}
