<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Layanan;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Rekomendasi bundling (§10.10 "rekomendasi harga/bundling"): pasangan produk yang sering dibeli dalam transaksi yang
 * sama (analisis keranjang belanja), fungsi murni tanpa float. Per pasangan: `Support` (jumlah transaksi berisi
 * keduanya), `Keyakinan` A→B dan B→A (% transaksi berisi satu produk yang juga berisi pasangannya), dan `Lift` =
 * (transaksi berisi keduanya × seluruh transaksi) ÷ (transaksi berisi A × transaksi berisi B); lift > 1 berarti
 * keduanya dibeli bersama lebih sering daripada kebetulan. Pasangan di bawah `MIN_SUPPORT` dibuang; keranjang dengan
 * lebih dari `MAKS_ITEM` produk dilewati supaya jumlah pasangan tetap terkendali.
 */
final class PencariPasanganProduk
{
    public const MIN_SUPPORT = 3;

    public const MAKS_ITEM = 30;

    /**
     * @param  list<list<int>>  $keranjang  Id produk berbeda per transaksi
     * @return array{JumlahTransaksi: int, Baris: list<array{IdProdukA: int, IdProdukB: int, Support: int, PorsiTransaksi: string, KeyakinanAB: string, KeyakinanBA: string, Lift: string}>}
     */
    public function Cari(array $keranjang, int $batas = 50, int $minSupport = self::MIN_SUPPORT): array
    {
        $total = count($keranjang);
        $satuan = [];
        $pasangan = [];

        foreach ($keranjang as $isi) {
            $isi = array_values(array_unique($isi));
            sort($isi);

            foreach ($isi as $id) {
                $satuan[$id] = ($satuan[$id] ?? 0) + 1;
            }

            if (count($isi) < 2 || count($isi) > self::MAKS_ITEM) {
                continue;
            }

            for ($i = 0, $n = count($isi); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $kunci = $isi[$i].'-'.$isi[$j];
                    $pasangan[$kunci] = ($pasangan[$kunci] ?? 0) + 1;
                }
            }
        }

        $hasil = [];

        foreach ($pasangan as $kunci => $bersama) {
            if ($bersama < $minSupport) {
                continue;
            }

            [$a, $b] = array_map('intval', explode('-', $kunci));
            $hasil[] = [
                'IdProdukA' => $a,
                'IdProdukB' => $b,
                'Support' => $bersama,
                'PorsiTransaksi' => self::Persen($bersama, $total),
                'KeyakinanAB' => self::Persen($bersama, $satuan[$a]),
                'KeyakinanBA' => self::Persen($bersama, $satuan[$b]),
                'Lift' => (string) BigDecimal::of($bersama)->multipliedBy($total)->dividedBy(BigDecimal::of($satuan[$a])->multipliedBy($satuan[$b]), 2, RoundingMode::HalfUp),
            ];
        }

        usort($hasil, fn (array $x, array $y): int => BigDecimal::of($y['Lift'])->compareTo($x['Lift']) ?: $y['Support'] <=> $x['Support'] ?: [$x['IdProdukA'], $x['IdProdukB']] <=> [$y['IdProdukA'], $y['IdProdukB']]);

        return ['JumlahTransaksi' => $total, 'Baris' => array_slice($hasil, 0, $batas)];
    }

    private static function Persen(int $bagian, int $dasar): string
    {
        return $dasar === 0 ? '0.00' : (string) BigDecimal::of($bagian * 100)->dividedBy($dasar, 2, RoundingMode::HalfUp);
    }
}
