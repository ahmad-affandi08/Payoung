<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Layanan\PenguraiMutasiBank;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;

/* FIN-09: pengurai nominal & tanggal rekening koran bank Indonesia (tanpa float). */

it('membaca nominal format Indonesia, internasional, kurung, minus, dan akhiran CR/DB', function (string $teks, string $nilai, int $tanda): void {
    expect(PenguraiMutasiBank::UraiNominal($teks))->toBe(['Nilai' => $nilai, 'Tanda' => $tanda]);
})->with([
    ['1.250.000,00', '1250000.00', 1],
    ['1,250,000.00', '1250000.00', 1],
    ['Rp 1.250.000', '1250000.00', 1],
    ['1.250', '1250.00', 1],
    ['12,5', '12.50', 1],
    ['(25.000)', '25000.00', -1],
    ['-25.000', '25000.00', -1],
    ['500,000.00 CR', '500000.00', 1],
    ['75.000 DB', '75000.00', -1],
]);

it('kosong = null. Teks bukan angka ditolak', function (): void {
    expect(PenguraiMutasiBank::UraiNominal(''))->toBeNull()
        ->and(PenguraiMutasiBank::UraiNominal('-'))->toBeNull();
    PenguraiMutasiBank::UraiNominal('PEND');
})->throws(PelanggaranAturanBisnis::class);

it('membaca tanggal d/m/Y, d-m-Y, Y-m-d, d/m/y, dan tanggal+jam Excel', function (): void {
    expect(PenguraiMutasiBank::UraiTanggal('01/10/2026'))->toBe('2026-10-01')
        ->and(PenguraiMutasiBank::UraiTanggal('5-10-2026'))->toBe('2026-10-05')
        ->and(PenguraiMutasiBank::UraiTanggal('2026-10-07'))->toBe('2026-10-07')
        ->and(PenguraiMutasiBank::UraiTanggal('09/10/26'))->toBe('2026-10-09')
        ->and(PenguraiMutasiBank::UraiTanggal('2026-10-11 00:00:00'))->toBe('2026-10-11');
});
