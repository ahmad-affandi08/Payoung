<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Penjualan\Data\DataHasilPemeriksaanDiskon;
use App\Domain\Tenant\Data\DataPengaturanKasir;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * BR-07.3 diskon manual (F-07b; PRD v1.46 "Tindak lanjut tinjauan" (b) & (c)).
 *
 * Persen efektif = diskon hasil `MesinKalkulasi` (sudah dibulatkan ke sen) ÷ bruto baris (diskon baris) atau
 * ÷ subtotal (diskon pesanan) × 100, sama dengan aplikasi kasir. Perbandingan dengan batas dilakukan eksak
 * (diskon × 100 > batas × dasar), tanpa pembulatan hasil bagi. Aturan:
 * - Pemilik (kasir atau penyetuju) tanpa batas.
 * - Kasir ber-izin `penjualan.diskon.manual` boleh sampai `BatasDiskonManual` tanpa penyetuju.
 * - Kasir ber-izin `penjualan.diskon.setujui` boleh menyetujui sendiri sampai `BatasDiskonPenyetuju`.
 * - Selain itu wajib penyetuju ber-izin `penjualan.diskon.setujui` sampai `BatasDiskonPenyetuju`.
 *
 * Penjualan offline sudah terjadi dan uangnya sudah diterima, jadi pelanggaran batas (termasuk tanpa penyetuju, atau
 * kasir yang izin diskonnya dicabut) **tidak menolak**: menjadi alasan tinjauan `DiskonMelebihiBatas`. Penyetuju yang
 * tidak lagi punya akses ke outlet = `IzinBerubah`. Yang tetap ditolak (`PenyetujuTidakBerwenang`) hanya data yang
 * tidak mungkin sah: `UuidPenyetujuDiskon` bukan anggota tenant atau tanpa izin `penjualan.diskon.setujui` sama sekali.
 */
final class PemeriksaDiskonPenjualan
{
    public function __construct(private readonly AnggotaOutlet $anggota) {}

    /**
     * @param  list<array{0: Uang, 1: Uang}>  $diskon  pasangan [dasar (bruto/subtotal), diskon manual hasil mesin]
     */
    public function Periksa(array $diskon, DataAnggotaOutlet $kasir, ?string $uuidPenyetuju, int $idTenant, int $idOutlet, DataPengaturanKasir $pengaturan): DataHasilPemeriksaanDiskon
    {
        $tinjauan = [];
        $penyetuju = null;

        if ($uuidPenyetuju !== null) {
            [$penyetuju, $aksesOutlet] = $this->CariPenyetuju($uuidPenyetuju, $idTenant, $idOutlet);

            if (! $aksesOutlet) {
                $tinjauan['IzinBerubah'] = "IzinBerubah: penyetuju diskon {$penyetuju->nama} tidak lagi terdaftar di outlet ini";
            }
        }

        $diskon = array_values(array_filter($diskon, fn (array $d): bool => ! $d[0]->BernilaiNol() && ! $d[1]->BernilaiNol()));

        if ($diskon === [] || $kasir->pemilik) {
            return new DataHasilPemeriksaanDiskon($penyetuju, $tinjauan);
        }

        $izinManual = $kasir->CekIzin(IzinTenant::PenjualanDiskonManual->value);
        $izinSetujui = $kasir->CekIzin(IzinTenant::PenjualanDiskonSetujui->value);
        $bolehSendiri = ($izinManual && ! self::CekMelebihi($diskon, $pengaturan->batasDiskonManual))
            || ($izinSetujui && ! self::CekMelebihi($diskon, $pengaturan->batasDiskonPenyetuju));

        if ($bolehSendiri || ($penyetuju !== null && ($penyetuju->pemilik || ! self::CekMelebihi($diskon, $pengaturan->batasDiskonPenyetuju)))) {
            return new DataHasilPemeriksaanDiskon($penyetuju, $tinjauan);
        }

        $terbesar = self::HitungPersenTerbesar($diskon);
        $batasKasir = self::FormatPersen($pengaturan->batasDiskonManual);
        $batasPenyetuju = self::FormatPersen($pengaturan->batasDiskonPenyetuju);
        $persen = self::FormatPersenMelebihi($terbesar, $penyetuju !== null || $izinSetujui ? $pengaturan->batasDiskonPenyetuju : $pengaturan->batasDiskonManual);

        $tinjauan['DiskonMelebihiBatas'] = match (true) {
            $penyetuju !== null => "DiskonMelebihiBatas: diskon {$persen} melebihi batas persetujuan {$batasPenyetuju} ({$penyetuju->nama}). Hanya Pemilik yang boleh menyetujui",
            ! $izinManual && ! self::CekMelebihi($diskon, $pengaturan->batasDiskonManual) => "DiskonMelebihiBatas: diskon {$persen} diberikan tanpa persetujuan, padahal {$kasir->nama} tidak punya izin diskon manual",
            default => "DiskonMelebihiBatas: diskon {$persen} melebihi batas kasir {$batasKasir} tanpa persetujuan supervisor",
        };

        return new DataHasilPemeriksaanDiskon($penyetuju, $tinjauan);
    }

    /**
     * Persen efektif satu diskon (skala 10, dipotong). Dipakai untuk tampilan; keputusan batas memakai `CekMelebihi`
     * yang eksak.
     */
    public static function HitungPersenEfektif(Uang $dasar, Uang $diskon): BigDecimal
    {
        if ($dasar->BernilaiNol()) {
            return BigDecimal::zero();
        }

        return BigDecimal::of($diskon->KeString())->multipliedBy(100)->dividedBy($dasar->KeString(), 10, RoundingMode::Down);
    }

    /**
     * Ada diskon yang persen efektifnya di atas batas: diskon × 100 > batas × dasar (eksak).
     *
     * @param  list<array{0: Uang, 1: Uang}>  $diskon
     */
    private static function CekMelebihi(array $diskon, BigDecimal $batas): bool
    {
        foreach ($diskon as [$dasar, $jumlah]) {
            if (BigDecimal::of($jumlah->KeString())->multipliedBy(100)->isGreaterThan($batas->multipliedBy($dasar->KeString()))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: Uang, 1: Uang}>  $diskon
     */
    private static function HitungPersenTerbesar(array $diskon): BigDecimal
    {
        $maks = BigDecimal::zero();

        foreach ($diskon as [$dasar, $jumlah]) {
            $persen = self::HitungPersenEfektif($dasar, $jumlah);
            $maks = $persen->isGreaterThan($maks) ? $persen : $maks;
        }

        return $maks;
    }

    /**
     * Persen tampilan, maks. 4 desimal (dibulatkan setengah ke atas), koma desimal Indonesia.
     *
     * @param  non-negative-int  $skala
     */
    private static function FormatPersen(BigDecimal $persen, int $skala = 4, RoundingMode $mode = RoundingMode::HalfUp): string
    {
        $teks = (string) $persen->toScale($skala, $mode)->strippedOfTrailingZeros();

        return str_replace('.', ',', $teks).'%';
    }

    /** Persen yang melebihi batas tidak boleh tampil sama dengan batasnya (misal 30,0000009% → 30,000001%). */
    private static function FormatPersenMelebihi(BigDecimal $persen, BigDecimal $batas): string
    {
        return $persen->toScale(4, RoundingMode::HalfUp)->isGreaterThan($batas)
            ? self::FormatPersen($persen)
            : self::FormatPersen($persen, 6, RoundingMode::Up);
    }

    /**
     * @return array{0: DataAnggotaOutlet, 1: bool} [penyetuju, masih punya akses outlet]
     */
    private function CariPenyetuju(string $uuid, int $idTenant, int $idOutlet): array
    {
        $hasil = $this->anggota->CariDiTenant($idTenant, $uuid, $idOutlet);

        if ($hasil === null || ! $hasil[0]->CekIzin(IzinTenant::PenjualanDiskonSetujui->value)) {
            throw new PelanggaranAturanBisnis('PenyetujuTidakBerwenang', 'Penyetuju diskon bukan anggota usaha ini atau tidak punya izin menyetujui diskon.', 'UuidPenyetujuDiskon', 403);
        }

        return $hasil;
    }
}
