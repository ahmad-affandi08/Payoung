<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Layanan\PenghitungPenyusutan;
use App\Domain\Bersama\Nilai\Uang;

/*
 * FIN-10: jadwal penyusutan garis lurus — mulai bulan perolehan, bulan terakhir menyerap pembulatan sehingga total
 * tepat (harga − nilai sisa − akumulasi awal), aset saldo awal disusutkan selama sisa masa manfaat.
 */

function TotalJadwal(array $jadwal): string
{
    return array_reduce($jadwal, fn (Uang $t, array $b): Uang => $t->Tambah($b['Jumlah']), Uang::Nol())->KeString();
}

it('garis lurus: 48 bulan, mulai bulan perolehan, total tepat', function (): void {
    $jadwal = PenghitungPenyusutan::HitungJadwal(Uang::Dari('12000000'), Uang::Nol(), Uang::Nol(), 48, '2026-07', '2026-07');

    expect($jadwal)->toHaveCount(48)
        ->and($jadwal[0])->toMatchArray(['Periode' => '2026-07'])
        ->and($jadwal[0]['Jumlah']->KeString())->toBe('250000.00')
        ->and($jadwal[47]['Periode'])->toBe('2030-06')
        ->and(TotalJadwal($jadwal))->toBe('12000000.00');
});

it('pembulatan diserap bulan terakhir. Nilai sisa tidak disusutkan', function (): void {
    $jadwal = PenghitungPenyusutan::HitungJadwal(Uang::Dari('10000001'), Uang::Dari('1'), Uang::Nol(), 3, '2026-01', '2026-01');

    expect(array_map(fn (array $b): string => $b['Jumlah']->KeString(), $jadwal))->toBe(['3333333.33', '3333333.33', '3333333.34'])
        ->and(TotalJadwal($jadwal))->toBe('10000000.00');
});

it('saldo awal: sisa masa manfaat dari periode mulai, dasar dikurangi akumulasi awal', function (): void {
    // Dibeli Okt 2024, 96 bulan; mulai dipakai di aplikasi Okt 2026 (24 bulan lewat) dengan akumulasi 5 juta.
    $jadwal = PenghitungPenyusutan::HitungJadwal(Uang::Dari('20000000'), Uang::Nol(), Uang::Dari('5000000'), 96, '2024-10', '2026-10');

    expect($jadwal)->toHaveCount(72)
        ->and($jadwal[0]['Periode'])->toBe('2026-10')
        ->and($jadwal[0]['Jumlah']->KeString())->toBe('208333.33')
        ->and(TotalJadwal($jadwal))->toBe('15000000.00');
});

it('tanpa jadwal: masa manfaat 0 (tanah), habis, atau tidak ada nilai tersisa', function (): void {
    expect(PenghitungPenyusutan::HitungJadwal(Uang::Dari('500000000'), Uang::Nol(), Uang::Nol(), 0, '2026-01', '2026-01'))->toBe([])
        ->and(PenghitungPenyusutan::HitungJadwal(Uang::Dari('1000000'), Uang::Nol(), Uang::Nol(), 12, '2020-01', '2026-01'))->toBe([])
        ->and(PenghitungPenyusutan::HitungJadwal(Uang::Dari('1000000'), Uang::Nol(), Uang::Dari('1000000'), 12, '2026-01', '2026-03'))->toBe([]);
});
