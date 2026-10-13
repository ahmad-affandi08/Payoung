<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Dokumen\Enum;

use InvalidArgumentException;

/**
 * Jenis dokumen yang dinomori `PenomorDokumen` (`NomorUrutDokumen.JenisDokumen`, DesainF05a B.4). Nomor berurutan
 * tanpa celah per tenant, jenis, dan periode `YYYY-MM`: `SA/2026/09/0001`, `JU/2026/09/000001`.
 */
enum JenisDokumenBernomor: string
{
    case StokAwal = 'StokAwal';
    case Jurnal = 'Jurnal';
    // F-13a: transaksi kas & bank back-office `KB/2026/09/0001`.
    case TransaksiKasBank = 'TransaksiKasBank';
    // F-05b: nomor berkode lokasi stok (`TF/{ASAL}-{TUJUAN}/{YYMM}/{SEQ4}`, `SO/{LOKASI}/{YYMM}/{SEQ3}`,
    // `PS/{LOKASI}/{YYMM}/{SEQ4}`) disusun `PenomorDokumenPersediaan`; urutnya per tenant, jenis, dan periode.
    case TransferStok = 'TransferStok';
    case StokOpname = 'StokOpname';
    case PenyesuaianStok = 'PenyesuaianStok';
    // F-04 fase 1: nomor pembelian disusun `PenomorPembelian` (`PO/{OUTLET}/{YYMM}/{SEQ4}`, `GR/…`, `RB/…` per outlet;
    // `FB/{YYMM}/{SEQ4}`, `BH/{YYMM}/{SEQ4}` per tenant); urutnya per tenant, jenis, periode (dan outlet).
    case PesananPembelian = 'PesananPembelian';
    case PenerimaanBarang = 'PenerimaanBarang';
    case FakturPembelian = 'FakturPembelian';
    case PembayaranHutang = 'PembayaranHutang';
    case ReturPembelian = 'ReturPembelian';
    // F-12: pelunasan piutang `BP/{YYMM}/{SEQ4}` per tenant.
    case PembayaranPiutang = 'PembayaranPiutang';
    // F-17: pesanan QR meja `QR/{OUTLET}/{YYMMDD}-{SEQ4}` disusun `BuatPesananSendiri`; urutnya per outlet per hari
    // (periode harian `YYYY-MM-DD`, `PenomorDokumen::AmbilBerikutnyaHarian`).
    case PesananSendiri = 'PesananSendiri';
    // F-17 toko online: `ON/2026/09/0001` per tenant.
    case PesananOnline = 'PesananOnline';
    // F-07 mode service: reservasi layanan `RS/{YYYY}/{MM}/{SEQ4}` per tenant.
    case Reservasi = 'Reservasi';
    // F-05e: order produksi `PR/{LOKASI}/{YYMM}/{SEQ4}` disusun `PenomorDokumenPersediaan`.
    case OrderProduksi = 'OrderProduksi';
    // Grosir (F-12, §9.7, D-32): sales order grosir `PG/{OUTLET}/{YYMM}/{SEQ4}` disusun `PenomorGrosir`.
    // Awalan `SO` tidak dipakai karena sudah milik `StokOpname`.
    case PesananGrosir = 'PesananGrosir';
    // Grosir (F-12, §9.7, BR-12.2): surat jalan `SJ/{OUTLET}/{YYMM}/{SEQ4}`, juga lewat `PenomorGrosir`.
    case SuratJalan = 'SuratJalan';
    // Grosir (F-12, §9.7, BR-12.4): faktur penjualan `FJ/{OUTLET}/{YYMM}/{SEQ4}`. Awalan `FB` sudah milik
    // `FakturPembelian`, jadi sisi jual memakai `FJ`.
    case FakturPenjualan = 'FakturPenjualan';
    // Grosir bagian 2 (F-12, §9.7, BR-12.7): retur grosir `RG/{OUTLET}/{YYMM}/{SEQ4}`. Awalan `RB` milik
    // `ReturPembelian`, jadi sisi jual memakai `RG`.
    case ReturGrosir = 'ReturGrosir';

    /** F-08 BR-08.4: pencairan dana non-tunai ke rekening toko (J-08.1). */
    case Pencairan = 'Pencairan';

    /** E8: hapus-buku piutang `HB/{YYYY}/{MM}/{SEQ4}` per tenant. */
    case HapusBukuPiutang = 'HapusBukuPiutang';

    /** FIN-10 (v3.38): aset tetap `AT-2610-0001`. */
    case AsetTetap = 'AsetTetap';

    /** F-05i (v3.40): titipan konsinyasi masuk/retur `KS/{OUTLET}/{YYMM}/{SEQ4}`, setoran ke penitip `BK/{YYMM}/{SEQ4}`. */
    case DokumenKonsinyasi = 'DokumenKonsinyasi';

    case PembayaranKonsinyasi = 'PembayaranKonsinyasi';

    /** v3.41 (INV-14): biaya tambahan pembelian `BY/{YYMM}/{SEQ4}`. */
    case BiayaTambahanPembelian = 'BiayaTambahanPembelian';

    /** Sektor Bengkel bagian 1 (§9.10): perintah kerja `WO/{OUTLET}/{YYMM}/{SEQ4}` per outlet. */
    case PerintahKerja = 'PerintahKerja';

    /** F-17 bagian 4: nomor antrian kios, urut harian per outlet (ditampilkan sebagai K001, bukan nomor dokumen). */
    case AntrianKios = 'AntrianKios';

    /** F-05b (D-105): permintaan stok antar lokasi `MS/{TUJUAN}-{ASAL}/{YYMM}/{SEQ4}` disusun `PenomorDokumenPersediaan`. */
    case PermintaanStok = 'PermintaanStok';

    /** FIN-05 (D-131): jurnal umum manual `JM/{YYYY}/{MM}/{SEQ4}`. Awalan `JU` sudah milik nomor `Jurnal` itu sendiri. */
    case JurnalUmum = 'JurnalUmum';

    /** F-17 (SLS-04): pesanan QR meja yang dibayar QRIS dari HP tamu (jalur `PesananOnline`, `Sumber` Meja) `ME/{OUTLET}/{YYMMDD}-{SEQ4}`. */
    case PesananMeja = 'PesananMeja';

    /** F-04 (PR): permintaan pembelian dari outlet `PP/{OUTLET}/{YYMM}/{SEQ4}`, sebelum menjadi pesanan pembelian (PO). */
    case PermintaanPembelian = 'PermintaanPembelian';

    public function AmbilAwalan(): string
    {
        return match ($this) {
            self::StokAwal => 'SA',
            self::Jurnal => 'JU',
            self::TransaksiKasBank => 'KB',
            self::TransferStok => 'TF',
            self::StokOpname => 'SO',
            self::PenyesuaianStok => 'PS',
            self::PesananPembelian => 'PO',
            self::PenerimaanBarang => 'GR',
            self::FakturPembelian => 'FB',
            self::PembayaranHutang => 'BH',
            self::ReturPembelian => 'RB',
            self::PembayaranPiutang => 'BP',
            self::PesananSendiri => 'QR',
            self::PesananOnline => 'ON',
            self::Reservasi => 'RS',
            self::OrderProduksi => 'PR',
            self::PesananGrosir => 'PG',
            self::SuratJalan => 'SJ',
            self::FakturPenjualan => 'FJ',
            self::ReturGrosir => 'RG',
            self::Pencairan => 'PC',
            self::HapusBukuPiutang => 'HB',
            self::AsetTetap => 'AT',
            self::DokumenKonsinyasi => 'KS',
            self::PembayaranKonsinyasi => 'BK',
            self::BiayaTambahanPembelian => 'BY',
            self::PerintahKerja => 'WO',
            self::AntrianKios => 'KI',
            self::PermintaanStok => 'MS',
            self::JurnalUmum => 'JM',
            self::PesananMeja => 'ME',
            self::PermintaanPembelian => 'PP',
        };
    }

    public function AmbilPanjangUrut(): int
    {
        return match ($this) {
            self::StokAwal, self::TransaksiKasBank, self::TransferStok, self::PenyesuaianStok, self::OrderProduksi, self::PermintaanStok, self::JurnalUmum => 4,
            self::StokOpname => 3,
            self::Jurnal => 6,
            self::PesananPembelian, self::PenerimaanBarang, self::FakturPembelian, self::PembayaranHutang, self::ReturPembelian, self::PembayaranPiutang, self::PesananSendiri, self::PesananOnline, self::Reservasi, self::PesananGrosir, self::SuratJalan, self::FakturPenjualan, self::ReturGrosir, self::Pencairan, self::HapusBukuPiutang, self::AsetTetap, self::DokumenKonsinyasi, self::PembayaranKonsinyasi, self::BiayaTambahanPembelian, self::PerintahKerja, self::PesananMeja, self::PermintaanPembelian => 4,
            self::AntrianKios => 3,
        };
    }

    /**
     * @param  string  $periode  `YYYY-MM`
     *
     * @throws InvalidArgumentException bila periode tidak berformat `YYYY-MM` atau urut < 1
     */
    public function FormatNomor(string $periode, int $urut): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $cocok) !== 1 || $urut < 1) {
            throw new InvalidArgumentException("Periode {$periode} atau nomor urut {$urut} tidak valid.");
        }

        return sprintf('%s/%s/%s/%s', $this->AmbilAwalan(), $cocok[1], $cocok[2], str_pad((string) $urut, $this->AmbilPanjangUrut(), '0', STR_PAD_LEFT));
    }
}
