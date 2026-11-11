import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import AksiMassalSederhana from '@/Komponen/TabelData/AksiMassalSederhana';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

type Baris = { Uuid: string };

function Konteks(uuid: string[]) {
    return {
        terpilih: uuid.map((u) => ({ Uuid: u })),
        semuaHasil: false,
        total: uuid.length,
        keadaan: { cari: '', urut: [], halaman: 1, perHalaman: 25, saring: {} },
        bersihkan: vi.fn(),
    };
}

const Tombol = [
    { aksi: 'Aktifkan', label: 'Aktifkan' },
    { aksi: 'Nonaktifkan', label: 'Nonaktifkan', varian: 'bahaya' as const },
];

describe('AksiMassalSederhana', () => {
    beforeEach(() => tiruanRouter.post.mockClear());
    afterEach(() => cleanup());

    it('mengirim aksi dan Uuid terpilih ke alamat', () => {
        RenderUji(
            <AksiMassalSederhana<Baris>
                konteks={Konteks(['01J9AAA0000000000000000001', '01J9AAA0000000000000000002'])}
                alamat="/kelola/promo/massal"
                ambilUuid={(b) => b.Uuid}
                tombol={Tombol}
                maksimal={200}
                objek="promo"
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/promo/massal',
            { Aksi: 'Nonaktifkan', Uuid: ['01J9AAA0000000000000000001', '01J9AAA0000000000000000002'] },
            expect.anything(),
        );
    });

    it('tombol dengan `berlaku` hanya tampil bila ada baris terpilih yang bisa dikenai aksinya', () => {
        type BarisStatus = { Uuid: string; Status: 'Aktif' | 'Nonaktif' };
        const tombol = [
            { aksi: 'Aktifkan', label: 'Aktifkan', berlaku: (b: BarisStatus) => b.Status === 'Nonaktif' },
            {
                aksi: 'Nonaktifkan',
                label: 'Nonaktifkan',
                varian: 'bahaya' as const,
                berlaku: (b: BarisStatus) => b.Status === 'Aktif',
            },
        ];
        const Pasang = (status: BarisStatus['Status'][]) =>
            RenderUji(
                <AksiMassalSederhana<BarisStatus>
                    konteks={{
                        ...Konteks([]),
                        terpilih: status.map((Status, i) => ({ Uuid: `U${String(i)}`, Status })),
                    }}
                    alamat="/kelola/voucher/massal"
                    ambilUuid={(b) => b.Uuid}
                    tombol={tombol}
                    maksimal={200}
                    objek="voucher"
                />,
            );

        const semuaAktif = Pasang(['Aktif', 'Aktif']);
        expect(screen.queryByRole('button', { name: 'Aktifkan' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Nonaktifkan' })).toBeTruthy();
        semuaAktif.unmount();

        const campuran = Pasang(['Aktif', 'Nonaktif']);
        expect(screen.getByRole('button', { name: 'Aktifkan' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Nonaktifkan' })).toBeTruthy();
        campuran.unmount();

        Pasang(['Nonaktif']);
        expect(screen.getByRole('button', { name: 'Aktifkan' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Nonaktifkan' })).toBeNull();
    });

    it('menolak pilihan melebihi batas dengan penjelasan', () => {
        RenderUji(
            <AksiMassalSederhana<Baris>
                konteks={Konteks(['01J9AAA0000000000000000001', '01J9AAA0000000000000000002'])}
                alamat="/kelola/promo/massal"
                ambilUuid={(b) => b.Uuid}
                tombol={Tombol}
                maksimal={1}
                objek="voucher"
            />,
        );

        expect(screen.getByText(/Maksimal 1 voucher sekali proses/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Aktifkan' }) as HTMLButtonElement).disabled).toBe(true);
    });
});
