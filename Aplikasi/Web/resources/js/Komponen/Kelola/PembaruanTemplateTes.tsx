import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanPembaruanTemplate, { type TawaranPembaruanTemplate } from '@/Halaman/Kelola/Pengaturan/PembaruanTemplate';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* P-03 langkah 6: halaman Pembaruan template usaha menampilkan yang ditambahkan, lalu menerapkan per outlet. */

const tawaran: TawaranPembaruanTemplate = {
    UuidOutlet: '01K5OUTLET0000000000000001',
    NamaOutlet: 'Kopi Nusantara',
    KodeTemplate: 'FNB-CAF',
    NamaTemplate: 'Kafe / kedai kopi',
    VersiSaatIni: 1,
    VersiBaru: 2,
    Perubahan: [
        { Bagian: 'Kategori', Butir: ['Smoothie', 'Roti'] },
        { Bagian: 'Akun', Butir: ['6-2999 Beban Uji'] },
    ],
};

describe('halaman Pembaruan template usaha', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan/pembaruan-template');
    });
    afterEach(cleanup);

    it('tanpa tawaran menjelaskan bahwa semua template sudah terbaru', () => {
        RenderUji(<HalamanPembaruanTemplate Tawaran={[]} />);

        expect(screen.getByText('Semua template sudah terbaru')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Terapkan pembaruan' })).toBeNull();
    });

    it('menampilkan versi dan perubahan per bagian dengan jumlahnya', () => {
        RenderUji(<HalamanPembaruanTemplate Tawaran={[tawaran]} />);

        expect(screen.getByText('Versi 1 menjadi versi 2')).toBeTruthy();
        expect(screen.getByText('Kategori (2)')).toBeTruthy();
        expect(screen.getByText('Smoothie | Roti')).toBeTruthy();
        expect(screen.getByText('6-2999 Beban Uji')).toBeTruthy();
    });

    it('menerapkan lewat dialog konfirmasi ke URL outlet', () => {
        RenderUji(<HalamanPembaruanTemplate Tawaran={[tawaran]} />);

        fireEvent.click(screen.getByRole('button', { name: 'Terapkan pembaruan' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getAllByRole('button', { name: 'Terapkan pembaruan' }).at(-1) as HTMLElement);

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pengaturan/pembaruan-template/01K5OUTLET0000000000000001/terapkan',
            {},
            expect.anything(),
        );
    });

    it('versi baru tanpa tambahan tetap bisa dicatat', () => {
        RenderUji(<HalamanPembaruanTemplate Tawaran={[{ ...tawaran, Perubahan: [] }]} />);

        expect(screen.getByText(/tidak menambah akun, kategori, atau fitur/)).toBeTruthy();
    });
});
