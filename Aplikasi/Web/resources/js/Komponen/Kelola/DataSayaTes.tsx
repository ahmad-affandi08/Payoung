import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDataSaya from '@/Halaman/Kelola/Pengaturan/DataSaya';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* E5: halaman Data saya meminta ekspor data usaha (UU PDP) dan menampilkan riwayatnya. */

const baris = {
    Uuid: '01K5EKSPOR00000000000000A1',
    Status: 'Siap' as const,
    LabelStatus: 'Siap diunduh',
    UkuranByte: 2048,
    JumlahTabel: 12,
    JumlahBaris: 1500,
    DibuatPada: '2026-10-12T03:00:00Z',
    KedaluwarsaPada: '2026-10-19T03:00:00Z',
    DiunduhPada: null,
    Galat: null,
    BisaDiunduh: true,
};

describe('E5 halaman Data saya', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan/data-saya');
        kirimanForm.length = 0;
    });
    afterEach(cleanup);

    it('tanpa riwayat menampilkan keadaan kosong dan tombol minta ekspor mengirim POST', () => {
        RenderUji(<HalamanDataSaya Ekspor={[]} />);

        expect(screen.getByText(/Belum ada ekspor/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Minta ekspor data' }));

        expect(kirimanForm.map((kiriman) => [kiriman.metode, kiriman.url])).toEqual([
            ['post', '/kelola/pengaturan/data-saya/ekspor'],
        ]);
    });

    it('ekspor siap menampilkan ringkasan dan tombol unduh; ekspor berjalan mengunci tombol minta', () => {
        RenderUji(<HalamanDataSaya Ekspor={[baris]} />);

        expect(screen.getByText('Siap diunduh')).toBeTruthy();
        expect(screen.getByText(/12 berkas/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Unduh' })).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanDataSaya
                Ekspor={[{ ...baris, Status: 'Berjalan', LabelStatus: 'Sedang disiapkan', BisaDiunduh: false }]}
            />,
        );
        const tombol = screen.getByRole('button', { name: 'Minta ekspor data' }) as HTMLButtonElement;

        expect(tombol.disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Unduh' })).toBeNull();
    });
});
