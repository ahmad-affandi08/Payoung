<?php

declare(strict_types=1);

use App\Domain\Laporan\Layanan\PenilaiMusimRestock;
use Carbon\CarbonImmutable;

/*
 * X6 faktor musiman saran restock: pergeseran ke tahun lalu selaras Lebaran di sekitar Ramadan/Lebaran, 364 hari di
 * luar itu; faktor = laju cakupan tahun lalu ÷ laju dasar tahun lalu, dibatasi 0,50–3,00, tanpa data = 1,00.
 */

$lebaran = [
    2026 => CarbonImmutable::parse('2026-03-20'),
    2027 => CarbonImmutable::parse('2027-03-10'),
];

it('periode cakupan di sekitar Ramadan/Lebaran digeser sebesar selisih Lebaran tahun ini dan tahun lalu', function () use ($lebaran): void {
    expect(PenilaiMusimRestock::TentukanPergeseran(CarbonImmutable::parse('2027-02-20'), 14, $lebaran))
        ->toBe(['Jenis' => 'Lebaran', 'SelisihHari' => 355, 'Lebaran' => '2027-03-10']);
    // Titik tengah 14 hari setelah Lebaran masih masuk (arus balik).
    expect(PenilaiMusimRestock::TentukanPergeseran(CarbonImmutable::parse('2027-03-18'), 14, $lebaran)['Jenis'])->toBe('Lebaran');
});

it('di luar musim Lebaran atau tanpa data Lebaran tahun lalu: 364 hari (52 minggu)', function () use ($lebaran): void {
    expect(PenilaiMusimRestock::TentukanPergeseran(CarbonImmutable::parse('2027-07-01'), 14, $lebaran))
        ->toBe(['Jenis' => 'TahunLalu', 'SelisihHari' => 364, 'Lebaran' => null]);
    expect(PenilaiMusimRestock::TentukanPergeseran(CarbonImmutable::parse('2026-03-01'), 14, $lebaran)['Jenis'])->toBe('TahunLalu');
});

it('faktor dari laju tahun lalu, dibatasi 0,50–3,00. Data kosong = 1,00', function (): void {
    expect((string) PenilaiMusimRestock::HitungFaktor('28', 14, '28', 28))->toBe('2.00')
        ->and((string) PenilaiMusimRestock::HitungFaktor('7', 14, '28', 28))->toBe('0.50')
        ->and((string) PenilaiMusimRestock::HitungFaktor('100', 14, '10', 28))->toBe('3.00')
        ->and((string) PenilaiMusimRestock::HitungFaktor('1', 14, '1', 28))->toBe('2.00')
        ->and((string) PenilaiMusimRestock::HitungFaktor('0', 14, '28', 28))->toBe('1.00')
        ->and((string) PenilaiMusimRestock::HitungFaktor('5', 14, '0', 28))->toBe('1.00');
});
