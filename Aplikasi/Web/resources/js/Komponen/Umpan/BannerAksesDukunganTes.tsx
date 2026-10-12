import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import BannerAksesDukungan from '@/Komponen/Umpan/BannerAksesDukungan';
import type { AksesDukunganAktif } from '@/Tipe/Aplikasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* PGL-16 / P-09 langkah 2: banner merah selama petugas Payoung masuk lewat akses dukungan. */

const sesi: AksesDukunganAktif = {
    NamaPetugas: 'Silvia Suryatmi',
    Cakupan: 'BacaSaja',
    LabelCakupan: 'Baca saja',
    Darurat: false,
    DataPribadiTerbuka: false,
    BerakhirPada: '2026-10-12T07:00:00Z',
};

describe('PGL-16 banner akses dukungan', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola'));
    afterEach(cleanup);

    it('menyebut usaha, petugas, cakupan, dan keadaan data pribadi', () => {
        RenderUji(<BannerAksesDukungan sesi={sesi} namaUsaha="Kopi Nusantara" />);

        expect(screen.getByText(/Anda mengakses akun Kopi Nusantara sebagai Dukungan\./)).toBeTruthy();
        expect(screen.getByText(/Silvia Suryatmi/)).toBeTruthy();
        expect(screen.getByText(/data pribadi pelanggan tersamar/)).toBeTruthy();
        expect(screen.getByRole('alert').getAttribute('data-jenis')).toBe('akses-dukungan');
    });

    it('akses darurat dan data pribadi terbuka diberi tahu jelas', () => {
        RenderUji(
            <BannerAksesDukungan
                sesi={{
                    ...sesi,
                    Darurat: true,
                    DataPribadiTerbuka: true,
                    Cakupan: 'BacaUbah',
                    LabelCakupan: 'Baca & ubah',
                }}
                namaUsaha={null}
            />,
        );

        expect(screen.getByText(/\(akses darurat\)/)).toBeTruthy();
        expect(screen.getByText(/data pribadi pelanggan terbuka/)).toBeTruthy();
    });

    it('Akhiri sesi mengirim POST ke /dukungan/akhiri', () => {
        RenderUji(<BannerAksesDukungan sesi={sesi} namaUsaha="Kopi Nusantara" />);

        fireEvent.click(screen.getByRole('button', { name: 'Akhiri sesi' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith('/dukungan/akhiri', {}, expect.any(Object));
    });
});
