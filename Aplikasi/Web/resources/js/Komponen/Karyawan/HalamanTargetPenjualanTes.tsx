import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTargetPenjualan from '@/Halaman/Kelola/Karyawan/Target';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisTargetPenjualan, PropsTargetPenjualan } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const target: BarisTargetPenjualan = {
    Uuid: 'T1',
    Cakupan: 'Karyawan',
    LabelCakupan: 'Karyawan',
    UuidSasaran: 'KR1',
    NamaSasaran: 'Maya Senior',
    Nilai: '10000000.00',
    Realisasi: '8250000.00',
    Persen: '82.5',
    Sisa: '1750000.00',
    Proyeksi: '12500000.00',
};

function Props(kelola = true, berjalan = true): PropsTargetPenjualan {
    return {
        Periode: '2026-10',
        Berjalan: berjalan,
        OpsiPeriode: [
            { Nilai: '2026-10', Label: 'Oktober 2026' },
            { Nilai: '2026-09', Label: 'September 2026' },
        ],
        Target: [target],
        OpsiOutlet: [{ Uuid: 'O1', Nama: 'Outlet Solo Baru' }],
        OpsiKaryawan: [{ Uuid: 'KR1', Nama: 'Maya Senior' }],
        Izin: { Kelola: kelola },
    };
}

describe('Target penjualan (F-18 bagian 3)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/karyawan/target'));
    afterEach(() => cleanup());

    it('menampilkan progres & proyeksi. Ubah target mengirim sasaran yang sama', () => {
        RenderUji(<HalamanTargetPenjualan {...Props()} />);
        expect(screen.getAllByText('82,5%').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Rp 12.500.000').length).toBeGreaterThan(0);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi target Maya Senior/ })[0] as HTMLElement);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Ubah target' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan target' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'put',
            url: '/kelola/karyawan/target',
            data: { Periode: '2026-10', Cakupan: 'Karyawan', Sasaran: 'KR1', Nilai: '10000000' },
        });
    });

    it('bulan lalu tanpa kolom proyeksi, tanpa izin kelola tidak ada tombol & aksi', () => {
        RenderUji(<HalamanTargetPenjualan {...Props(false, false)} />);
        expect(screen.queryByText('Rp 12.500.000')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Tambah target' })).toBeNull();
        expect(screen.queryAllByRole('button', { name: /Aksi target/ })).toHaveLength(0);
    });
});
