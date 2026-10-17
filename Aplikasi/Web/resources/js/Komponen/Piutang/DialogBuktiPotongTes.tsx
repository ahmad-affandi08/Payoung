import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import DialogBuktiPotong from '@/Komponen/Piutang/DialogBuktiPotong';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

describe('Dialog nomor bukti potong pelunasan', () => {
    beforeEach(() => tiruanRouter.post.mockClear());
    afterEach(() => cleanup());

    it('tombol simpan nonaktif selama nomor kosong lalu mengirim nomor ke alamat pelunasan', () => {
        const Tutup = vi.fn();
        RenderUji(
            <DialogBuktiPotong
                nomorPelunasan="BP/2610/0002"
                alamat="/kelola/piutang/pelunasan/01J9PELUNASAN0000000000001/bukti-potong"
                nomorAwal={null}
                saatTutup={Tutup}
            />,
        );

        const simpan = screen.getByRole('button', { name: 'Simpan nomor' }) as HTMLButtonElement;
        expect(simpan.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Nomor bukti potong/), { target: { value: '0011-BP23-2026' } });
        expect(simpan.disabled).toBe(false);
        fireEvent.click(simpan);

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/piutang/pelunasan/01J9PELUNASAN0000000000001/bukti-potong',
            { NomorBuktiPotong: '0011-BP23-2026' },
            expect.anything(),
        );
    });

    it('memuat nomor yang sudah ada supaya bisa diperbaiki', () => {
        RenderUji(
            <DialogBuktiPotong
                nomorPelunasan="BP/2610/0002"
                alamat="/x"
                nomorAwal="0007-BP23-2026"
                saatTutup={vi.fn()}
            />,
        );

        expect((screen.getByLabelText(/Nomor bukti potong/) as HTMLInputElement).value).toBe('0007-BP23-2026');
    });
});
