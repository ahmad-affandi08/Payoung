import { describe, expect, it } from 'vitest';

import { CekSesuaiSektor } from './Sektor';

describe('CekSesuaiSektor', () => {
    it('sektor belum diketahui: semua butir tampil', () => {
        expect(CekSesuaiSektor({ sektor: ['SVC-WRK'] }, [])).toBe(true);
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ'] }, [])).toBe(true);
    });

    it('butir khusus sektor tampil hanya bila ada sektor berawalan itu (per segmen)', () => {
        expect(CekSesuaiSektor({ sektor: ['SVC'] }, ['SVC-WRK'])).toBe(true);
        expect(CekSesuaiSektor({ sektor: ['SVC-WRK'] }, ['SVC-SLN'])).toBe(false);
        expect(CekSesuaiSektor({ sektor: ['BIZ-MFG'] }, ['BIZ-MFG'])).toBe(true);
    });

    it('D-167: kecualiSektor menyembunyikan butir kasir bila SEMUA sektor usaha Perusahaan', () => {
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ'] }, ['BIZ-JSA'])).toBe(false);
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ'] }, ['BIZ-JSA', 'BIZ-KTR'])).toBe(false);
    });

    it('D-167: usaha campuran (Perusahaan + toko) tetap melihat butir kasir', () => {
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ'] }, ['BIZ-JSA', 'RTL-GEN'])).toBe(true);
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ'] }, ['RTL-GEN'])).toBe(true);
    });

    it('kecualiSektor sempit hanya menyembunyikan sektor yang disebut (manufaktur tetap melihat Grosir)', () => {
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ-JSA', 'BIZ-KTR'] }, ['BIZ-MFG'])).toBe(true);
        expect(CekSesuaiSektor({ kecualiSektor: ['BIZ-JSA', 'BIZ-KTR'] }, ['BIZ-KTR'])).toBe(false);
    });
});
