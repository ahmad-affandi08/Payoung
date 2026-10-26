import { describe, expect, it } from 'vitest';

import { LabelAkun, SaranAkun } from './SaranAkun';

const beban = [
    { Kode: '6-1000', Nama: 'Beban Gaji & Komisi' },
    { Kode: '6-2000', Nama: 'Beban Sewa, Listrik, Air, Internet' },
    { Kode: '6-4000', Nama: 'Beban Promosi' },
    { Kode: '6-9000', Nama: 'Beban Selisih Kas / Lain-lain' },
];

describe('SaranAkun (audit kemudahan pakai #15)', () => {
    it('kata pada nama kategori memilih akun template yang sesuai', () => {
        expect(SaranAkun('Bayar listrik & air PDAM', beban)?.Kode).toBe('6-2000');
        expect(SaranAkun('Gaji harian karyawan', beban)?.Kode).toBe('6-1000');
        expect(SaranAkun('Cetak brosur promo Lebaran', beban)?.Kode).toBe('6-4000');
    });

    it('tidak dikenal → akun lain-lain. Satu-satunya akun dipakai bila tidak ada lain-lain', () => {
        expect(SaranAkun('Beli es batu & galon', beban)?.Kode).toBe('6-9000');
        expect(SaranAkun('Apa saja', [{ Kode: '4-9000', Nama: 'Pendapatan Lain' }])?.Kode).toBe('4-9000');
        expect(SaranAkun('Apa saja', [{ Kode: '3-1000', Nama: 'Modal Pemilik' }])?.Kode).toBe('3-1000');
        expect(SaranAkun('Apa saja', [])).toBeNull();
    });

    it('label tanpa kode kecuali mode akuntan', () => {
        expect(LabelAkun({ Kode: '6-1000', Nama: 'Beban Gaji & Komisi' }, false)).toBe('Beban Gaji & Komisi');
        expect(LabelAkun({ Kode: '6-1000', Nama: 'Beban Gaji & Komisi' }, true)).toBe('6-1000 Beban Gaji & Komisi');
    });
});
