<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Harga add-on yang dibeli di tengah periode berjalan (D-49). Murni: tanpa kueri, tanpa float.
 *
 * Harga penuh = harga bulanan × jumlah bulan periode (1 atau 12) × jumlah. Prorata = harga penuh × hari tersisa ÷ hari
 * periode, dibulatkan ke rupiah terdekat (HalfUp). Hari dihitung utuh dan dibulatkan ke atas, minimal 1 hari, paling
 * banyak seluruh periode. Dibeli tepat di awal periode (hari tersisa = hari periode) = harga penuh tanpa prorata.
 */
final class PenghitungProrataAddon
{
    /**
     * @return array{Subtotal: Uang, Prorata: bool, HariDitagih: int, HariPeriode: int}
     */
    public function Hitung(Uang $hargaBulanan, int $jumlahBulanPeriode, int $jumlah, CarbonInterface $sekarang, CarbonInterface $periodeMulai, CarbonInterface $periodeSelesai): array
    {
        if ($jumlahBulanPeriode < 1 || $jumlah < 1 || $hargaBulanan->BernilaiNegatif()) {
            throw new InvalidArgumentException('Harga add-on tidak boleh negatif. Jumlah bulan dan jumlah minimal 1.');
        }

        $hariPeriode = max(1, self::HariKeAtas($periodeMulai, $periodeSelesai));
        $hariSisa = $periodeSelesai->greaterThan($sekarang) ? self::HariKeAtas($sekarang, $periodeSelesai) : 0;
        $hariDitagih = max(1, min($hariPeriode, $hariSisa));
        $penuh = BigRational::of($hargaBulanan->KeString())->multipliedBy($jumlahBulanPeriode * $jumlah);
        $prorata = $hariDitagih < $hariPeriode;
        $nilai = $prorata ? $penuh->multipliedBy(BigRational::ofFraction($hariDitagih, $hariPeriode)) : $penuh;

        return [
            'Subtotal' => Uang::Dari(BigDecimal::of($nilai->toScale(0, RoundingMode::HalfUp))),
            'Prorata' => $prorata,
            'HariDitagih' => $hariDitagih,
            'HariPeriode' => $hariPeriode,
        ];
    }

    /**
     * Prorata untuk jumlah penuh satu periode yang sudah diketahui (D-98: selisih harga naik paket). Aturan hari dan
     * pembulatan sama dengan `Hitung()`.
     *
     * @return array{Subtotal: Uang, Prorata: bool, HariDitagih: int, HariPeriode: int}
     */
    public function HitungDariJumlahPenuh(Uang $penuh, CarbonInterface $sekarang, CarbonInterface $periodeMulai, CarbonInterface $periodeSelesai): array
    {
        if ($penuh->BernilaiNegatif()) {
            throw new InvalidArgumentException('Jumlah penuh tidak boleh negatif.');
        }

        $hariPeriode = max(1, self::HariKeAtas($periodeMulai, $periodeSelesai));
        $hariSisa = $periodeSelesai->greaterThan($sekarang) ? self::HariKeAtas($sekarang, $periodeSelesai) : 0;
        $hariDitagih = max(1, min($hariPeriode, $hariSisa));
        $prorata = $hariDitagih < $hariPeriode;
        $nilai = BigRational::of($penuh->KeString());

        if ($prorata) {
            $nilai = $nilai->multipliedBy(BigRational::ofFraction($hariDitagih, $hariPeriode));
        }

        return [
            'Subtotal' => Uang::Dari(BigDecimal::of($nilai->toScale(0, RoundingMode::HalfUp))),
            'Prorata' => $prorata,
            'HariDitagih' => $hariDitagih,
            'HariPeriode' => $hariPeriode,
        ];
    }

    /** Selisih hari utuh, dibulatkan ke atas (aritmetika bilangan bulat; detik pecahan diabaikan). */
    private static function HariKeAtas(CarbonInterface $dari, CarbonInterface $sampai): int
    {
        $detik = (int) floor($dari->diffInSeconds($sampai, true));

        return intdiv($detik + 86399, 86400);
    }
}
