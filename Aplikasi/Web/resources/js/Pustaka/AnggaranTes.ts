import { describe, expect, it } from 'vitest';

import { AdaAngkaAnggaran, HitungTotalSetahun, SamaAnggaran, SingkatBulan } from '@/Pustaka/Anggaran';

describe('F8 pustaka anggaran', () => {
    it('total setahun menjumlah desimal tanpa float; bulan kosong dihitung nol', () => {
        expect(HitungTotalSetahun(['1000000.10', '', '2000000.20', ...Array<string>(9).fill('')])).toBe('3000000.30');
        expect(HitungTotalSetahun(Array<string>(12).fill('333.33'))).toBe('3999.96');
        expect(HitungTotalSetahun(Array<string>(12).fill(''))).toBe('0');
    });

    it('mendeteksi ada tidaknya angka anggaran dan kesamaan dua daftar bulan', () => {
        expect(AdaAngkaAnggaran(['', '0', '0.00'])).toBe(false);
        expect(AdaAngkaAnggaran(['', '0', '1500'])).toBe(true);
        expect(SamaAnggaran(['1000', ''], ['1000.00', '0'])).toBe(true);
        expect(SamaAnggaran(['1000', ''], ['1000.01', ''])).toBe(false);
        expect(SamaAnggaran(['1000'], ['1000', ''])).toBe(false);
    });

    it('singkatan bulan tiga huruf', () => {
        expect([0, 4, 11].map(SingkatBulan)).toEqual(['Jan', 'Mei', 'Des']);
    });
});
