import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
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

    describe('D-338 Bayar QRIS dari HP', () => {
        const gerbang = {
            Kode: 'GerbangBelumAktif',
            Pesan: 'Gerbang pembayaran toko belum aktif.',
            Tautan: '/kelola/pembayaran/gerbang',
        };
        const metode = {
            Kode: 'MetodeQrisBelumAktif',
            Pesan: 'Belum ada metode QRIS dinamis yang aktif.',
            Tautan: '/kelola/panduan-awal/metode-pembayaran',
        };
        const Tampil = (qris: { Aktif: boolean; Hambatan: (typeof gerbang)[]; BolehUbah: boolean } | undefined) =>
            RenderUji(
                <BagianMeja
                    alamatOutlet="/kelola/outlet/O-1"
                    modeMeja={modeMeja}
                    bentuk={[]}
                    bolehKelola
                    pesanSendiri={{ FiturAktif: true, Aktif: true }}
                    pesanSendiriQris={qris}
                />,
            );

        it('belum siap: sebab ditulis dengan tautan perbaikannya, dan tombol hidupkan nonaktif', () => {
            Tampil({ Aktif: false, Hambatan: [gerbang, metode], BolehUbah: true });

            expect(screen.getByRole('heading', { name: 'Bayar QRIS dari HP' })).toBeTruthy();
            expect(screen.getByText('Siapkan dulu sebelum menghidupkan')).toBeTruthy();
            expect(screen.getByText(/Gerbang pembayaran toko belum aktif/)).toBeTruthy();
            expect(screen.getAllByRole('link', { name: 'Buka halamannya' }).map((a) => a.getAttribute('href'))).toEqual(
                ['/kelola/pembayaran/gerbang', '/kelola/panduan-awal/metode-pembayaran'],
            );
            expect((screen.getByRole('button', { name: 'Hidupkan QRIS dari HP' }) as HTMLButtonElement).disabled).toBe(
                true,
            );
        });

        it('siap: tombol hidupkan mengirim POST ke rute QRIS. Setelah aktif tersedia tombol matikan', () => {
            Tampil({ Aktif: false, Hambatan: [], BolehUbah: true });
            fireEvent.click(screen.getByRole('button', { name: 'Hidupkan QRIS dari HP' }));
            expect(tiruanRouter.post).toHaveBeenCalledWith(
                '/kelola/outlet/O-1/pesan-sendiri/qris',
                { Aktif: true },
                expect.anything(),
            );
            cleanup();

            Tampil({ Aktif: true, Hambatan: [], BolehUbah: true });
            expect(within(screen.getByRole('region', { name: 'Bayar QRIS dari HP' })).getByText('Aktif')).toBeTruthy();
            fireEvent.click(screen.getByRole('button', { name: 'Matikan QRIS dari HP' }));
            expect(tiruanRouter.post).toHaveBeenCalledWith(
                '/kelola/outlet/O-1/pesan-sendiri/qris',
                { Aktif: false },
                expect.anything(),
            );
        });

        it('aktif tetapi belum siap diperingatkan. Tanpa izin toko online tidak ada tombol', () => {
            Tampil({ Aktif: true, Hambatan: [metode], BolehUbah: false });

            expect(screen.getByText('Aktif, belum siap')).toBeTruthy();
            expect(screen.getByText('QRIS aktif tetapi belum bisa dipakai tamu')).toBeTruthy();
            expect(screen.queryByRole('button', { name: /QRIS dari HP/ })).toBeNull();
            expect(screen.getByText(/Hanya pengguna berizin toko online/)).toBeTruthy();
        });

        it('tanpa data QRIS dari server (halaman lama) bagian ini tidak tampil', () => {
            Tampil(undefined);

            expect(screen.queryByRole('heading', { name: 'Bayar QRIS dari HP' })).toBeNull();
        });
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
