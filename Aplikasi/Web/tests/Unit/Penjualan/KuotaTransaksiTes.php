<?php

declare(strict_types=1);

use App\Domain\Penjualan\Data\DataKuotaTransaksi;
use App\Domain\Penjualan\Enum\JangkaKuotaTransaksi;
use App\Domain\Penjualan\Enum\StatusKuotaTransaksi;
use App\Domain\Penjualan\Layanan\KuotaTransaksiTenant;
use Carbon\CarbonImmutable;

/** P-04: bentuk data kuota transaksi (sisa, persen, kontrak API) tanpa basis data. */
function DataKuotaUji(int $terpakai, ?int $batas, JangkaKuotaTransaksi $jangka = JangkaKuotaTransaksi::Bulan, ?CarbonImmutable $berakhirPada = null): DataKuotaTransaksi
{
    return new DataKuotaTransaksi($jangka, $jangka === JangkaKuotaTransaksi::Bulan ? '2026-12' : '2026-12-13', $terpakai, $batas, KuotaTransaksiTenant::TentukanStatus($terpakai, $batas), $berakhirPada);
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
        'Jangka' => 'Bulan',
        'Periode' => '2026-12',
        'Terpakai' => 80,
        'Batas' => 100,
        'Sisa' => 20,
        'Persen' => 80,
        'Status' => 'HampirHabis',
        'LabelStatus' => 'Hampir habis',
        'BerakhirPada' => null,
    ])->and(StatusKuotaTransaksi::from('Habis')->AmbilLabel())->toBe('Habis');
});

it('jangka Hari membawa tanggal bisnis sebagai periode dan saat berakhirnya hari dalam UTC', function (): void {
    $berakhir = CarbonImmutable::parse('2026-12-13 16:59:59', 'UTC');

    expect(DataKuotaUji(10, 10, JangkaKuotaTransaksi::Hari, $berakhir)->KeLarik())->toMatchArray([
        'Jangka' => 'Hari',
        'Periode' => '2026-12-13',
        'Status' => 'Habis',
        'BerakhirPada' => '2026-12-13T16:59:59Z',
    ])->and(JangkaKuotaTransaksi::Hari->AmbilKolomBatas())->toBe('KuotaTransaksiHarian')
        ->and(JangkaKuotaTransaksi::Bulan->AmbilKolomBatas())->toBe('KuotaTransaksiBulanan')
        ->and(JangkaKuotaTransaksi::Hari->AmbilKodeTindakan())->toBe('kuota.transaksi-harian');
});
