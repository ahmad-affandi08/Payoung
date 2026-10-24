<?php

declare(strict_types=1);

use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use Carbon\CarbonImmutable;

/** D-183: penyedia Kotak Tindakan berbatas sektor memakai aturan yang sama dengan menu web. */
function KonteksBersektor(array $sektor): DataKonteksTindakan
{
    return new DataKonteksTindakan(1, 1, true, [], null, CarbonImmutable::parse('2026-10-24'), $sektor);
}

it('sektor belum diketahui menjalankan semua penyedia', function (): void {
    expect(KonteksBersektor([])->CekSesuaiSektor(['SVC-LDR']))->toBeTrue()
        ->and(KonteksBersektor([])->CekSesuaiSektor(null, ['BIZ']))->toBeTrue();
});

it('awalan cocok per segmen: laundry tidak muncul di salon, SVC mencakup bengkel', function (): void {
    expect(KonteksBersektor(['SVC-SLN'])->CekSesuaiSektor(['SVC-LDR']))->toBeFalse()
        ->and(KonteksBersektor(['SVC-LDR'])->CekSesuaiSektor(['SVC-LDR']))->toBeTrue()
        ->and(KonteksBersektor(['SVC-WRK'])->CekSesuaiSektor(['SVC']))->toBeTrue()
        ->and(KonteksBersektor(['SVCX'])->CekSesuaiSektor(['SVC']))->toBeFalse();
});

it('butir kasir disembunyikan hanya bila semua sektor usaha tanpa kasir', function (): void {
    expect(KonteksBersektor(['BIZ-JSA'])->CekSesuaiSektor(null, ['BIZ']))->toBeFalse()
        ->and(KonteksBersektor(['BIZ-JSA', 'RTL-GEN'])->CekSesuaiSektor(null, ['BIZ']))->toBeTrue();
});
