<?php

declare(strict_types=1);

use App\Domain\Laporan\Layanan\PeramalPenjualan;
use Carbon\CarbonImmutable;

/*
 * Prediksi penjualan §10.10: rata-rata hari-dalam-minggu × faktor tren 4 minggu terakhir vs 4 minggu sebelumnya
 * (dibatasi 0,80–1,25), minimal 14 hari riwayat, hari tutup dihitung nol, tanpa float.
 */

/**
 * Seri harian: setiap hari `$bersih` dan `$transaksi` dari `$awal` sampai kemarin, kecuali hari dalam `$tutup` (ISO).
 *
 * @param  list<int>  $tutup
 * @return array<string, array{Bersih: string, JumlahTransaksi: int}>
 */
function SeriHarian(CarbonImmutable $awal, CarbonImmutable $hariIni, string $bersih, int $transaksi, array $tutup = []): array
{
    $seri = [];

    for ($d = $awal; $d->lessThan($hariIni); $d = $d->addDay()) {
        if (! in_array($d->dayOfWeekIso, $tutup, true)) {
            $seri[$d->toDateString()] = ['Bersih' => $bersih, 'JumlahTransaksi' => $transaksi];
        }
    }

    return $seri;
}

it('riwayat kurang dari 14 hari: tidak cukup data, tanpa angka', function (): void {
    $hariIni = CarbonImmutable::parse('2026-10-14');
    $hasil = (new PeramalPenjualan)->Ramal(SeriHarian($hariIni->subDays(10), $hariIni, '100000.00', 5), $hariIni);

    expect($hasil['Cukup'])->toBeFalse()
        ->and($hasil['HariData'])->toBe(10)
        ->and($hasil['Baris'])->toBe([]);
    expect((new PeramalPenjualan)->Ramal([], $hariIni)['Cukup'])->toBeFalse();
});

it('penjualan datar 8 minggu: perkiraan = rata-rata hari itu, faktor tren 1,00, hari tutup nol, rentang historis', function (): void {
    $hariIni = CarbonImmutable::parse('2026-10-14');   // Rabu
    $seri = SeriHarian($hariIni->subDays(56), $hariIni, '100000.00', 5, tutup: [7]);   // Minggu tutup
    $seri[$hariIni->subDays(7)->toDateString()] = ['Bersih' => '160000.00', 'JumlahTransaksi' => 9];   // Rabu lalu ramai
    $hasil = (new PeramalPenjualan)->Ramal($seri, $hariIni, 7);

    expect($hasil['Cukup'])->toBeTrue()
        ->and($hasil['FaktorTren'])->toBe('1.03')
        ->and($hasil['Baris'])->toHaveCount(7);
    $rabu = $hasil['Baris'][0];
    // 8 Rabu: 7 × 100.000 + 160.000 = 860.000 ÷ 8 = 107.500. Tren: 28 hari terakhir 24 hari buka × 100.000 + 60.000 = 2.460.000
    // ÷ 2.400.000 = 1,0250 → perkiraan 107.500 × 1,0250 = 110.187,50 (faktor tampil 1,03).
    expect($rabu['Tanggal'])->toBe('2026-10-14')
        ->and($rabu['Hari'])->toBe(3)
        ->and($rabu['Perkiraan'])->toBe('110187.50')
        ->and($rabu['Sampel'])->toBe(8)
        ->and($rabu['Terendah'])->toBe('100000.00')
        ->and($rabu['Tertinggi'])->toBe('160000.00');
    $minggu = array_values(array_filter($hasil['Baris'], fn (array $b): bool => $b['Hari'] === 7))[0];
    expect($minggu['Perkiraan'])->toBe('0.00')->and($minggu['PerkiraanTransaksi'])->toBe(0);
});

it('tren naik dibatasi 1,25 dan turun dibatasi 0,80, tanpa 8 minggu data faktor 1,00', function (): void {
    $hariIni = CarbonImmutable::parse('2026-10-14');
    $naik = [];
    $turun = [];

    for ($d = $hariIni->subDays(56); $d->lessThan($hariIni); $d = $d->addDay()) {
        $baru = (int) $d->diffInDays($hariIni) <= 28;
        $naik[$d->toDateString()] = ['Bersih' => $baru ? '300000.00' : '100000.00', 'JumlahTransaksi' => 10];
        $turun[$d->toDateString()] = ['Bersih' => $baru ? '10000.00' : '100000.00', 'JumlahTransaksi' => 10];
    }

    $ramal = new PeramalPenjualan;

    expect($ramal->Ramal($naik, $hariIni, 1)['FaktorTren'])->toBe('1.25')
        ->and($ramal->Ramal($turun, $hariIni, 1)['FaktorTren'])->toBe('0.80')
        ->and($ramal->Ramal(SeriHarian($hariIni->subDays(30), $hariIni, '100000.00', 5), $hariIni, 1)['FaktorTren'])->toBe('1.00');
});

it('perkiraan dikalikan faktor tren dan dibulatkan ke sen. Total perkiraan = jumlah baris', function (): void {
    $hariIni = CarbonImmutable::parse('2026-10-14');
    $seri = [];

    for ($d = $hariIni->subDays(56); $d->lessThan($hariIni); $d = $d->addDay()) {
        $baru = (int) $d->diffInDays($hariIni) <= 28;
        $seri[$d->toDateString()] = ['Bersih' => $baru ? '120000.00' : '100000.00', 'JumlahTransaksi' => 10];
    }

    $hasil = (new PeramalPenjualan)->Ramal($seri, $hariIni, 3);

    // Tren 1,20; setiap hari 100.000/120.000 per tanggal: hari baru = 4 minggu terakhir (28 hari) 120.000, 4 minggu sebelumnya 100.000
    // → rata-rata hari-dalam-minggu 110.000 × 1,20 = 132.000.
    expect($hasil['FaktorTren'])->toBe('1.20')
        ->and($hasil['Baris'][0]['Perkiraan'])->toBe('132000.00')
        ->and($hasil['Baris'][0]['PerkiraanTransaksi'])->toBe(12)
        ->and($hasil['TotalPerkiraan'])->toBe('396000.00');
});

it('hari ini bertanda zona waktu outlet (WIB) tidak menggeser hitungan hari riwayat', function (): void {
    $hariIni = CarbonImmutable::parse('2026-10-14', 'Asia/Jakarta')->startOfDay();
    $hasil = (new PeramalPenjualan)->Ramal(SeriHarian(CarbonImmutable::parse('2026-09-23'), CarbonImmutable::parse('2026-10-14'), '100000.00', 5), $hariIni, 1);

    expect($hasil['HariData'])->toBe(21)
        ->and($hasil['Cukup'])->toBeTrue()
        ->and($hasil['Baris'][0]['Tanggal'])->toBe('2026-10-14');
});
