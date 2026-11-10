<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Layanan;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\ArusKas;
use App\Domain\Akuntansi\Model\Akun;

/**
 * Menggolongkan perubahan uang di akun kas & bank ke bahasa pemilik usaha (laporan Pemasukan & Pengeluaran, D-255).
 * Murni, tanpa kueri: pemanggil memberi jenis sumber jurnal atau akun lawan. Hasilnya `Label` (baris yang dilihat
 * pemilik), `Kelompok` (pengelompokan di ringkasan), dan `DiLuar` (true = bukan pendapatan atau biaya usaha, seperti
 * modal, prive, pinjaman, dan beli aset, jadi tidak masuk hitungan selisih).
 */
final class PenggolongUangKas
{
    public const MASUK_PENJUALAN = 'Penjualan';

    public const MASUK_PIUTANG = 'Piutang dan uang muka';

    public const MASUK_LAINNYA = 'Lainnya';

    /** Belanja bahan baku (FnB, produksi): dipisah dari dagangan supaya pemilik tahu berapa yang habis untuk bahan. */
    public const KELUAR_BELANJA_BAHAN = 'Belanja bahan baku';

    public const KELUAR_BELANJA = 'Belanja barang dagangan';

    /**
     * D-256: uang laci dipakai belanja stok yang dicatat kasir di POS. Pada saat kas keluar belum diketahui isinya bahan
     * baku atau dagangan, jadi tampil terpisah. Barangnya dicatat kemudian lewat Belanja stok (tanpa kas bergerak).
     */
    public const KELUAR_BELANJA_LACI = 'Belanja stok dari uang laci';

    public const KELUAR_GAJI = 'Gaji dan tenaga kerja';

    public const KELUAR_OPERASIONAL = 'Biaya operasional';

    public const KELUAR_PAJAK = 'Pajak';

    public const KELUAR_PLATFORM = 'Potongan platform dan pembayaran';

    public const KELUAR_PELANGGAN = 'Pengembalian ke pelanggan';

    public const KELUAR_LAIN = 'Lain-lain';

    public const DI_LUAR_MODAL = 'Modal, prive, dan pinjaman';

    public const DI_LUAR_ASET = 'Beli atau jual aset tetap';

    public const DI_LUAR_PINDAH = 'Pindah uang antar akun';

    public const BELUM_DIKELOMPOKKAN = 'Belum dikelompokkan';

    /** Urutan tampil kelompok pemasukan. */
    public const URUT_MASUK = [self::MASUK_PENJUALAN, self::MASUK_PIUTANG, self::MASUK_LAINNYA];

    /** Urutan tampil kelompok pengeluaran. */
    public const URUT_KELUAR = [self::KELUAR_BELANJA_BAHAN, self::KELUAR_BELANJA, self::KELUAR_BELANJA_LACI, self::KELUAR_GAJI, self::KELUAR_OPERASIONAL, self::KELUAR_PAJAK, self::KELUAR_PLATFORM, self::KELUAR_PELANGGAN, self::KELUAR_LAIN, self::BELUM_DIKELOMPOKKAN];

    /**
     * Penggolongan menurut jenis dokumen sumber jurnal. `$mentah` dipakai bila jenisnya tidak dikenal.
     *
     * @return array{Label: string, Kelompok: string, DiLuar: bool}
     */
    public static function DariSumber(?JenisSumberJurnal $jenis, bool $masuk, string $mentah = ''): array
    {
        if ($jenis === null) {
            return self::Hasil($mentah === '' ? 'Transaksi tidak dikenal' : $mentah, self::BELUM_DIKELOMPOKKAN);
        }

        $pasangan = match ($jenis) {
            JenisSumberJurnal::Penjualan => $masuk
                ? ['Penjualan', self::MASUK_PENJUALAN]
                : ['Penjualan dibatalkan (void)', self::KELUAR_PELANGGAN],
            JenisSumberJurnal::ReturPenjualan => $masuk
                ? ['Retur penjualan dibatalkan', self::MASUK_LAINNYA]
                : ['Retur dan refund ke pelanggan', self::KELUAR_PELANGGAN],
            JenisSumberJurnal::Pencairan => $masuk
                ? ['Penjualan non-tunai yang sudah cair', self::MASUK_PENJUALAN]
                : ['Potongan platform dan biaya pembayaran', self::KELUAR_PLATFORM],
            JenisSumberJurnal::PembayaranPiutang => $masuk
                ? ['Pelunasan piutang', self::MASUK_PIUTANG]
                : ['Pelunasan piutang dibatalkan', self::KELUAR_LAIN],
            JenisSumberJurnal::PesananPenjualan, JenisSumberJurnal::PesananOnline, JenisSumberJurnal::ReservasiMeja => $masuk
                ? ['Uang muka pesanan dan reservasi', self::MASUK_PIUTANG]
                : ['Uang muka dikembalikan', self::KELUAR_PELANGGAN],
            JenisSumberJurnal::Invoice => $masuk
                ? ['Pembayaran invoice', self::MASUK_PIUTANG]
                : ['Pembayaran invoice dibatalkan', self::KELUAR_LAIN],
            JenisSumberJurnal::IsiDeposit, JenisSumberJurnal::MutasiDeposit, JenisSumberJurnal::MutasiGiftCard, JenisSumberJurnal::MutasiSesi, JenisSumberJurnal::PemakaianSesi => $masuk
                ? ['Deposit, gift card, dan paket sesi', self::MASUK_LAINNYA]
                : ['Penarikan deposit dan gift card', self::KELUAR_PELANGGAN],
            JenisSumberJurnal::PenerimaanBarang => $masuk
                ? ['Belanja stok dibatalkan', self::MASUK_LAINNYA]
                : ['Belanja stok tunai', self::KELUAR_BELANJA],
            JenisSumberJurnal::PembayaranHutang => $masuk
                ? ['Pembayaran hutang dibatalkan', self::MASUK_LAINNYA]
                : ['Pembayaran hutang ke pemasok', self::KELUAR_BELANJA],
            JenisSumberJurnal::FakturPembelian => $masuk
                ? ['Faktur pembelian dibatalkan', self::MASUK_LAINNYA]
                : ['Faktur pembelian dibayar', self::KELUAR_BELANJA],
            JenisSumberJurnal::BiayaTambahanPembelian => $masuk
                ? ['Biaya tambahan pembelian dibatalkan', self::MASUK_LAINNYA]
                : ['Biaya tambahan pembelian', self::KELUAR_BELANJA],
            JenisSumberJurnal::PembayaranKonsinyasi => $masuk
                ? ['Setoran konsinyasi dibatalkan', self::MASUK_LAINNYA]
                : ['Setoran hasil barang titipan', self::KELUAR_BELANJA],
            JenisSumberJurnal::Giro => $masuk
                ? ['Giro dan cek mundur cair', self::MASUK_PIUTANG]
                : ['Giro dan cek mundur keluar', self::KELUAR_BELANJA],
            JenisSumberJurnal::ReturPembelian, JenisSumberJurnal::NotaDebitPembelian => $masuk
                ? ['Uang kembali dari pemasok', self::MASUK_LAINNYA]
                : ['Retur pembelian dibatalkan', self::KELUAR_LAIN],
            JenisSumberJurnal::PenerimaanKlaimPemasok => $masuk
                ? ['Klaim promo dari pemasok', self::MASUK_LAINNYA]
                : ['Klaim promo dibatalkan', self::KELUAR_LAIN],
            JenisSumberJurnal::RekapGaji => $masuk
                ? ['Pembayaran gaji dibatalkan', self::MASUK_LAINNYA]
                : ['Gaji dan komisi', self::KELUAR_GAJI],
            JenisSumberJurnal::Kasbon => $masuk
                ? ['Kasbon dibatalkan', self::MASUK_LAINNYA]
                : ['Kasbon karyawan', self::KELUAR_GAJI],
            JenisSumberJurnal::PelunasanKasbon => $masuk
                ? ['Pelunasan kasbon', self::MASUK_LAINNYA]
                : ['Pelunasan kasbon dibatalkan', self::KELUAR_LAIN],
            JenisSumberJurnal::TutupShift => $masuk
                ? ['Kas lebih saat tutup shift', self::MASUK_LAINNYA]
                : ['Kas kurang saat tutup shift', self::KELUAR_LAIN],
            JenisSumberJurnal::TagihanQris => $masuk
                ? ['Uang QRIS tanpa penjualan', self::MASUK_LAINNYA]
                : ['Uang QRIS dikembalikan', self::KELUAR_PELANGGAN],
            JenisSumberJurnal::AsetTetap => [$masuk ? 'Penjualan aset tetap' : 'Pembelian aset tetap', self::DI_LUAR_ASET],
            JenisSumberJurnal::JurnalUmum => [$masuk ? 'Jurnal umum manual (masuk)' : 'Jurnal umum manual (keluar)', $masuk ? self::MASUK_LAINNYA : self::KELUAR_LAIN],
            JenisSumberJurnal::TransaksiKasBank => [$masuk ? 'Penerimaan kas dan bank' : 'Pengeluaran kas dan bank', $masuk ? self::MASUK_LAINNYA : self::KELUAR_LAIN],
            JenisSumberJurnal::MutasiKas => [$masuk ? 'Kas masuk kasir' : 'Kas keluar kasir', $masuk ? self::MASUK_LAINNYA : self::KELUAR_LAIN],
            default => [$jenis->AmbilLabel(), $masuk ? self::MASUK_LAINNYA : self::KELUAR_LAIN],
        };

        return self::Hasil($pasangan[0], $pasangan[1]);
    }

    /**
     * Penggolongan menurut akun lawan satu-satunya pada jurnal kas & bank. `$pajak` true bila akun itu akun pajak
     * (PPN keluaran, PPN masukan, hutang PB1).
     *
     * @return array{Label: string, Kelompok: string, DiLuar: bool}
     */
    public static function DariAkun(Akun $akun, bool $masuk, bool $pajak): array
    {
        $aktivitas = ArusKas::KlasifikasiAkun($akun);

        if ($aktivitas === 'Pendanaan') {
            return self::Hasil($akun->Nama, self::DI_LUAR_MODAL);
        }

        if ($aktivitas === 'Investasi') {
            return self::Hasil($akun->Nama, self::DI_LUAR_ASET);
        }

        if ($pajak) {
            return self::Hasil($akun->Nama, $masuk ? self::MASUK_LAINNYA : self::KELUAR_PAJAK);
        }

        return match ($akun->Jenis) {
            TipeAkun::Beban => $masuk
                ? self::Hasil($akun->Nama.' (dikembalikan)', self::MASUK_LAINNYA)
                : self::Hasil($akun->Nama, str_starts_with($akun->Kode, '6-1') ? self::KELUAR_GAJI : self::KELUAR_OPERASIONAL),
            TipeAkun::Hpp => $masuk
                ? self::Hasil($akun->Nama, self::MASUK_LAINNYA)
                : self::Hasil($akun->Nama, self::KELUAR_BELANJA),
            default => self::Hasil($akun->Nama, $masuk ? self::MASUK_LAINNYA : self::KELUAR_LAIN),
        };
    }

    /**
     * Kas keluar kasir ke akun penampung uang laci (D-256), atau uang kembalian yang dikembalikan ke laci.
     *
     * @return array{Label: string, Kelompok: string, DiLuar: bool}
     */
    public static function DariUangLaci(bool $masuk): array
    {
        return $masuk
            ? self::Hasil('Sisa uang belanja stok kembali ke laci', self::MASUK_LAINNYA)
            : self::Hasil('Uang laci dipakai belanja stok (barangnya belum dicatat)', self::KELUAR_BELANJA_LACI);
    }

    /**
     * Bagian kas keluar kasir ke penampung uang laci yang barangnya sudah dicatat lewat Belanja stok (D-257).
     *
     * @return array{Label: string, Kelompok: string, DiLuar: bool}
     */
    public static function DariUangLaciTerpakai(bool $bahanBaku): array
    {
        return $bahanBaku
            ? self::Hasil('Uang laci dipakai belanja bahan baku', self::KELUAR_BELANJA_BAHAN)
            : self::Hasil('Uang laci dipakai belanja barang dagangan', self::KELUAR_BELANJA);
    }

    /**
     * @return array{Label: string, Kelompok: string, DiLuar: bool}
     */
    public static function Hasil(string $label, string $kelompok): array
    {
        return [
            'Label' => $label,
            'Kelompok' => $kelompok,
            'DiLuar' => in_array($kelompok, [self::DI_LUAR_MODAL, self::DI_LUAR_ASET, self::DI_LUAR_PINDAH], true),
        ];
    }
}
