import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanGantiSektor, { type PratinjauGantiSektor } from '@/Halaman/Kelola/Pengaturan/GantiSektor';
import { AturHalamanUji, TambahPropsHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const outlet = [
    { Uuid: '01J0000000000000000000OUT1', Nama: 'Kopi Nusantara', KodeTemplate: 'FNB-CAF', NamaTemplate: 'Kafe' },
];
const template = [
    { Kode: 'FNB-CAF', Nama: 'Kafe', Keterangan: null },
    { Kode: 'RTL-GEN', Nama: 'Retail umum', Keterangan: null },
];

function BuatPratinjau(ubah: Partial<PratinjauGantiSektor> = {}): PratinjauGantiSektor {
    return {
        UuidOutlet: outlet[0]?.Uuid ?? '',
        NamaOutlet: 'Kopi Nusantara',
        Lama: { Kode: 'FNB-CAF', Nama: 'Kafe', Versi: 1 },
        Baru: {
            Kode: 'RTL-GEN',
            Nama: 'Retail umum',
            Versi: 1,
            JumlahAkun: 50,
            JumlahKategori: 6,
            JumlahFitur: 7,
            ModeKasir: ['Retail (scan barcode)'],
        },
        Dibersihkan: {
            Akun: ['4-1010 Penjualan Makanan'],
            Kategori: ['Kopi', 'Teh'],
            StasiunDapur: ['Bar'],
            Fitur: ['Kitchen display (KDS)'],
        },
        Dipertahankan: [{ Jenis: 'Kategori', Nama: 'Makanan', Alasan: 'Kategori ini masih dipakai 3 produk.' }],
        ...ubah,
    };
}

describe('Pengaturan › Ganti jenis usaha (D-165)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pengaturan/ganti-sektor');
    });

    afterEach(() => {
        cleanup();
    });

    it('tanpa pilihan lengkap hanya menampilkan pilihan outlet dan jenis usaha, tanpa tombol ganti', () => {
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={null} />);

        expect(screen.getByText('Pilih outlet dan jenis usaha baru')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Ganti jenis usaha' })).toBeNull();
    });

    it('pratinjau menampilkan yang ditambahkan, dibersihkan, dan dipertahankan beserta alasannya', () => {
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={BuatPratinjau()} />);

        expect(screen.getByText('Kafe menjadi Retail umum')).toBeTruthy();
        expect(screen.getByText(/50 akun/)).toBeTruthy();
        expect(screen.getByText('4-1010 Penjualan Makanan')).toBeTruthy();
        expect(screen.getByText('Kopi | Teh')).toBeTruthy();
        expect(screen.getByText(/Makanan: Kategori ini masih dipakai 3 produk\./)).toBeTruthy();
    });

    it('terapkan lewat dialog konfirmasi mengirim outlet, jenis usaha, dan pilihan bersihkan', () => {
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={BuatPratinjau()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ganti jenis usaha' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Ganti jenis usaha' }).at(-1) as HTMLElement);

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pengaturan/ganti-sektor',
            { UuidOutlet: outlet[0]?.Uuid, KodeTemplate: 'RTL-GEN', Bersihkan: true },
            expect.any(Object),
        );
    });

    it('mematikan "bersihkan" menyembunyikan daftar dan mengirim Bersihkan false', () => {
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={BuatPratinjau()} />);

        fireEvent.click(screen.getByRole('checkbox', { name: /Bersihkan sisa jenis usaha lama/ }));

        expect(screen.queryByText('Kopi | Teh')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Ganti jenis usaha' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Ganti jenis usaha' }).at(-1) as HTMLElement);

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pengaturan/ganti-sektor',
            { UuidOutlet: outlet[0]?.Uuid, KodeTemplate: 'RTL-GEN', Bersihkan: false },
            expect.any(Object),
        );
    });

    it('tanpa sisa yang perlu dibersihkan tampil kalimat penjelasnya', () => {
        render(
            <HalamanGantiSektor
                Outlet={outlet}
                Template={template}
                Pratinjau={BuatPratinjau({
                    Dibersihkan: { Akun: [], Kategori: [], StasiunDapur: [], Fitur: [] },
                    Dipertahankan: [],
                })}
            />,
        );

        expect(screen.getByText('Tidak ada sisa jenis usaha lama yang perlu dibersihkan.')).toBeTruthy();
    });

    it('panel menu: jenis usaha belum diketahui menjelaskan bahwa semua menu tampil', () => {
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={null} />);

        expect(screen.getByText('Menu menurut jenis usaha')).toBeTruthy();
        expect(screen.getByText(/Jenis usaha belum dipilih, jadi semua menu ditampilkan/)).toBeTruthy();
    });

    it('panel menu: menyebut butir yang tampil dan yang disembunyikan menurut sektor saat ini', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['FNB-CAF'] });
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={null} />);

        const tampil = screen.getByText(/^Tampil untuk usaha Anda/).nextElementSibling?.textContent ?? '';
        const sembunyi = screen.getByText(/^Disembunyikan \(/).nextElementSibling?.textContent ?? '';

        expect(tampil).toContain('Produksi');
        expect(tampil).toContain('Stasiun dapur');
        expect(sembunyi).toContain('Paket sesi');
        expect(sembunyi).toContain('Laporan apotek');
        expect(screen.queryByText('Menu sudah menyesuaikan jenis usaha baru')).toBeNull();
    });

    it('sesudah berhasil mengganti FnB ke Apotek: menyebut menu yang muncul dan yang disembunyikan', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['FNB-CAF'] });
        tiruanRouter.post.mockImplementationOnce((_url: string, _data: unknown, opsi: { onSuccess?: () => void }) => {
            // Server berhasil mengganti sektor; prop bersama berikutnya memuat sektor baru.
            TambahPropsHalamanUji({ SektorOutlet: ['RTL-PHR'] });
            opsi.onSuccess?.();
        });
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={BuatPratinjau()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ganti jenis usaha' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Ganti jenis usaha' }).at(-1) as HTMLElement);

        const ringkasan = screen.getByText('Menu sudah menyesuaikan jenis usaha baru').parentElement?.textContent ?? '';

        expect(ringkasan).toContain('Kini tersedia: Laporan apotek');
        expect(ringkasan).toContain('Produksi');
        expect(ringkasan).toContain('Stasiun dapur');
    });

    it('sesudah mengganti ke jenis usaha bermenu sama: menjelaskan bahwa tidak ada butir menu yang berubah', () => {
        TambahPropsHalamanUji({ SektorOutlet: ['RTL-GEN'] });
        tiruanRouter.post.mockImplementationOnce((_url: string, _data: unknown, opsi: { onSuccess?: () => void }) => {
            TambahPropsHalamanUji({ SektorOutlet: ['RTL-FRS'] });
            opsi.onSuccess?.();
        });
        render(<HalamanGantiSektor Outlet={outlet} Template={template} Pratinjau={BuatPratinjau()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Ganti jenis usaha' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Ganti jenis usaha' }).at(-1) as HTMLElement);

        expect(screen.getByText(/Tidak ada butir menu yang berubah/)).toBeTruthy();
    });
});
