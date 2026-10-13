<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Layanan;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Prediksi penjualan (§10.10 "Insight: prediksi penjualan"), fungsi murni tanpa float. Metodenya sengaja sederhana dan
 * bisa dijelaskan ke pemilik usaha: **rata-rata hari-dalam-minggu** dari `MINGGU_DASAR` minggu terakhir (sampai
 * kemarin; hari tutup tanpa penjualan dihitung nol), dikali **faktor tren** = penjualan 4 minggu terakhir ÷ 4 minggu
 * sebelumnya, dibatasi `TREN_MIN`..`TREN_MAKS` (1,00 bila belum ada 8 minggu data). Butuh riwayat minimal
 * `MIN_HARI_DATA` hari sejak penjualan pertama; kurang dari itu hasilnya `Cukup = false` tanpa angka.
 */
final class PeramalPenjualan
{
    public const MINGGU_DASAR = 8;

    public const MIN_HARI_DATA = 14;

    public const TREN_MIN = '0.80';

    public const TREN_MAKS = '1.25';

    /**
     * @param  array<string, array{Bersih: string, JumlahTransaksi: int}>  $seri  penjualan harian (tanggal bisnis `Y-m-d`); tanggal tanpa baris = tidak ada penjualan
     * @return array{Cukup: bool, HariData: int, FaktorTren: string, Baris: list<array{Tanggal: string, Hari: int, Perkiraan: string, PerkiraanTransaksi: int, Terendah: string, Tertinggi: string, Sampel: int}>, TotalPerkiraan: string}
     */
    public function Ramal(array $seri, CarbonImmutable $hariIni, int $horizon = 14): array
    {
        // Tanggal kalender murni (tanpa zona waktu): selisih hari tidak boleh tergelincir oleh jam atau zona outlet.
        $hariIni = CarbonImmutable::parse($hariIni->toDateString());
        $jendelaMulai = $hariIni->subDays(self::MINGGU_DASAR * 7);
        $jendelaAkhir = $hariIni->subDay();
        $pertama = null;

        foreach (array_keys($seri) as $tanggal) {
            if ($tanggal >= $jendelaMulai->toDateString() && $tanggal <= $jendelaAkhir->toDateString() && ($pertama === null || $tanggal < $pertama)) {
                $pertama = $tanggal;
            }
        }

        $hariData = $pertama === null ? 0 : (int) CarbonImmutable::parse($pertama)->diffInDays($hariIni);
        $kosong = ['Cukup' => false, 'HariData' => $hariData, 'FaktorTren' => '1.00', 'Baris' => [], 'TotalPerkiraan' => '0.00'];

        if ($pertama === null || $hariData < self::MIN_HARI_DATA) {
            return $kosong;
        }

        $awalData = CarbonImmutable::parse($pertama);
        $perHari = [];
        $terbaru = BigDecimal::zero();
        $sebelumnya = BigDecimal::zero();

        for ($d = $awalData; $d->lessThanOrEqualTo($jendelaAkhir); $d = $d->addDay()) {
            $tanggal = $d->toDateString();
            $bersih = BigDecimal::of($seri[$tanggal]['Bersih'] ?? '0');
            $transaksi = (int) ($seri[$tanggal]['JumlahTransaksi'] ?? 0);
            $perHari[$d->dayOfWeekIso][] = [$bersih, $transaksi];
            $selisih = (int) $d->diffInDays($hariIni);

            if ($selisih <= 28) {
                $terbaru = $terbaru->plus($bersih);
            } elseif ($selisih <= 56) {
                $sebelumnya = $sebelumnya->plus($bersih);
            }
        }

        $faktor = BigDecimal::one();

        if ($awalData->lessThanOrEqualTo($hariIni->subDays(56)) && $sebelumnya->isPositive()) {
            $faktor = $terbaru->dividedBy($sebelumnya, 4, RoundingMode::HalfUp);
            $faktor = $faktor->isLessThan(self::TREN_MIN) ? BigDecimal::of(self::TREN_MIN) : ($faktor->isGreaterThan(self::TREN_MAKS) ? BigDecimal::of(self::TREN_MAKS) : $faktor);
        }

        $baris = [];
        $total = BigDecimal::zero();

        for ($i = 0; $i < $horizon; $i++) {
            $hari = $hariIni->addDays($i);
            $sampel = $perHari[$hari->dayOfWeekIso] ?? [];

            if ($sampel === []) {
                continue;
            }

            $jumlah = count($sampel);
            $rata = array_reduce($sampel, fn (BigDecimal $t, array $s): BigDecimal => $t->plus($s[0]), BigDecimal::zero())->dividedBy($jumlah, 6, RoundingMode::HalfUp);
            $rataTransaksi = BigDecimal::of(array_sum(array_column($sampel, 1)))->dividedBy($jumlah, 6, RoundingMode::HalfUp);
            $nilai = $rata->multipliedBy($faktor)->toScale(2, RoundingMode::HalfUp);
            $total = $total->plus($nilai);
            $bersihSampel = array_map(fn (array $s): BigDecimal => $s[0], $sampel);
            $baris[] = [
                'Tanggal' => $hari->toDateString(),
                'Hari' => $hari->dayOfWeekIso,
                'Perkiraan' => (string) $nilai,
                'PerkiraanTransaksi' => $rataTransaksi->multipliedBy($faktor)->toScale(0, RoundingMode::HalfUp)->toInt(),
                'Terendah' => (string) self::Ekstrem($bersihSampel, false)->toScale(2, RoundingMode::HalfUp),
                'Tertinggi' => (string) self::Ekstrem($bersihSampel, true)->toScale(2, RoundingMode::HalfUp),
                'Sampel' => $jumlah,
            ];
        }

        return ['Cukup' => true, 'HariData' => $hariData, 'FaktorTren' => (string) $faktor->toScale(2, RoundingMode::HalfUp), 'Baris' => $baris, 'TotalPerkiraan' => (string) $total->toScale(2, RoundingMode::HalfUp)];
    }

    /**
     * @param  list<BigDecimal>  $nilai
     */
    private static function Ekstrem(array $nilai, bool $terbesar): BigDecimal
    {
        $hasil = $nilai[0];

        foreach ($nilai as $n) {
            if ($terbesar ? $n->isGreaterThan($hasil) : $n->isLessThan($hasil)) {
                $hasil = $n;
            }
        }

        return $hasil;
    }
}
