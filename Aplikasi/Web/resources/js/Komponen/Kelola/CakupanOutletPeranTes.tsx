import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import CakupanOutletPeran, { AmbilCakupan, TerapkanCakupanPeran } from '@/Komponen/Kelola/CakupanOutletPeran';
import type { OpsiOutletPengguna, OpsiPeranPengguna } from '@/Tipe/Organisasi';

/* D-104: cakupan outlet mengikuti peran. */

const semua: OpsiPeranPengguna = { Uuid: 'R-A', Nama: 'Admin', Pemilik: false, Cakupan: 'Semua' };
const terpilih: OpsiPeranPengguna = { Uuid: 'R-G', Nama: 'Staf Gudang', Pemilik: false, Cakupan: 'Terpilih' };
const bebas: OpsiPeranPengguna = { Uuid: 'R-K', Nama: 'Admin Cabang', Pemilik: false, Cakupan: 'Bebas' };
const pemilik: OpsiPeranPengguna = { Uuid: 'R-P', Nama: 'Pemilik (Owner)', Pemilik: true, Cakupan: 'Terpilih' };
const satuOutlet: OpsiOutletPengguna[] = [{ Uuid: 'O-1', Kode: 'UTAMA', Nama: 'Toko Utama' }];
const duaOutlet: OpsiOutletPengguna[] = [...satuOutlet, { Uuid: 'O-2', Kode: 'CAB2', Nama: 'Cabang Dua' }];

afterEach(cleanup);

describe('AmbilCakupan & TerapkanCakupanPeran', () => {
    it('peran Pemilik selalu Semua. Peran belum dipilih diperlakukan Bebas', () => {
        expect(AmbilCakupan(pemilik)).toBe('Semua');
        expect(AmbilCakupan(undefined)).toBe('Bebas');
        expect(AmbilCakupan(terpilih)).toBe('Terpilih');
    });

    it('peran Semua mengosongkan outlet dan menyalakan semua outlet', () => {
        expect(TerapkanCakupanPeran(semua, duaOutlet, { SemuaOutlet: false, Outlet: ['O-2'] })).toEqual({
            SemuaOutlet: true,
            Outlet: [],
        });
    });

    it('peran Terpilih mematikan semua outlet, mempertahankan pilihan, dan mengisi otomatis bila hanya satu outlet', () => {
        expect(TerapkanCakupanPeran(terpilih, duaOutlet, { SemuaOutlet: true, Outlet: ['O-2'] })).toEqual({
            SemuaOutlet: false,
            Outlet: ['O-2'],
        });
        expect(TerapkanCakupanPeran(terpilih, duaOutlet, { SemuaOutlet: true, Outlet: [] })).toEqual({
            SemuaOutlet: false,
            Outlet: [],
        });
        expect(TerapkanCakupanPeran(terpilih, satuOutlet, { SemuaOutlet: false, Outlet: [] })).toEqual({
            SemuaOutlet: false,
            Outlet: ['O-1'],
        });
    });

    it('peran Bebas tidak mengubah isian', () => {
        expect(TerapkanCakupanPeran(bebas, duaOutlet, { SemuaOutlet: true, Outlet: [] })).toEqual({
            SemuaOutlet: true,
            Outlet: [],
        });
    });
});

describe('CakupanOutletPeran', () => {
    it('tanpa peran terpilih tidak menampilkan apa pun', () => {
        const { container } = render(
            <CakupanOutletPeran
                peran={undefined}
                outlet={duaOutlet}
                nilai={{ SemuaOutlet: false, Outlet: [] }}
                saatBerubah={vi.fn()}
            />,
        );

        expect(container.textContent).toBe('');
    });

    it('Semua: hanya keterangan, tanpa kotak centang', () => {
        render(
            <CakupanOutletPeran
                peran={semua}
                outlet={duaOutlet}
                nilai={{ SemuaOutlet: true, Outlet: [] }}
                saatBerubah={vi.fn()}
            />,
        );

        expect(screen.getByRole('status').textContent).toContain('Peran Admin otomatis mencakup seluruh outlet');
        expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    });

    it('Terpilih: daftar outlet saja. Mencentang outlet melapor tanpa menyalakan semua outlet', () => {
        const SaatBerubah = vi.fn();
        render(
            <CakupanOutletPeran
                peran={terpilih}
                outlet={duaOutlet}
                nilai={{ SemuaOutlet: false, Outlet: [] }}
                saatBerubah={SaatBerubah}
            />,
        );

        expect(screen.queryByLabelText(/Semua outlet/)).toBeNull();
        fireEvent.click(screen.getByLabelText('CAB2 | Cabang Dua'));
        expect(SaatBerubah).toHaveBeenCalledWith({ SemuaOutlet: false, Outlet: ['O-2'] });
    });

    it('Bebas: saklar semua outlet. Menyalakannya menyembunyikan daftar outlet', () => {
        const SaatBerubah = vi.fn();
        const { rerender: Render } = render(
            <CakupanOutletPeran
                peran={bebas}
                outlet={duaOutlet}
                nilai={{ SemuaOutlet: false, Outlet: [] }}
                saatBerubah={SaatBerubah}
            />,
        );

        expect(screen.getByLabelText('UTAMA | Toko Utama')).toBeTruthy();
        fireEvent.click(screen.getByLabelText(/Semua outlet, termasuk outlet baru/));
        expect(SaatBerubah).toHaveBeenCalledWith({ SemuaOutlet: true, Outlet: [] });

        Render(
            <CakupanOutletPeran
                peran={bebas}
                outlet={duaOutlet}
                nilai={{ SemuaOutlet: true, Outlet: [] }}
                saatBerubah={SaatBerubah}
            />,
        );
        expect(screen.queryByLabelText('UTAMA | Toko Utama')).toBeNull();
    });
});
