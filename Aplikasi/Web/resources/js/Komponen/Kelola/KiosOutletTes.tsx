import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import KiosOutlet from '@/Komponen/Kelola/KiosOutlet';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

beforeEach(() => AturHalamanUji({}, '/kelola/outlet/O-1'));
afterEach(() => cleanup());

const alamat = '/kelola/outlet/O-1';

describe('Pengaturan kios pesan sendiri di halaman outlet (F-17 bagian 4)', () => {
    it('kios mati: hidupkan mengirim POST, tanpa fitur self-order tombol nonaktif dengan penjelasan', () => {
        RenderUji(
            <KiosOutlet
                alamatOutlet={alamat}
                bolehKelola
                data={{ FiturAktif: true, Aktif: false, Tautan: null, TautanAntrian: null, QrisTersedia: false }}
            />,
        );
        expect(screen.getByText('Mati')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Hidupkan kios' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(`${alamat}/kios`, { Aktif: true }, expect.anything());
        cleanup();

        RenderUji(
            <KiosOutlet
                alamatOutlet={alamat}
                bolehKelola
                data={{ FiturAktif: false, Aktif: false, Tautan: null, TautanAntrian: null, QrisTersedia: false }}
            />,
        );
        expect(screen.getByRole('button', { name: 'Hidupkan kios' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getByText('Fitur Self-order belum aktif')).toBeTruthy();
    });

    it('kios aktif: tautan kios & layar antrian tampil, buat ulang meminta konfirmasi, QRIS dijelaskan', () => {
        RenderUji(
            <KiosOutlet
                alamatOutlet={alamat}
                bolehKelola
                data={{
                    FiturAktif: true,
                    Aktif: true,
                    Tautan: 'https://dashboard.payoung.id/toko/kios/TOKEN',
                    TautanAntrian: 'https://dashboard.payoung.id/toko/kios/TOKEN/antrian',
                    QrisTersedia: false,
                }}
            />,
        );

        expect(screen.getByText('Aktif')).toBeTruthy();
        expect(screen.getByText('https://dashboard.payoung.id/toko/kios/TOKEN')).toBeTruthy();
        expect(screen.getByText('https://dashboard.payoung.id/toko/kios/TOKEN/antrian')).toBeTruthy();
        expect(screen.getByText('QRIS di kios belum tersedia')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Buat ulang tautan' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(screen.getByText(/Tautan kios dan layar antrian yang lama berhenti bekerja/)).toBeTruthy();
    });

    it('pengguna tanpa izin kelola hanya melihat, tanpa tombol aksi', () => {
        RenderUji(
            <KiosOutlet
                alamatOutlet={alamat}
                bolehKelola={false}
                data={{
                    FiturAktif: true,
                    Aktif: true,
                    Tautan: 'https://x/k/T',
                    TautanAntrian: 'https://x/k/T/antrian',
                    QrisTersedia: true,
                }}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Matikan kios' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Buat ulang tautan' })).toBeNull();
    });
});
