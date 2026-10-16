import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanGantiSektor, { type PratinjauGantiSektor } from '@/Halaman/Kelola/Pengaturan/GantiSektor';
import { AturHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';

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
});
