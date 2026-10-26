import { describe, expect, it } from 'vitest';

import { AmbilNamaBerkasIlustrasi, AmbilTampilanSektor, KelompokkanSektor } from '@/Pustaka/TampilanSektor';

describe('Tampilan sektor (ilustrasi & kelompok pemilih jenis usaha, D-178)', () => {
    it('kode dikenal memakai ilustrasinya sendiri. Kode baru jatuh ke ilustrasi awalannya. Kode asing ke umum', () => {
        const kafe = AmbilTampilanSektor('FNB-CAF');
        const laundry = AmbilTampilanSektor('SVC-LDR');
        expect(kafe.Kelompok).toBe('Makanan');
        expect(kafe.Ilustrasi).toMatch(/FnbCaf/);
        expect(laundry).toMatchObject({ Kelompok: 'Jasa' });
        expect(laundry.Ilustrasi).toMatch(/SvcLdr/);
        expect(AmbilTampilanSektor('WHS-DST')).toMatchObject({ Kelompok: 'Retail' });

        // Template baru dari konsol yang belum punya ilustrasi: ilustrasi awalannya, tidak pernah kosong.
        const baru = AmbilTampilanSektor('RTL-BARU');
        expect(baru.Kelompok).toBe('Retail');
        expect(baru.Ilustrasi).toMatch(/RtlGen/);
        const asing = AmbilTampilanSektor('XYZ-1');
        expect(asing.Kelompok).toBe('Lainnya');
        expect(asing.Ilustrasi).not.toBe('');
        expect(AmbilTampilanSektor('').Ilustrasi).not.toBe('');
    });

    it('setiap kode template sektor bawaan punya ilustrasi sendiri', () => {
        const kode = [
            'FNB-CAF',
            'FNB-QSR',
            'FNB-RST',
            'FNB-BAK',
            'RTL-GEN',
            'RTL-FRS',
            'RTL-FSH',
            'RTL-ELC',
            'RTL-BLD',
            'RTL-PHR',
            'RTL-PET',
            'RTL-FLR',
            'WHS-DST',
            'SVC-SLN',
            'SVC-LDR',
            'SVC-WRK',
            'SVC-FIT',
            'SVC-CWS',
            'SVC-GEN',
            'BIZ-JSA',
            'BIZ-KTR',
            'BIZ-MFG',
        ];
        for (const k of kode) {
            expect(AmbilTampilanSektor(k).Ilustrasi, k).toContain(AmbilNamaBerkasIlustrasi(k));
        }
    });

    it('mengelompokkan dengan urutan kelompok tetap, urutan server dipertahankan, kelompok kosong dibuang', () => {
        const daftar = [
            { Kode: 'SVC-SLN' },
            { Kode: 'FNB-RST' },
            { Kode: 'RTL-GEN' },
            { Kode: 'FNB-CAF' },
            { Kode: 'BIZ-JSA' },
        ];

        const grup = KelompokkanSektor(daftar);

        expect(grup.map((g) => g.Kelompok)).toEqual(['Makanan', 'Retail', 'Jasa', 'Bisnis']);
        expect(grup[0]?.Item.map((i) => i.Kode)).toEqual(['FNB-RST', 'FNB-CAF']);
        expect(KelompokkanSektor([])).toEqual([]);
    });
});
