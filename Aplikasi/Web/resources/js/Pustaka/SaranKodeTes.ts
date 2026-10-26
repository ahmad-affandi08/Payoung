import { describe, expect, it } from 'vitest';

import { SaranKodeOutlet, SaranKodePemasok } from '@/Pustaka/SaranKode';

describe('Saran kode dari nama', () => {
    it('outlet: aturan sama dengan kode outlet pertama di server (3–5 karakter)', () => {
        expect(SaranKodeOutlet('Raja Sayur')).toBe('RSY');
        expect(SaranKodeOutlet('Toko Utama Solo Baru')).toBe('USB');
        expect(SaranKodeOutlet('Kopi')).toBe('KOPI');
        expect(SaranKodeOutlet('Warung Makan Bu Tini Cabang Solo Baru')).toMatch(/^[A-Z][A-Z0-9]{2,4}$/);
        expect(SaranKodeOutlet('')).toBe('');
        expect(SaranKodeOutlet('12')).toBe('');
    });

    it('pemasok: kata inti digabung "-" dan tidak lebih dari 30 karakter', () => {
        expect(SaranKodePemasok('CV Maju Jaya')).toBe('MAJU-JAYA');
        expect(
            SaranKodePemasok('PT Sumber Pangan Nusantara Sejahtera Abadi Makmur Sentosa').length,
        ).toBeLessThanOrEqual(30);
        expect(SaranKodePemasok('')).toBe('');
    });
});
