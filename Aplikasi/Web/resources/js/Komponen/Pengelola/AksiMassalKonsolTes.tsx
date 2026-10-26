import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import AksiMassalTiket from '@/Komponen/Pengelola/AksiMassalTiket';
import AksiMassalVerifikasi from '@/Komponen/Pengelola/AksiMassalVerifikasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Konteks<T>(terpilih: T[]) {
    return {
        terpilih,
        semuaHasil: false,
        total: terpilih.length,
        keadaan: { cari: '', urut: [], halaman: 1, perHalaman: 25, saring: {} },
        bersihkan: vi.fn(),
    };
}

describe('Aksi massal konsol', () => {
    beforeEach(() => {
        tiruanRouter.put.mockClear();
        tiruanRouter.post.mockClear();
    });
    afterEach(() => cleanup());

    it('tiket: tandai selesai langsung terkirim. Tutup butuh alasan', () => {
        RenderUji(
            <AksiMassalTiket
                konteks={Konteks([{ Uuid: '01J9TKT0000000000000000001' }, { Uuid: '01J9TKT0000000000000000002' }])}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Tandai selesai' }));
        expect(tiruanRouter.put).toHaveBeenLastCalledWith(
            '/dukungan/tiket/status-massal',
            { Status: 'Selesai', Alasan: null, Uuid: ['01J9TKT0000000000000000001', '01J9TKT0000000000000000002'] },
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Tutup tiket…' }));
        expect((screen.getByRole('button', { name: 'Tutup tiket' }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.change(screen.getByLabelText(/Alasan menutup tiket/), { target: { value: 'Duplikat' } });
        fireEvent.click(screen.getByRole('button', { name: 'Tutup tiket' }));
        expect(tiruanRouter.put).toHaveBeenLastCalledWith(
            '/dukungan/tiket/status-massal',
            expect.objectContaining({ Status: 'Ditutup', Alasan: 'Duplikat' }),
            expect.anything(),
        );
    });

    it('verifikasi: menampilkan total dan baru aktif setelah dicentang sudah dicocokkan', () => {
        RenderUji(
            <AksiMassalVerifikasi
                konteks={Konteks([
                    { Uuid: '01J9PBY0000000000000000001', Jumlah: '220889.00' },
                    { Uuid: '01J9PBY0000000000000000002', Jumlah: '100000.00' },
                ])}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Terima 2 pembayaran…' }));
        expect(screen.getByRole('dialog').textContent).toContain('Rp 320.889');

        const kirim = screen.getByRole('button', { name: 'Terima semua' }) as HTMLButtonElement;
        expect(kirim.disabled).toBe(true);
        fireEvent.click(screen.getByRole('checkbox'));
        expect(kirim.disabled).toBe(false);
        fireEvent.click(kirim);

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/tagihan/pembayaran/terima-massal',
            {
                Uuid: ['01J9PBY0000000000000000001', '01J9PBY0000000000000000002'],
                SudahDicocokkan: true,
                Catatan: '',
            },
            expect.anything(),
        );
    });
});
