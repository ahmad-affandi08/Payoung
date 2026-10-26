import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanQrMeja from '@/Halaman/Kelola/Outlet/QrMeja';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import BagianMeja from '@/Komponen/Kelola/BagianMeja';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const modeMeja = {
    Aktif: true,
    Area: [],
    Meja: [
        {
            Uuid: 'M-7',
            Nama: '7',
            UuidArea: null,
            NamaArea: null,
            Kapasitas: 4,
            Bentuk: 'Bundar',
            Urutan: 0,
            Status: 'Aktif' as const,
        },
    ],
};

beforeEach(() => AturHalamanUji({}, '/kelola/outlet/O-1'));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('F-17 QR pesan sendiri di halaman outlet', () => {
    it('sakelar: hidupkan mengirim POST, tanpa fitur kanal.self-order tombol nonaktif dengan penjelasan', () => {
        RenderUji(
            <BagianMeja
                alamatOutlet="/kelola/outlet/O-1"
                modeMeja={modeMeja}
                bentuk={[]}
                bolehKelola
                pesanSendiri={{ FiturAktif: true, Aktif: false }}
            />,
        );
        expect(screen.getByText('Mati')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Cetak semua QR meja' }).getAttribute('href')).toBe(
            '/kelola/outlet/O-1/meja/qr',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Hidupkan pesan sendiri' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/outlet/O-1/pesan-sendiri',
            { Aktif: true },
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <BagianMeja
                alamatOutlet="/kelola/outlet/O-1"
                modeMeja={modeMeja}
                bentuk={[]}
                bolehKelola
                pesanSendiri={{ FiturAktif: false, Aktif: false }}
            />,
        );
        expect((screen.getByRole('button', { name: 'Hidupkan pesan sendiri' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
        expect(screen.getByText('Fitur Self-order QR belum aktif')).toBeTruthy();
    });

    it('aksi baris "QR pesan sendiri" memuat QR & URL. Buat ulang minta konfirmasi lalu POST', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        NamaMeja: '7',
                        Url: 'https://payoung.id/kedai-kopi/meja/TokenMejaTujuhAcak32KarakterAbcd',
                        QrSvg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
                    }),
            }),
        );
        RenderUji(
            <BagianMeja
                alamatOutlet="/kelola/outlet/O-1"
                modeMeja={modeMeja}
                bentuk={[]}
                bolehKelola
                pesanSendiri={{ FiturAktif: true, Aktif: true }}
            />,
        );

        fireEvent.keyDown(screen.getByRole('button', { name: 'Aksi meja 7' }), { key: 'Enter' });
        fireEvent.click(screen.getByRole('menuitem', { name: 'QR pesan sendiri' }));
        await waitFor(() => expect(screen.getByRole('img', { name: 'QR pesan sendiri meja 7' })).toBeTruthy());
        expect(screen.getByText('https://payoung.id/kedai-kopi/meja/TokenMejaTujuhAcak32KarakterAbcd')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Buat ulang QR' }));
        expect(screen.getByText(/QR lama di meja 7 langsung tidak bisa dipakai/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Buat ulang QR' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/outlet/O-1/meja/M-7/qr/buat-ulang',
            {},
            expect.anything(),
        );
    });

    it('tanpa izin kelola: QR tetap bisa dilihat, tanpa sakelar & tanpa buat ulang', () => {
        RenderUji(
            <BagianMeja
                alamatOutlet="/kelola/outlet/O-1"
                modeMeja={modeMeja}
                bentuk={[]}
                bolehKelola={false}
                pesanSendiri={{ FiturAktif: true, Aktif: true }}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Matikan pesan sendiri' })).toBeNull();
        fireEvent.keyDown(screen.getByRole('button', { name: 'Aksi meja 7' }), { key: 'Enter' });
        expect(screen.getByRole('menuitem', { name: 'QR pesan sendiri' })).toBeTruthy();
        expect(screen.queryByRole('menuitem', { name: 'Arsipkan' })).toBeNull();
    });

    it('halaman cetak QR: kartu per meja aktif dengan nama meja & URL. Peringatan bila belum aktif', () => {
        RenderUji(
            <HalamanQrMeja
                Outlet={{ Uuid: 'O-1', Kode: 'SLO1', Nama: 'Kedai Solo Baru' }}
                NamaUsaha="Kedai Kopi Senja"
                PesanSendiriAktif={false}
                Meja={[
                    {
                        Uuid: 'M-7',
                        Nama: '7',
                        NamaArea: 'Teras',
                        Url: 'https://payoung.id/k/meja/A',
                        QrSvg: '<svg></svg>',
                    },
                    {
                        Uuid: 'M-9',
                        Nama: '9',
                        NamaArea: null,
                        Url: 'https://payoung.id/k/meja/B',
                        QrSvg: '<svg></svg>',
                    },
                ]}
            />,
        );
        expect(screen.getByRole('listitem', { name: 'QR meja 7' })).toBeTruthy();
        expect(screen.getByText('Meja 9')).toBeTruthy();
        expect(screen.getByText('https://payoung.id/k/meja/B')).toBeTruthy();
        expect(screen.getByText('Pesan sendiri belum aktif')).toBeTruthy();
    });
});
