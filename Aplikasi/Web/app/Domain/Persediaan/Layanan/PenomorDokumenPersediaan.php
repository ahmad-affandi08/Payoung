<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use Carbon\CarbonInterface;

/**
 * Nomor dokumen persediaan F-05b berkode lokasi stok (§27 lampiran nomor dokumen): `TF/{ASAL}-{TUJUAN}/{YYMM}/{SEQ4}`,
 * `SO/{LOKASI}/{YYMM}/{SEQ3}`, `PS/{LOKASI}/{YYMM}/{SEQ4}`. Urut per tenant, jenis, dan periode bulan (tanpa celah,
 * `PenomorDokumen`, kunci L7) sehingga nomor unik walau kode lokasi berubah. Kode transfer dipotong 13 karakter per
 * lokasi supaya nomor muat di `MutasiStok.NomorReferensi`/`Jurnal.NomorSumber` (40 karakter). SEQ melebar bila lewat.
 */
final class PenomorDokumenPersediaan
{
    private const PANJANG_KODE_TRANSFER = 13;

    public function __construct(private readonly PenomorDokumen $penomor) {}

    public function AmbilTransfer(CarbonInterface $tanggal, string $kodeAsal, string $kodeTujuan): string
    {
        $kode = self::Rapikan($kodeAsal, self::PANJANG_KODE_TRANSFER).'-'.self::Rapikan($kodeTujuan, self::PANJANG_KODE_TRANSFER);

        return $this->Susun(JenisDokumenBernomor::TransferStok, $tanggal, $kode);
    }

    /** `MS/{TUJUAN}-{ASAL}/{YYMM}/{SEQ4}`: lokasi yang meminta lebih dulu, lalu lokasi yang diminta mengirim (D-105). */
    public function AmbilPermintaanStok(CarbonInterface $tanggal, string $kodeTujuan, string $kodeAsal): string
    {
        $kode = self::Rapikan($kodeTujuan, self::PANJANG_KODE_TRANSFER).'-'.self::Rapikan($kodeAsal, self::PANJANG_KODE_TRANSFER);

        return $this->Susun(JenisDokumenBernomor::PermintaanStok, $tanggal, $kode);
    }

    public function AmbilOpname(CarbonInterface $tanggal, string $kodeLokasi): string
    {
        return $this->Susun(JenisDokumenBernomor::StokOpname, $tanggal, self::Rapikan($kodeLokasi, 20));
    }

    public function AmbilPenyesuaian(CarbonInterface $tanggal, string $kodeLokasi): string
    {
        return $this->Susun(JenisDokumenBernomor::PenyesuaianStok, $tanggal, self::Rapikan($kodeLokasi, 20));
    }

    public function AmbilProduksi(CarbonInterface $tanggal, string $kodeLokasi): string
    {
        return $this->Susun(JenisDokumenBernomor::OrderProduksi, $tanggal, self::Rapikan($kodeLokasi, 20));
    }

    private function Susun(JenisDokumenBernomor $jenis, CarbonInterface $tanggal, string $kode): string
    {
        $urut = $this->penomor->AmbilBerikutnya($jenis, $tanggal->format('Y-m'));

        return sprintf('%s/%s/%s/%s', $jenis->AmbilAwalan(), $kode, $tanggal->format('ym'), str_pad((string) $urut, $jenis->AmbilPanjangUrut(), '0', STR_PAD_LEFT));
    }

    /** Kode lokasi huruf besar tanpa garis miring/spasi (pemisah nomor), dipotong `panjang` karakter. */
    private static function Rapikan(string $kode, int $panjang): string
    {
        $bersih = (string) preg_replace('/[\/\s]+/u', '', mb_strtoupper(trim($kode)));

        return mb_substr($bersih === '' ? 'LOKASI' : $bersih, 0, $panjang);
    }
}
