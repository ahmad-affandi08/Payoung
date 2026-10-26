import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DialogPembayaranInvoice from '@/Komponen/Invoice/DialogPembayaranInvoice';
import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

describe('Dialog pembayaran invoice', () => {
    afterEach(() => cleanup());

    const Render = () =>
        RenderUji(
            <DialogPembayaranInvoice
                uuidInvoice="01J9INV0000000000000000001"
                nomor="INV/2609/0001"
                sisa="7500000.00"
                kodePph={null}
                saranPph="0.00"
                opsiAkun={[{ Uuid: 'AKUN-1', Kode: '1-1200', Nama: 'Bank BCA' }]}
                hariIni="2026-09-27"
                saatTutup={vi.fn()}
            />,
        );

    it('jumlah dilunasi langsung terisi sisa tagihan, tidak perlu dikosongkan', () => {
        tiruanRouter.post.mockClear();
        Render();

        expect((screen.getByLabelText(/Jumlah dilunasi/) as HTMLInputElement).value).toMatch(/7\.500\.000/);

        fireEvent.click(screen.getByRole('button', { name: 'Catat pembayaran' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/invoice/01J9INV0000000000000000001/pembayaran',
            expect.objectContaining({ Jumlah: '7500000' }),
            expect.anything(),
        );
    });
});
