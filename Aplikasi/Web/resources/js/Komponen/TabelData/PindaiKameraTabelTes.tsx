import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';

import TabelData from './TabelData';
import type { KolomTabel } from './Tipe';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const kamera = vi.hoisted(() => ({
    mulai: vi.fn(),
    daftar: vi.fn(),
}));

vi.mock('@/Pustaka/PemindaiKamera', async (impor) => ({
    ...(await impor<typeof import('@/Pustaka/PemindaiKamera')>()),
    MulaiPemindaian: kamera.mulai,
    DaftarKamera: kamera.daftar,
}));

type Baris = { Uuid: string; Nama: string; Barcode: string };

const data: Baris[] = [
    { Uuid: '1', Nama: 'Susu UHT 1 L', Barcode: '8991234500011' },
    { Uuid: '2', Nama: 'Kopi Arabika', Barcode: '8991234500028' },
];

const kolom: KolomTabel<Baris>[] = [
    { accessorKey: 'Nama', header: 'Nama', meta: { label: 'Nama', prioritas: 'utama', wajib: true } },
    { accessorKey: 'Barcode', header: 'Barcode', meta: { label: 'Barcode', prioritas: 'penting' } },
];

function Tampilkan(pindaiKamera: boolean | 'Seri') {
    const klien = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={klien}>
            <TabelData
                id="uji"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'lokal', data }}
                ambilIdBaris={(baris) => baris.Uuid}
                cari="Cari nama atau barcode"
                {...(pindaiKamera ? { pindaiKamera } : {})}
                kosong={{ judul: 'Tidak ada.' }}
            />
        </QueryClientProvider>,
    );
}

describe('TabelData: pindai kamera di kotak cari', () => {
    beforeEach(() => {
        kamera.mulai.mockReset();
        kamera.daftar.mockReset();
        window.history.replaceState({}, '', '/');
    });
    afterEach(() => cleanup());

    it('barcode yang terbaca mengisi kotak cari dan menyaring baris', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        Tampilkan(true);
        fireEvent.click(screen.getByRole('button', { name: 'Pindai barcode untuk mencari di Daftar produk' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('8991234500028');

        await waitFor(() =>
            expect((screen.getByRole('searchbox', { name: 'Cari di Daftar produk' }) as HTMLInputElement).value).toBe(
                '8991234500028',
            ),
        );
        await waitFor(() => expect(screen.queryByText('Susu UHT 1 L')).toBeNull());
        expect(screen.getAllByText('Kopi Arabika').length).toBeGreaterThan(0);
    });

    it('tanpa prop pindaiKamera tidak ada tombol kamera', () => {
        SiapkanKameraTiruan(kamera);
        Tampilkan(false);

        expect(screen.queryByRole('button', { name: /Pindai barcode/ })).toBeNull();
    });

    it("pindaiKamera 'Seri': tombol untuk nomor seri/IMEI memakai jenis kode Seri dan mengisi kotak cari", async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        Tampilkan('Seri');
        expect(screen.queryByRole('button', { name: /Pindai barcode untuk mencari/ })).toBeNull();
        fireEvent.click(
            screen.getByRole('button', { name: 'Pindai nomor seri atau IMEI untuk mencari di Daftar produk' }),
        );
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('356938035643809');

        await waitFor(() =>
            expect((screen.getByRole('searchbox', { name: 'Cari di Daftar produk' }) as HTMLInputElement).value).toBe(
                '356938035643809',
            ),
        );
        expect(tiruan.opsiTerakhir()).toMatchObject({ jenisKode: 'Seri' });
    });
});
