import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanKasbon from '@/Halaman/Kelola/Karyawan/Kasbon';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisKasbon, PropsKasbon } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const dasar: BarisKasbon = {
    Uuid: 'K1',
    Karyawan: 'Rina Wulandari',
    UuidKaryawan: 'KR1',
    Tanggal: '2026-10-20',
    Jumlah: '500000.00',
    Sisa: '500000.00',
    Status: 'Aktif',
    LabelStatus: 'Belum lunas',
    Keterangan: 'Biaya sekolah',
    AkunKasBank: '1-1100 Kas Outlet',
    AlasanBatal: null,
};

function Props(baris: BarisKasbon[], kelola = true): PropsKasbon {
    return {
        Kasbon: BuatHasilTabel(baris),
        TotalSisa: '500000.00',
        OpsiKaryawan: [{ Uuid: 'KR1', Nama: 'Rina Wulandari' }],
        OpsiAkunKasBank: [{ Uuid: 'A1', Nama: '1-1100 Kas Outlet' }],
        Izin: { Kelola: kelola },
    };
}

describe('Kasbon karyawan (F-18 bagian 3)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/karyawan/kasbon'));
    afterEach(() => cleanup());

    it('menampilkan total sisa dan pelunasan mengirim sisa sebagai jumlah bawaan', () => {
        RenderUji(<HalamanKasbon {...Props([dasar])} />);
        expect(screen.getAllByText('Rp 500.000').length).toBeGreaterThan(0);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi kasbon Rina Wulandari/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Batalkan kasbon' })).toBeTruthy();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Catat pelunasan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pelunasan' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/karyawan/kasbon/K1/pelunasan',
            data: { Jumlah: '500000', AkunKasBank: 'A1' },
        });
    });

    it('kasbon yang sudah dicicil tidak bisa dibatalkan, tanpa izin tidak ada tombol & aksi', () => {
        RenderUji(<HalamanKasbon {...Props([{ ...dasar, Sisa: '300000.00' }])} />);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi kasbon Rina Wulandari/ })[0] as HTMLElement);
        expect(screen.queryByRole('menuitem', { name: 'Batalkan kasbon' })).toBeNull();
        cleanup();
        RenderUji(<HalamanKasbon {...Props([dasar], false)} />);
        expect(screen.queryByRole('button', { name: 'Catat kasbon' })).toBeNull();
        expect(screen.queryAllByRole('button', { name: /Aksi kasbon/ })).toHaveLength(0);
    });
});
