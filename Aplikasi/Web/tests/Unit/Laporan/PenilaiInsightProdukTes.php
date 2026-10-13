<?php

declare(strict_types=1);

use App\Domain\Laporan\Layanan\PenilaiInsightProduk;

/*
 * X6 insight produk: ABC Pareto 80/95 dan menu engineering Kasavana & Smith (70% × 1/n, margin rata-rata tertimbang),
 * tanpa float.
 */

function BarisInsight(int $id, string $nama, string $qty, string $bersih, string $hpp = '0'): array
{
    return ['IdProduk' => $id, 'NamaProduk' => $nama, 'Qty' => $qty, 'Bersih' => $bersih, 'Hpp' => $hpp];
}

it('ABC: produk penyusun ±80% omzet kelas A, berikutnya sampai 95% B, sisanya C; omzet nol diabaikan', function (): void {
    $hasil = (new PenilaiInsightProduk)->Abc([
        BarisInsight(1, 'Kopi Susu', '100', '500000.00'),
        BarisInsight(2, 'Americano', '60', '300000.00'),
        BarisInsight(3, 'Croissant', '20', '120000.00'),
        BarisInsight(4, 'Air Mineral', '30', '60000.00'),
        BarisInsight(5, 'Stiker', '5', '20000.00'),
        BarisInsight(6, 'Sampel', '3', '0.00'),
    ]);

    expect(array_column($hasil['Baris'], 'Kelas'))->toBe(['A', 'A', 'B', 'B', 'C'])
        ->and(array_column($hasil['Baris'], 'PorsiKumulatif'))->toBe(['50.00', '80.00', '92.00', '98.00', '100.00'])
        ->and($hasil['Ringkasan']['A'])->toBe(['Jumlah' => 2, 'Bersih' => '800000.00'])
        ->and($hasil['Ringkasan']['C']['Jumlah'])->toBe(1);
});

it('menu engineering: Star, Plowhorse, Puzzle, Dog dari porsi qty dan margin per unit', function (): void {
    $hasil = (new PenilaiInsightProduk)->Menu([
        BarisInsight(1, 'Kopi Susu Aren', '100', '2000000.00', '800000.00'),   // margin 12.000/unit, porsi 50%
        BarisInsight(2, 'Es Teh Manis', '80', '640000.00', '480000.00'),      // margin 2.000, porsi 40%
        BarisInsight(3, 'Steak Wagyu', '5', '750000.00', '300000.00'),        // margin 90.000, porsi 2,5%
        BarisInsight(4, 'Roti Bakar', '15', '150000.00', '120000.00'),        // margin 2.000, porsi 7,5%
    ]);
    $kelas = array_column($hasil['Baris'], 'Kelas', 'NamaProduk');

    // Batas populer = 70% × 1/4 = 17,5%; rata-rata margin = 1.840.000 ÷ 200 = 9.200.
    expect($hasil['BatasPorsiQty'])->toBe('17.50')
        ->and($hasil['RataRataMargin'])->toBe('9200.00')
        ->and($kelas)->toBe(['Kopi Susu Aren' => 'Star', 'Es Teh Manis' => 'Plowhorse', 'Roti Bakar' => 'Dog', 'Steak Wagyu' => 'Puzzle'])
        ->and((new PenilaiInsightProduk)->Menu([])['Baris'])->toBe([]);
});

it('menu engineering: saran harga hanya untuk Plowhorse, naik sebesar kekurangan margin tetapi paling banyak 10%, dibulatkan ke atas Rp 500', function (): void {
    $hasil = (new PenilaiInsightProduk)->Menu([
        BarisInsight(1, 'Kopi Susu Aren', '100', '2000000.00', '800000.00'),   // Star
        BarisInsight(2, 'Es Teh Manis', '80', '640000.00', '480000.00'),      // Plowhorse: harga 8.000, margin 2.000
        BarisInsight(3, 'Steak Wagyu', '5', '750000.00', '300000.00'),        // Puzzle
        BarisInsight(4, 'Roti Bakar', '15', '150000.00', '120000.00'),        // Dog
    ]);
    $baris = array_column($hasil['Baris'], null, 'NamaProduk');

    // Margin rata-rata tertimbang = (1.200.000 + 160.000 + 450.000 + 30.000) ÷ 200 = 9.200; kekurangan Es Teh 7.200,
    // dibatasi 10% × 8.000 = 800 → 8.800 → dibulatkan ke atas 9.000.
    expect($baris['Es Teh Manis']['Kelas'])->toBe('Plowhorse')
        ->and($baris['Es Teh Manis']['HargaRata'])->toBe('8000.00')
        ->and($baris['Es Teh Manis']['SaranHarga'])->toBe('9000.00')
        ->and($baris['Kopi Susu Aren']['SaranHarga'])->toBeNull()
        ->and($baris['Steak Wagyu']['SaranHarga'])->toBeNull()
        ->and($baris['Roti Bakar']['SaranHarga'])->toBeNull();
});
