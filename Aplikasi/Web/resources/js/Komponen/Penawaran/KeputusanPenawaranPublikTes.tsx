import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import KeputusanPenawaranPublik from '@/Komponen/Penawaran/KeputusanPenawaranPublik';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

describe('Keputusan penawaran oleh pelanggan (halaman publik)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('tombol terima dan tolak nonaktif sampai nama penyetuju (minimal 2 huruf) diisi', () => {
        RenderUji(<KeputusanPenawaranPublik kode="1.abcdefghijklmnopqrstuvwxyz012345" />);

        const terima = screen.getByRole('button', { name: 'Terima penawaran' }) as HTMLButtonElement;
        const tolak = screen.getByRole('button', { name: 'Tolak penawaran' }) as HTMLButtonElement;
        expect(terima.disabled).toBe(true);
        expect(tolak.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Nama Anda/), { target: { value: 'B' } });
        expect(terima.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Nama Anda/), { target: { value: 'Budi Santoso' } });
        expect(terima.disabled).toBe(false);
        expect(tolak.disabled).toBe(false);
    });

    it('mengirim keputusan beserta nama dan catatan ke rute publik', () => {
        RenderUji(<KeputusanPenawaranPublik kode="1.abcdefghijklmnopqrstuvwxyz012345" />);

        fireEvent.change(screen.getByLabelText(/Nama Anda/), { target: { value: ' Budi Santoso ' } });
        fireEvent.change(screen.getByLabelText(/Catatan untuk penjual/), { target: { value: 'Mulai Oktober.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Terima penawaran' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/p/1.abcdefghijklmnopqrstuvwxyz012345/putuskan',
            { Keputusan: 'Terima', Nama: 'Budi Santoso', Catatan: 'Mulai Oktober.' },
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Tolak penawaran' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/p/1.abcdefghijklmnopqrstuvwxyz012345/putuskan',
            { Keputusan: 'Tolak', Nama: 'Budi Santoso', Catatan: 'Mulai Oktober.' },
            expect.anything(),
        );
    });

    it('masalah dari server tampil sebagai pesan yang terbaca', () => {
        AturHalamanUji({ Umum: 'Masa berlaku penawaran ini sudah lewat.' });
        RenderUji(<KeputusanPenawaranPublik kode="1.abcdefghijklmnopqrstuvwxyz012345" />);

        expect(screen.getByText('Masa berlaku penawaran ini sudah lewat.')).toBeTruthy();
    });
});
