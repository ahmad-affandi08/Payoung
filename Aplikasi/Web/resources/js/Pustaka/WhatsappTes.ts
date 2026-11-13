import { describe, expect, it } from 'vitest';

import { CekButirWhatsappTampil, LabelTanpaWhatsapp } from '@/Pustaka/Whatsapp';

describe('D-299 pustaka WhatsApp', () => {
    it('butir menu WhatsApp hilang bila konsol belum mengaktifkan layanan, butir lain tidak terpengaruh', () => {
        expect(CekButirWhatsappTampil('integrasi.whatsapp', false)).toBe(false);
        expect(CekButirWhatsappTampil('integrasi.whatsapp', true)).toBe(true);
        expect(CekButirWhatsappTampil('promo.mesin', false)).toBe(true);
        expect(CekButirWhatsappTampil(undefined, false)).toBe(true);
    });

    it('label dari server dinetralkan dari kata WhatsApp bila layanan tidak tersedia', () => {
        expect(LabelTanpaWhatsapp('Terbitkan dan kirim WhatsApp', false)).toBe('Terbitkan');
        expect(LabelTanpaWhatsapp('Terbitkan dan kirim WhatsApp', true)).toBe('Terbitkan dan kirim WhatsApp');
        expect(LabelTanpaWhatsapp('Hanya buat draf', false)).toBe('Hanya buat draf');
    });
});
