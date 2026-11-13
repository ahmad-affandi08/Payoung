import { cleanup, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarReturGrosir from '@/Halaman/Kelola/Grosir/Retur/Daftar';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { IzinGrosir, PropsDaftarRetur } from '@/Tipe/Grosir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-288: retur grosir bisa dimulai dari daftarnya. Tombol "Buat retur" membuka halaman pilih surat jalan, jadi
 * pengguna tidak perlu mencari surat jalannya dulu di daftar lain.
 */

const izinKelola: IzinGrosir = { Kelola: true, SetujuiKredit: false, LihatJurnal: false, LihatPiutang: true };

function Props(izin: IzinGrosir): PropsDaftarRetur {
    return {
        Retur: { Data: [], Meta: { Halaman: 1, PerHalaman: 25, Total: 0, JumlahHalaman: 1 } },
        OpsiStatus: [],
        Izin: izin,
    };
}

beforeEach(() => {
    AturHalamanUji({}, '/kelola/grosir/retur');
});

afterEach(cleanup);

describe('daftar retur grosir', () => {
    it('pengelola melihat tombol Buat retur yang menuju halaman pilih surat jalan', () => {
        RenderUji(<HalamanDaftarReturGrosir {...Props(izinKelola)} />);

        const tautan = screen.getAllByRole('link', { name: 'Buat retur' });

        expect(tautan.length).toBeGreaterThan(0);
        expect(tautan[0]?.getAttribute('href')).toBe('/kelola/grosir/retur/buat');
    });

    it('tanpa izin kelola tidak ada tombol Buat retur', () => {
        RenderUji(<HalamanDaftarReturGrosir {...Props({ ...izinKelola, Kelola: false })} />);

        expect(screen.queryByRole('link', { name: 'Buat retur' })).toBeNull();
    });

    it('keadaan kosong menunjuk ke tombol Buat retur, bukan ke halaman surat jalan', () => {
        RenderUji(<HalamanDaftarReturGrosir {...Props(izinKelola)} />);

        expect(screen.getByText(/Tekan Buat retur, lalu pilih surat jalan/)).toBeTruthy();
    });
});
