import { describe, expect, it } from 'vitest';

import { TerbilangBilangan, TerbilangRupiah } from './Terbilang';

describe('Terbilang Rupiah', () => {
    it.each([
        [0n, 'nol'],
        [1n, 'satu'],
        [11n, 'sebelas'],
        [12n, 'dua belas'],
        [19n, 'sembilan belas'],
        [20n, 'dua puluh'],
        [21n, 'dua puluh satu'],
        [100n, 'seratus'],
        [101n, 'seratus satu'],
        [111n, 'seratus sebelas'],
        [200n, 'dua ratus'],
        [999n, 'sembilan ratus sembilan puluh sembilan'],
        [1000n, 'seribu'],
        [1001n, 'seribu satu'],
        [2000n, 'dua ribu'],
        [11000n, 'sebelas ribu'],
        [100000n, 'seratus ribu'],
        [1000000n, 'satu juta'],
        [1250000n, 'satu juta dua ratus lima puluh ribu'],
        [1000001000n, 'satu miliar seribu'],
        [
            12345678901n,
            'dua belas miliar tiga ratus empat puluh lima juta enam ratus tujuh puluh delapan ribu sembilan ratus satu',
        ],
    ])('%s dieja "%s"', (angka, kata) => {
        expect(TerbilangBilangan(angka)).toBe(kata);
    });

    it('menolak bilangan negatif', () => {
        expect(() => TerbilangBilangan(-1n)).toThrow(RangeError);
    });

    it('rupiah diawali huruf kapital dan diakhiri kata rupiah', () => {
        expect(TerbilangRupiah('1250000.00')).toBe('Satu juta dua ratus lima puluh ribu rupiah');
        expect(TerbilangRupiah('0.00')).toBe('Nol rupiah');
        expect(TerbilangRupiah('1500')).toBe('Seribu lima ratus rupiah');
    });

    it('sen disebut hanya bila bukan nol', () => {
        expect(TerbilangRupiah('1000.50')).toBe('Seribu rupiah dan lima puluh sen');
        expect(TerbilangRupiah('1000.5')).toBe('Seribu rupiah dan lima puluh sen');
        expect(TerbilangRupiah('1000.05')).toBe('Seribu rupiah dan lima sen');
        expect(TerbilangRupiah('1000.00')).toBe('Seribu rupiah');
    });

    it('nilai triliunan tidak bergeser (tanpa float)', () => {
        // 2^53 + 1: bila lewat float, angka terakhirnya menjadi 2 (…992) dan ejaannya bergeser.
        expect(TerbilangRupiah('9007199254740993.00')).toBe(
            'Sembilan kuadriliun tujuh triliun seratus sembilan puluh sembilan miliar dua ratus lima puluh empat juta tujuh ratus empat puluh ribu sembilan ratus sembilan puluh tiga rupiah',
        );
    });

    it('nilai negatif atau bukan desimal menghasilkan teks kosong', () => {
        expect(TerbilangRupiah('-5.00')).toBe('');
        expect(TerbilangRupiah('abc')).toBe('');
        expect(TerbilangRupiah('')).toBe('');
    });
});
