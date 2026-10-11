import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import AksiMassalPengguna from '@/Komponen/Organisasi/AksiMassalPengguna';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type { OpsiPeranPengguna } from '@/Tipe/Organisasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Peran: OpsiPeranPengguna[] = [
    { Uuid: '01J9PRN0000000000000000001', Nama: 'Kasir', Pemilik: false, Cakupan: 'Terpilih' as const },
];

function Konteks(baris: { Uuid: string; Nama: string }[]) {
    return {
        terpilih: baris,
        semuaHasil: false,
        total: baris.length,
        keadaan: { cari: '', urut: [], halaman: 1, perHalaman: 25, saring: {} },
        bersihkan: vi.fn(),
    };
}

describe('Aksi massal pengguna', () => {
    beforeEach(() => tiruanRouter.post.mockClear());
    afterEach(() => cleanup());

    it('nonaktifkan dan ganti peran mengirim Uuid terpilih', () => {
        RenderUji(
            <AksiMassalPengguna
                konteks={Konteks([
                    { Uuid: '01J9PGN0000000000000000001', Nama: 'Budi' },
                    { Uuid: '01J9PGN0000000000000000002', Nama: 'Sari' },
                ])}
                peran={Peran}
                bolehSentuh={() => true}
                bolehUbah
                bolehNonaktifkan
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/pengguna/massal',
            {
                Aksi: 'Nonaktifkan',
                Uuid: ['01J9PGN0000000000000000001', '01J9PGN0000000000000000002'],
                UuidPeran: null,
            },
            expect.anything(),
        );

        expect((screen.getByRole('button', { name: 'Ganti peran' }) as HTMLButtonElement).disabled).toBe(true);
        PilihOpsi(screen.getByRole('combobox', { name: 'Peran baru' }), '01J9PRN0000000000000000001');
        fireEvent.click(screen.getByRole('button', { name: 'Ganti peran' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/pengguna/massal',
            expect.objectContaining({ Aksi: 'Peran', UuidPeran: '01J9PRN0000000000000000001' }),
            expect.anything(),
        );
    });

    it('mengunci aksi bila pilihan memuat akun yang tidak boleh diubah dan menyebut namanya', () => {
        RenderUji(
            <AksiMassalPengguna
                konteks={Konteks([
                    { Uuid: '01J9PGN0000000000000000001', Nama: 'Budi' },
                    { Uuid: '01J9PGN0000000000000000009', Nama: 'Saya Sendiri' },
                ])}
                peran={Peran}
                bolehSentuh={(b) => b.Nama !== 'Saya Sendiri'}
                bolehUbah={false}
                bolehNonaktifkan
            />,
        );

        expect(screen.getByText(/Saya Sendiri/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Nonaktifkan' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Ganti peran' })).toBeNull();
    });
});
