<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Pencocokan wajah absensi web (F-18 bagian 4, D-37). Model wajah berjalan di browser karyawan dan mengirim sidik
 * wajah = deskriptor × 10.000 dibulatkan (bilangan bulat). Keputusan cocok-tidaknya dibuat di sini, di server:
 * kemiripan kosinus terhadap rata-rata sidik terdaftar, lalu dibandingkan dengan
 * `config('karyawan.AmbangKemiripanWajah')` (bawaan 0,70 berdasarkan pengukuran, lihat `Panduan/KalibrasiWajah.md`). Hitungan bilangan bulat & desimal, tanpa pecahan biner.
 */
final class PencocokWajah
{
    public const PANJANG_MINIMAL = 128;

    public const PANJANG_MAKSIMAL = 1024;

    private const NILAI_MAKSIMAL = 100000;

    /**
     * Memastikan isian dari browser berbentuk sidik wajah yang sah sebelum disimpan/dicocokkan.
     *
     * @return list<int>
     */
    public function Validasi(mixed $sidik, string $bidang = 'SidikWajah'): array
    {
        if (! is_array($sidik) || ! array_is_list($sidik) || count($sidik) < self::PANJANG_MINIMAL || count($sidik) > self::PANJANG_MAKSIMAL) {
            throw new PelanggaranAturanBisnis('SidikWajahTidakValid', 'Data wajah tidak terbaca. Ulangi pemindaian wajah.', $bidang);
        }

        $hasil = [];
        $nol = true;

        foreach ($sidik as $nilai) {
            if (! is_int($nilai) || abs($nilai) > self::NILAI_MAKSIMAL) {
                throw new PelanggaranAturanBisnis('SidikWajahTidakValid', 'Data wajah tidak terbaca. Ulangi pemindaian wajah.', $bidang);
            }

            $nol = $nol && $nilai === 0;
            $hasil[] = $nilai;
        }

        if ($nol) {
            throw new PelanggaranAturanBisnis('SidikWajahTidakValid', 'Data wajah tidak terbaca. Ulangi pemindaian wajah.', $bidang);
        }

        return $hasil;
    }

    /**
     * Kemiripan kosinus (0–1, 4 desimal) antara sidik saat absen dan **rata-rata** sidik-sidik terdaftar (D-188). Rata-rata
     * (jumlah vektor; panjangnya tak memengaruhi kosinus) lebih stabil daripada mengambil yang tertinggi dari tiap foto
     * pendaftaran: satu foto pendaftaran yang kebetulan mirip orang lain tidak lagi cukup untuk lolos. Sidik berpanjang
     * lain (model wajah berganti) tidak diikutkan; tanpa sidik yang sepadan hasilnya 0, jadi harus daftar ulang.
     *
     * @param  list<int>  $sidik
     * @param  list<list<int>>  $terdaftar
     */
    public function HitungKemiripan(array $sidik, array $terdaftar): BigDecimal
    {
        $acuan = null;

        foreach ($terdaftar as $sidikTerdaftar) {
            if (count($sidikTerdaftar) !== count($sidik)) {
                continue;
            }

            $acuan = $acuan === null ? $sidikTerdaftar : array_map(fn (int $a, int $b): int => $a + $b, $acuan, $sidikTerdaftar);
        }

        return $acuan === null ? BigDecimal::zero() : self::HitungKosinus($sidik, $acuan);
    }

    public function CekCocok(BigDecimal $kemiripan): bool
    {
        return $kemiripan->isGreaterThanOrEqualTo(BigDecimal::of((string) config('karyawan.AmbangKemiripanWajah')));
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function HitungKosinus(array $a, array $b): BigDecimal
    {
        $titik = BigInteger::zero();
        $normaA = BigInteger::zero();
        $normaB = BigInteger::zero();

        foreach ($a as $i => $nilai) {
            $titik = $titik->plus($nilai * $b[$i]);
            $normaA = $normaA->plus($nilai * $nilai);
            $normaB = $normaB->plus($b[$i] * $b[$i]);
        }

        if ($normaA->isZero() || $normaB->isZero() || $titik->isNegativeOrZero()) {
            return BigDecimal::zero();
        }

        $penyebut = $normaA->multipliedBy($normaB)->toBigDecimal()->sqrt(20, RoundingMode::HalfEven);

        return $titik->toBigDecimal()->dividedBy($penyebut, 4, RoundingMode::HalfUp);
    }
}
