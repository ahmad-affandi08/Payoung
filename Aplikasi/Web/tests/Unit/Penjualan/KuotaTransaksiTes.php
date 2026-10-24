<?php

declare(strict_types=1);

use App\Domain\Penjualan\Data\DataKuotaTransaksi;
use App\Domain\Penjualan\Enum\StatusKuotaTransaksi;
use App\Domain\Penjualan\Layanan\KuotaTransaksiTenant;

/** P-04: bentuk data kuota transaksi (sisa, persen, kontrak API) tanpa basis data. */
function DataKuotaUji(int $terpakai, ?int $batas): DataKuotaTransaksi
{
    return new DataKuotaTransaksi('2026-12', $terpakai, $batas, KuotaTransaksiTenant::TentukanStatus($terpakai, $batas));
}

it('sisa tidak pernah negatif dan persen dibatasi 100', function (): void {
    expect(DataKuotaUji(40, 100)->AmbilSisa())->toBe(60)
        ->and(DataKuotaUji(40, 100)->AmbilPersen())->toBe(40)
        ->and(DataKuotaUji(130, 100)->AmbilSisa())->toBe(0)
        ->and(DataKuotaUji(130, 100)->AmbilPersen())->toBe(100)
        ->and(DataKuotaUji(1, 3)->AmbilPersen())->toBe(34);
});

it('batas 0 = 100% terpakai dan habis; tanpa batas tidak punya sisa maupun persen', function (): void {
    expect(DataKuotaUji(0, 0)->AmbilPersen())->toBe(100)
        ->and(DataKuotaUji(0, 0)->CekHabis())->toBeTrue()
        ->and(DataKuotaUji(5000, null)->AmbilSisa())->toBeNull()
        ->and(DataKuotaUji(5000, null)->AmbilPersen())->toBeNull()
        ->and(DataKuotaUji(5000, null)->CekHabis())->toBeFalse();
});

it('KeLarik memakai key PascalCase kontrak API', function (): void {
    expect(DataKuotaUji(80, 100)->KeLarik())->toBe([
        'Periode' => '2026-12',
        'Terpakai' => 80,
        'Batas' => 100,
        'Sisa' => 20,
        'Persen' => 80,
        'Status' => 'HampirHabis',
        'LabelStatus' => 'Hampir habis',
    ])->and(StatusKuotaTransaksi::from('Habis')->AmbilLabel())->toBe('Habis');
});
