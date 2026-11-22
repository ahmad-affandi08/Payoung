import { describe, expect, it } from 'vitest';

import { FormatPersen, FormatRupiah, RingkasDesimal } from './Format';

describe('FormatRupiah (PRD §17.6.7)', () => {
    it('memformat string desimal dari API gaya Indonesia', () => {
        expect(FormatRupiah('1250000.00')).toBe('Rp 1.250.000');
        expect(FormatRupiah('63500')).toBe('Rp 63.500');
        expect(FormatRupiah('1234.5')).toBe('Rp 1.234,50');
        expect(FormatRupiah('0.00')).toBe('Rp 0');
    });

    it('memberi tanda minus tipografis untuk nilai negatif', () => {
        expect(FormatRupiah('-6000.00')).toBe('−Rp 6.000');
        expect(FormatRupiah('-0.00')).toBe('Rp 0');
    });

    it('tetap presisi untuk angka di luar batas aman float JavaScript', () => {
        expect(FormatRupiah('90071992547409931.99')).toBe('Rp 90.071.992.547.409.931,99');
    });

    it('menolak masukan yang bukan string desimal uang', () => {
        expect(() => FormatRupiah('12,50')).toThrow();
        expect(() => FormatRupiah('1.005')).toThrow();
    });
});

describe('FormatPersen (PRD §17.6.7)', () => {
    it('meringkas nol di belakang dan memakai koma desimal', () => {
        expect(FormatPersen('12.000000')).toBe('12');
        expect(FormatPersen('10.500000')).toBe('10,5');
        expect(FormatPersen('0.125000')).toBe('0,125');
        expect(FormatPersen('100')).toBe('100');
    });

    it('menolak masukan yang bukan string desimal', () => {
        expect(() => FormatPersen('12,5')).toThrow();
    });
});

describe('RingkasDesimal: nol di belakang koma dihapus, bilangan bulat tidak pernah dipangkas', () => {
    it('server meringkas "100.0000" jadi "100", dan itu tidak boleh menjadi "1"', () => {
        expect(RingkasDesimal('100')).toBe('100');
        expect(RingkasDesimal('10')).toBe('10');
        expect(RingkasDesimal('20')).toBe('20');
        expect(RingkasDesimal('1000')).toBe('1000');
    });

    it('menghapus nol desimal dan titik yang tersisa', () => {
        expect(RingkasDesimal('100.0000')).toBe('100');
        expect(RingkasDesimal('12.5000')).toBe('12.5');
        expect(RingkasDesimal('0.5000')).toBe('0.5');
        expect(RingkasDesimal('10.50')).toBe('10.5');
        expect(RingkasDesimal('7.000')).toBe('7');
    });
});
