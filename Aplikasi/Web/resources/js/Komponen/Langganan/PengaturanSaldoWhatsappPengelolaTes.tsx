import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, kirimanForm } from '@/Komponen/Katalog/TiruanInertia';
import { PanelSaldoWhatsapp } from '@/Komponen/Langganan/PengaturanSaldoWhatsappPengelola';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* D-300: pengaturan harga per pesan broadcast dan minimal isi saldo di konsol (izin tagihan.pengaturan). */

describe('PanelSaldoWhatsapp konsol (D-300)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/tagihan');
        kirimanForm.length = 0;
    });
    afterEach(() => cleanup());

    it('Super Admin mengubah harga dan minimal lewat PUT dengan alasan', () => {
        render(<PanelSaldoWhatsapp pengaturan={{ HargaPerPesan: '0.00', TopUpMinimal: '10000.00' }} boleh />);

        expect(screen.getByText('gratis')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Ubah harga dan minimal isi' }));
        const dialog = screen.getByRole('dialog', { name: 'Ubah pengaturan saldo WhatsApp' });
        fireEvent.change(within(dialog).getByLabelText(/Harga per pesan broadcast/), { target: { value: '175' } });
        fireEvent.change(within(dialog).getByLabelText(/Alasan perubahan/), {
            target: { value: 'Menutup biaya penyedia' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan pengaturan' }));

        expect(kirimanForm[0]).toMatchObject({
            metode: 'put',
            url: '/tagihan/pengaturan/saldo-whatsapp',
            data: { HargaPerPesan: '175', TopUpMinimal: '10000', Alasan: 'Menutup biaya penyedia' },
        });
    });

    it('peran lain hanya melihat nilai tanpa tombol ubah', () => {
        render(<PanelSaldoWhatsapp pengaturan={{ HargaPerPesan: '150.00', TopUpMinimal: '25000.00' }} boleh={false} />);

        expect(screen.getByText(/Rp\s?150 per pesan/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Ubah harga dan minimal isi' })).toBeNull();
    });
});
