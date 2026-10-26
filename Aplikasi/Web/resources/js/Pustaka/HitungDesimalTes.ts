import { describe, expect, it } from 'vitest';

import {
    AmbilTandaDesimal,
    BacaDesimal,
    BandingkanDesimal,
    BulatkanDesimal,
    CekDesimalBulat,
    CekDesimalValid,
    CekSkalaMaksimal,
    HitungNilaiBaris,
    HitungTotalNilai,
    JumlahkanDesimal,
    KalikanDesimal,
    KurangiDesimal,
    MutlakDesimal,
    TulisDesimal,
} from './HitungDesimal';

describe('HitungDesimal: aritmetika BigInt tanpa float (CLAUDE.md #7, DesainF05a C.3)', () => {
    it('membaca dan menulis string desimal polos apa adanya', () => {
        expect(BacaDesimal('-12.50')).toEqual({ nilai: -1250n, skala: 2 });
        expect(TulisDesimal(-1250n, 2)).toBe('-12.50');
        expect(TulisDesimal(5n, 4)).toBe('0.0005');
        expect(TulisDesimal(0n, 0)).toBe('0');
        expect(CekDesimalValid('1,5')).toBe(false);
        expect(CekDesimalValid('1e3')).toBe(false);
        expect(CekDesimalValid('')).toBe(false);
        expect(() => BacaDesimal('abc')).toThrow('Desimal tidak valid');
    });

    it('contoh kerja C.3 #1: 10 × 1234.5678 = 12345.68 (HalfUp ke 2 desimal)', () => {
        expect(HitungNilaiBaris('10', '1234.5678')).toBe('12345.68');
        expect(HitungNilaiBaris('10.0000', '1234.567800')).toBe('12345.68');
    });

    it('contoh kerja C.3 #6: 3 nomor seri @ 333.333333 → nilai baris 1000.00', () => {
        expect(HitungNilaiBaris('3', '333.333333')).toBe('1000.00');
        expect(JumlahkanDesimal(['333.33', '333.33', '333.34'])).toBe('1000.00');
    });

    it('HalfUp menjauhi nol di batas setengah, termasuk bilangan negatif', () => {
        expect(BulatkanDesimal('0.005', 2)).toBe('0.01');
        expect(BulatkanDesimal('0.004999', 2)).toBe('0.00');
        expect(BulatkanDesimal('-0.005', 2)).toBe('-0.01');
        expect(BulatkanDesimal('7', 2)).toBe('7.00');
        expect(KalikanDesimal('1.5', '0.25', 2)).toBe('0.38');
        expect(KalikanDesimal('-3', '1234.568', 2)).toBe('-3703.70');
    });

    it('nilai ekstrem: 99.999.999.999.999,9999 × 9.999.999.999.999,999999 tetap tepat tanpa kehilangan digit', () => {
        expect(HitungNilaiBaris('99999999999999.9999', '9999999999999.999999')).toBe('999999999999999998900000000.00');
    });

    it('nilai baris belum valid (sedang diketik) = null. Total melewatinya', () => {
        expect(HitungNilaiBaris('', '1000')).toBeNull();
        expect(HitungNilaiBaris('5', '')).toBeNull();
        expect(
            HitungTotalNilai([
                { Jumlah: '10', HppSatuan: '1000' },
                { Jumlah: '', HppSatuan: '5000' },
                { Jumlah: '5', HppSatuan: '1200' },
            ]),
        ).toBe('16000.00');
        expect(HitungTotalNilai([])).toBe('0.00');
    });

    it('perbandingan, tanda, jumlah, selisih, dan nilai mutlak', () => {
        expect(BandingkanDesimal('12', '12.0000')).toBe(0);
        expect(BandingkanDesimal('-4', '0')).toBe(-1);
        expect(BandingkanDesimal('0.0001', '0')).toBe(1);
        expect(AmbilTandaDesimal('-0.00')).toBe(0);
        expect(AmbilTandaDesimal('-4.0000')).toBe(-1);
        expect(JumlahkanDesimal([])).toBe('0');
        expect(JumlahkanDesimal(['0.1', '0.2'])).toBe('0.3');
        expect(KurangiDesimal('10', '12.5')).toBe('-2.5');
        expect(MutlakDesimal('-3.50')).toBe('3.50');
    });

    it('skala maksimal dan bilangan bulat', () => {
        expect(CekSkalaMaksimal('1.123456', 6)).toBe(true);
        expect(CekSkalaMaksimal('1.1234567', 6)).toBe(false);
        expect(CekDesimalBulat('12.0000')).toBe(true);
        expect(CekDesimalBulat('12.5')).toBe(false);
    });
});
