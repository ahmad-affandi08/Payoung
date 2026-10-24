import { describe, expect, it } from 'vitest';

import {
    BandingkanDesimal,
    BulatkanDesimal,
    CekDesimalBulat,
    CekDesimalPositif,
    CekMasukanJumlahValid,
    FormatJumlahSatuan,
    FormatMasukanJumlah,
    HitungJumlahKotor,
    JumlahkanDesimal,
    KalikanDesimal,
    NormalisasiMasukanJumlah,
} from './MasukanJumlah';

describe('MasukanJumlah: kuantitas sebagai teks, maksimal 4 desimal (F-03, CLAUDE.md #7)', () => {
    it('menerima format Indonesia dan ketikan setengah jadi', () => {
        expect(CekMasukanJumlahValid('1.250,5')).toBe(true);
        expect(CekMasukanJumlahValid('0,2500')).toBe(true);
        expect(CekMasukanJumlahValid('3,')).toBe(true);
        expect(CekMasukanJumlahValid('')).toBe(true);
        expect(CekMasukanJumlahValid('0,12345')).toBe(false);
        expect(CekMasukanJumlahValid('-1')).toBe(false);
        expect(CekMasukanJumlahValid('1,2,3')).toBe(false);
        expect(CekMasukanJumlahValid('12a')).toBe(false);
    });

    it('satuan tanpa desimal hanya menerima bilangan bulat', () => {
        expect(CekMasukanJumlahValid('12', { desimal: 0 })).toBe(true);
        expect(CekMasukanJumlahValid('12,5', { desimal: 0 })).toBe(false);
    });

    it('persen: 6 desimal dan tanda % diabaikan', () => {
        expect(NormalisasiMasukanJumlah('33,333333 %', { desimal: 6, digitBulat: 3 })).toBe('33.333333');
        expect(CekMasukanJumlahValid('1000', { desimal: 6, digitBulat: 3 })).toBe(false);
    });

    it('menormalkan ke string desimal server tanpa number', () => {
        expect(NormalisasiMasukanJumlah('1.250,50')).toBe('1250.5');
        expect(NormalisasiMasukanJumlah('0,0000')).toBe('0');
        expect(NormalisasiMasukanJumlah(',5')).toBe('0.5');
        expect(NormalisasiMasukanJumlah('007')).toBe('7');
        expect(NormalisasiMasukanJumlah('3,')).toBe('3');
        expect(NormalisasiMasukanJumlah('')).toBe('');
        expect(() => NormalisasiMasukanJumlah('abc')).toThrow();
    });

    it('memformat nilai server untuk tampilan', () => {
        expect(FormatMasukanJumlah('1250.5000')).toBe('1.250,5');
        expect(FormatMasukanJumlah('18.0000')).toBe('18');
        expect(FormatMasukanJumlah('0.0001')).toBe('0,0001');
        expect(FormatMasukanJumlah('99999999999999.9999')).toBe('99.999.999.999.999,9999');
        expect(FormatMasukanJumlah('')).toBe('');
        expect(FormatJumlahSatuan('150.0000', 'ml')).toBe('150 ml');
    });
});

describe('Aritmetika desimal BigInt', () => {
    it('membandingkan tanpa terpengaruh skala', () => {
        expect(BandingkanDesimal('12', '12.0000')).toBe(0);
        expect(BandingkanDesimal('0.1', '0.09')).toBe(1);
        expect(BandingkanDesimal('11.9999', '12')).toBe(-1);
        expect(BandingkanDesimal('-1', '0')).toBe(-1);
        expect(CekDesimalPositif('0.0001')).toBe(true);
        expect(CekDesimalPositif('0.0000')).toBe(false);
        expect(CekDesimalPositif('')).toBe(false);
        expect(CekDesimalBulat('12.0000')).toBe(true);
        expect(CekDesimalBulat('12.5')).toBe(false);
    });

    it('menjumlahkan alokasi persen tepat 100 tanpa masalah pembulatan float', () => {
        expect(JumlahkanDesimal(['33.333333', '33.333333', '33.333334'])).toBe('100.000000');
        expect(JumlahkanDesimal(['0.1', '0.2'])).toBe('0.3');
        expect(JumlahkanDesimal([])).toBe('0');
    });

    it('jumlah kotor = dasar ÷ (1 − susut/100), susut di [0, 100)', () => {
        expect(HitungJumlahKotor('150', '10')).toBe('166.6667');
        expect(HitungJumlahKotor('18', '0')).toBe('18.0000');
        expect(HitungJumlahKotor('100', '50')).toBe('200.0000');
        expect(HitungJumlahKotor('1.5', '12.5')).toBe('1.7143');
        expect(HitungJumlahKotor('10', '99.999999')).toBe('1000000000.0000');
        expect(HitungJumlahKotor('10', '100')).toBeNull();
        expect(HitungJumlahKotor('10', '-1')).toBeNull();
        expect(HitungJumlahKotor('', '10')).toBeNull();
    });

    it('mengalikan dengan pembulatan HalfUp', () => {
        expect(KalikanDesimal('18', '250')).toBe('4500.0000');
        expect(KalikanDesimal('0.00005', '1')).toBe('0.0001');
        expect(KalikanDesimal('0.00004', '1')).toBe('0.0000');
    });

    it('membulatkan HalfUp untuk tampilan HPP', () => {
        expect(BulatkanDesimal('7800.125000', 2)).toBe('7800.13');
        expect(BulatkanDesimal('7800.124999', 2)).toBe('7800.12');
        expect(BulatkanDesimal('12', 2)).toBe('12.00');
        expect(BulatkanDesimal('0.005', 2)).toBe('0.01');
    });
});
