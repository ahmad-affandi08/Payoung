<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Layanan\PenghitungHargaDariModal;
use Brick\Math\BigDecimal;

/*
 * D-266 rumus harga dari modal: harga = modal × (1 + untung/100), dibulatkan ke kelipatan terdekat. "Untung" dihitung
 * dari modal, bukan margin dari harga jual.
 */

describe('Hitung', function (): void {
    it('untung 20% dari modal 10.000 menjadi 12.000 (bukan 12.500 seperti margin)', function (): void {
        expect(PenghitungHargaDariModal::Hitung(BigDecimal::of('10000'), BigDecimal::of('20'), 0)->KeString())->toBe('12000.00');
    });

    it('modal pecahan per satuan dasar dikalikan dulu baru dibulatkan, tidak dibulatkan dua kali', function (): void {
        // 120.000 per dus isi 12 = 10.000 per pcs; modal 8.333,333333 × 1,2 = 9.999,9999996 → 10.000,00.
        expect(PenghitungHargaDariModal::Hitung(BigDecimal::of('8333.333333'), BigDecimal::of('20'), 0)->KeString())->toBe('10000.00');
    });

    it('pembulatan ke kelipatan terdekat: 12.300 ke 500 terdekat = 12.500, 12.200 = 12.000', function (): void {
        expect(PenghitungHargaDariModal::Hitung(BigDecimal::of('10250'), BigDecimal::of('20'), 500)->KeString())->toBe('12500.00')
            ->and(PenghitungHargaDariModal::Hitung(BigDecimal::of('10166.67'), BigDecimal::of('20'), 500)->KeString())->toBe('12000.00')
            ->and(PenghitungHargaDariModal::Hitung(BigDecimal::of('10250'), BigDecimal::of('20'), 100)->KeString())->toBe('12300.00')
            ->and(PenghitungHargaDariModal::Hitung(BigDecimal::of('10250'), BigDecimal::of('20'), 1000)->KeString())->toBe('12000.00');
    });

    it('untung 0 persen = harga sama dengan modal', function (): void {
        expect(PenghitungHargaDariModal::Hitung(BigDecimal::of('4750'), BigDecimal::of('0'), 0)->KeString())->toBe('4750.00');
    });

    it('untung pecahan 7,5 persen', function (): void {
        expect(PenghitungHargaDariModal::Hitung(BigDecimal::of('20000'), BigDecimal::of('7.5'), 0)->KeString())->toBe('21500.00');
    });
});

describe('HitungPerubahanPersen', function (): void {
    it('naik, turun, dan harga lama nol', function (): void {
        expect((string) PenghitungHargaDariModal::HitungPerubahanPersen(Uang::Dari('12000'), Uang::Dari('13200')))->toBe('10.00')
            ->and((string) PenghitungHargaDariModal::HitungPerubahanPersen(Uang::Dari('12000'), Uang::Dari('10800')))->toBe('-10.00')
            ->and((string) PenghitungHargaDariModal::HitungPerubahanPersen(Uang::Nol(), Uang::Dari('500')))->toBe('0.00');
    });
});

describe('AmbilPersen', function (): void {
    it('menerima angka biasa dan koma desimal', function (): void {
        expect((string) PenghitungHargaDariModal::AmbilPersen('20'))->toBe('20')
            ->and((string) PenghitungHargaDariModal::AmbilPersen(' 7,5 '))->toBe('7.5')
            ->and((string) PenghitungHargaDariModal::AmbilPersen('1000'))->toBe('1000');
    });

    it('menolak huruf, minus, lebih dari 1000, dan lebih dari dua desimal', function (): void {
        foreach (['', 'abc', '-5', '1001', '5,555', '20%'] as $salah) {
            expect(fn () => PenghitungHargaDariModal::AmbilPersen($salah))->toThrow(PelanggaranAturanBisnis::class);
        }
    });
});

it('pembulatan hanya dari daftar yang sama dengan perubahan harga massal', function (): void {
    PenghitungHargaDariModal::PastikanPembulatanSah(500);

    expect(fn () => PenghitungHargaDariModal::PastikanPembulatanSah(250))->toThrow(PelanggaranAturanBisnis::class);
});
