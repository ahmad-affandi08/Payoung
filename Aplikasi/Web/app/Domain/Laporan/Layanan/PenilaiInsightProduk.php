<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Insight produk (X6, §10 laporan "analisis ABC" & "menu engineering"), fungsi murni tanpa float atas baris per produk
 * laporan penjualan (`Qty`, `Bersih`, `Hpp`).
 *
 * - **ABC** (Pareto): produk berpenjualan bersih > 0 diurut menurun; kelas A selama kumulatif sebelum produk itu < 80%,
 *   B selama < 95%, sisanya C. Produk yang menyeberangi batas ikut kelas di bawah batas (A berisi produk yang menyusun
 *   ±80% omzet).
 * - **Menu engineering** (Kasavana & Smith): populer bila porsi qty ≥ 70% × (1 ÷ jumlah menu); margin tinggi bila
 *   margin kontribusi per unit (bersih − HPP) ÷ qty ≥ rata-rata tertimbang (Σ margin ÷ Σ qty). Star = populer & margin
 *   tinggi, Plowhorse = populer & margin rendah, Puzzle = tidak populer & margin tinggi, Dog = keduanya rendah.
 */
final class PenilaiInsightProduk
{
    public const BATAS_A = '0.80';

    public const BATAS_B = '0.95';

    public const FAKTOR_POPULER = '0.70';

    /** Kenaikan harga yang disarankan paling banyak sekian persen dari harga rata-rata. */
    public const KENAIKAN_MAKS = 10;

    /**
     * @param  list<array{IdProduk: int, NamaProduk: string, Qty: string, Bersih: string, Hpp: string}>  $baris
     * @return array{Baris: list<array{IdProduk: int, NamaProduk: string, Qty: string, Bersih: string, Porsi: string, PorsiKumulatif: string, Kelas: string}>, Ringkasan: array<string, array{Jumlah: int, Bersih: string}>}
     */
    public function Abc(array $baris): array
    {
        $baris = array_values(array_filter($baris, fn (array $b): bool => BigDecimal::of($b['Bersih'])->isPositive()));
        usort($baris, fn (array $a, array $b): int => BigDecimal::of($b['Bersih'])->compareTo($a['Bersih']) ?: $a['IdProduk'] <=> $b['IdProduk']);
        $total = array_reduce($baris, fn (BigDecimal $t, array $b): BigDecimal => $t->plus($b['Bersih']), BigDecimal::zero());
        $ringkasan = ['A' => ['Jumlah' => 0, 'Bersih' => Uang::Nol()], 'B' => ['Jumlah' => 0, 'Bersih' => Uang::Nol()], 'C' => ['Jumlah' => 0, 'Bersih' => Uang::Nol()]];
        $kumulatif = BigRational::zero();
        $hasil = [];

        foreach ($baris as $b) {
            $porsi = $total->isZero() ? BigRational::zero() : BigRational::of($b['Bersih'])->dividedBy($total);
            $kelas = $kumulatif->compareTo(self::BATAS_A) < 0 ? 'A' : ($kumulatif->compareTo(self::BATAS_B) < 0 ? 'B' : 'C');
            $kumulatif = $kumulatif->plus($porsi);
            $ringkasan[$kelas]['Jumlah']++;
            $ringkasan[$kelas]['Bersih'] = $ringkasan[$kelas]['Bersih']->Tambah(Uang::Dari($b['Bersih']));
            $hasil[] = [
                'IdProduk' => $b['IdProduk'],
                'NamaProduk' => $b['NamaProduk'],
                'Qty' => $b['Qty'],
                'Bersih' => $b['Bersih'],
                'Porsi' => self::Persen($porsi),
                'PorsiKumulatif' => self::Persen($kumulatif),
                'Kelas' => $kelas,
            ];
        }

        return [
            'Baris' => $hasil,
            'Ringkasan' => array_map(fn (array $r): array => ['Jumlah' => $r['Jumlah'], 'Bersih' => $r['Bersih']->KeString()], $ringkasan),
        ];
    }

    /**
     * @param  list<array{IdProduk: int, NamaProduk: string, Qty: string, Bersih: string, Hpp: string}>  $baris
     * @return array{Baris: list<array{IdProduk: int, NamaProduk: string, Qty: string, Bersih: string, Hpp: string, MarginPerUnit: string, PorsiQty: string, Populer: bool, MarginTinggi: bool, Kelas: string}>, BatasPorsiQty: string, RataRataMargin: string}
     */
    public function Menu(array $baris): array
    {
        $baris = array_values(array_filter($baris, fn (array $b): bool => BigDecimal::of($b['Qty'])->isPositive() && BigDecimal::of($b['Bersih'])->isPositive()));
        $n = count($baris);

        if ($n === 0) {
            return ['Baris' => [], 'BatasPorsiQty' => '0.00', 'RataRataMargin' => '0.00'];
        }

        $totalQty = array_reduce($baris, fn (BigDecimal $t, array $b): BigDecimal => $t->plus($b['Qty']), BigDecimal::zero());
        $totalMargin = array_reduce($baris, fn (BigDecimal $t, array $b): BigDecimal => $t->plus(BigDecimal::of($b['Bersih'])->minus($b['Hpp'])), BigDecimal::zero());
        $batasPorsi = BigRational::of(self::FAKTOR_POPULER)->dividedBy($n);
        $rataMargin = BigRational::of($totalMargin)->dividedBy($totalQty);
        $hasil = [];

        foreach ($baris as $b) {
            $margin = BigRational::of(BigDecimal::of($b['Bersih'])->minus($b['Hpp']))->dividedBy($b['Qty']);
            $porsi = BigRational::of($b['Qty'])->dividedBy($totalQty);
            $populer = $porsi->compareTo($batasPorsi) >= 0;
            $tinggi = $margin->compareTo($rataMargin) >= 0;
            $hargaRata = BigRational::of($b['Bersih'])->dividedBy($b['Qty']);
            $hasil[] = [
                'IdProduk' => $b['IdProduk'],
                'NamaProduk' => $b['NamaProduk'],
                'Qty' => $b['Qty'],
                'Bersih' => $b['Bersih'],
                'Hpp' => $b['Hpp'],
                'MarginPerUnit' => (string) $margin->toScale(2, RoundingMode::HalfUp),
                'HargaRata' => (string) $hargaRata->toScale(2, RoundingMode::HalfUp),
                'SaranHarga' => $populer && ! $tinggi ? self::SaranHarga($hargaRata, $rataMargin->minus($margin)) : null,
                'PorsiQty' => self::Persen($porsi),
                'Populer' => $populer,
                'MarginTinggi' => $tinggi,
                'Kelas' => $populer ? ($tinggi ? 'Star' : 'Plowhorse') : ($tinggi ? 'Puzzle' : 'Dog'),
            ];
        }

        usort($hasil, fn (array $a, array $b): int => BigDecimal::of($b['Qty'])->compareTo($a['Qty']) ?: $a['IdProduk'] <=> $b['IdProduk']);

        return ['Baris' => $hasil, 'BatasPorsiQty' => self::Persen($batasPorsi), 'RataRataMargin' => (string) $rataMargin->toScale(2, RoundingMode::HalfUp)];
    }

    /**
     * Saran harga untuk menu Plowhorse (laris, margin di bawah rata-rata): naikkan sebesar kekurangan margin terhadap
     * rata-rata, paling banyak `KENAIKAN_MAKS`% dari harga rata-rata, lalu dibulatkan ke atas ke kelipatan Rp 500.
     * Hanya saran; null bila kenaikannya tidak berarti.
     */
    private static function SaranHarga(BigRational $hargaRata, BigRational $kekurangan): ?string
    {
        $batas = $hargaRata->multipliedBy(self::KENAIKAN_MAKS)->dividedBy(100);
        $naik = $kekurangan->compareTo($batas) > 0 ? $batas : $kekurangan;
        $target = BigDecimal::of((string) $hargaRata->plus($naik)->toScale(2, RoundingMode::HalfUp));
        $saran = $target->dividedBy(500, 0, RoundingMode::Up)->multipliedBy(500)->toScale(2);

        return $saran->isGreaterThan((string) $hargaRata->toScale(2, RoundingMode::HalfUp)) ? (string) $saran : null;
    }

    /** Pecahan → persen 2 desimal ("0.4567" → "45.67"). */
    private static function Persen(BigRational $pecahan): string
    {
        return (string) $pecahan->multipliedBy(100)->toScale(2, RoundingMode::HalfUp);
    }
}
