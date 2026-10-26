import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanSlipGaji from '@/Halaman/Kelola/Karyawan/SlipGaji';
import type { BarisGajiKaryawan, PropsSlipGaji } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Maya: BarisGajiKaryawan = {
    UuidKaryawan: '01J9KRY0000000000000000001',
    Nama: 'Maya Puspitasari Handayani',
    Jabatan: 'Penata rambut senior',
    GajiPokok: '3250000.00',
    UpahHarian: null,
    HariMasuk: null,
    Komisi: '1475250.00',
    Tambahan: '0.00',
    LemburMenit: 0,
    Lembur: '0.00',
    TerlambatMenit: 0,
    PotonganTerlambat: '0.00',
    HariTidakMasuk: 0,
    PotonganTidakMasuk: '0.00',
    Kotor: '4725250.00',
    PotonganKasbon: '600000.00',
    PotonganLain: '0.00',
    Bersih: '4125250.00',
    Catatan: 'Lembur dibayar bulan depan',
    SisaKasbon: '700000.00',
};

function BuatProps(status: 'Draf' | 'Dibayar', baris: BarisGajiKaryawan[]): PropsSlipGaji {
    return {
        Rekap: {
            Uuid: '01J9RKP0000000000000000001',
            Periode: '2026-10',
            LabelPeriode: 'Oktober 2026',
            Siklus: 'Bulanan',
            LabelSiklus: 'Bulanan',
            TanggalMulai: '2026-10-01',
            TanggalSelesai: '2026-10-31',
            JumlahHari: 31,
            Status: status,
            LabelStatus: status,
            TotalKotor: '0.00',
            TotalPotongan: '0.00',
            TotalBersih: '0.00',
            TanggalBayar: status === 'Dibayar' ? '2026-10-31' : null,
            AkunKasBank: null,
            AkunBeban: null,
            Jurnal: null,
        },
        Baris: baris,
        Usaha: { Nama: 'Salon Cantik Nusantara', Npwp: null },
    };
}

describe('Slip gaji (F-18, v3.35)', () => {
    afterEach(() => cleanup());

    it('satu slip per karyawan: pendapatan, potongan, bersih; nilai nol tidak ditampilkan', () => {
        render(<HalamanSlipGaji {...BuatProps('Dibayar', [Maya])} />);
        const slip = screen.getByRole('article', { name: `Slip gaji ${Maya.Nama}` });

        expect(within(slip).getByText('Rp 3.250.000')).toBeTruthy();
        expect(within(slip).getByText('Rp 1.475.250')).toBeTruthy();
        expect(within(slip).getByText('Rp 4.125.250')).toBeTruthy();
        expect(within(slip).queryByText(/Tambahan/)).toBeNull();
        expect(within(slip).queryByText('Potongan lain')).toBeNull();
        expect(within(slip).getByText(/Sisa kasbon saat slip dicetak/)).toBeTruthy();
        expect(within(slip).queryByText('Draf, belum dibayar')).toBeNull();
        expect(within(slip).getByText(/Dibayar 31 Okt 2026/)).toBeTruthy();
    });

    it('draf bertanda; banyak karyawan = banyak slip', () => {
        render(
            <HalamanSlipGaji
                {...BuatProps('Draf', [Maya, { ...Maya, UuidKaryawan: '01J9KRY0000000000000000002', Nama: 'Dewi' }])}
            />,
        );

        expect(screen.getAllByRole('article')).toHaveLength(2);
        expect(screen.getAllByText('Draf, belum dibayar')).toHaveLength(2);
        expect(screen.getByText('Slip gaji 2 karyawan')).toBeTruthy();
    });
});
