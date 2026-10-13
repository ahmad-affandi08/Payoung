import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanCuti from '@/Halaman/Kelola/Karyawan/Cuti';
import { AturHalamanUji, kirimanForm, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisCuti, PropsCuti } from '@/Tipe/Karyawan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const dasar: BarisCuti = {
    Uuid: 'C1',
    Karyawan: 'Rina Wulandari',
    UuidKaryawan: 'KR1',
    Jenis: 'Cuti',
    TanggalMulai: '2026-10-12',
    TanggalSelesai: '2026-10-14',
    Berbayar: true,
    Alasan: 'Menikah',
    Status: 'Aktif',
    LabelStatus: 'Berlaku',
    AlasanBatal: null,
};

function Props(baris: BarisCuti[], kelola = true): PropsCuti {
    return {
        Cuti: BuatHasilTabel(baris),
        OpsiKaryawan: [{ Uuid: 'KR1', Nama: 'Rina Wulandari' }],
        Izin: { Kelola: kelola },
    };
}

describe('Cuti & izin karyawan (F-18 EMP-03)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/karyawan/cuti'));
    afterEach(() => cleanup());

    it('mencatat: bawaan Cuti tetap digaji; memilih Izin mematikan gaji; kiriman memuat jenis dan tanggal', () => {
        RenderUji(<HalamanCuti {...Props([dasar])} />);
        fireEvent.click(screen.getByRole('button', { name: 'Catat cuti atau izin' }));

        expect(screen.getByRole('checkbox', { name: /Tetap digaji/ }).getAttribute('aria-checked')).toBe('true');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan catatan' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/karyawan/cuti',
            data: { Jenis: 'Cuti', Berbayar: true, Karyawan: '' },
        });
    });

    it('membatalkan: menu baris hanya untuk catatan berlaku; kiriman membawa alasan ke alamat batal', () => {
        RenderUji(<HalamanCuti {...Props([dasar])} />);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi cuti Rina Wulandari/ })[0] as HTMLElement);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Batalkan catatan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Batalkan catatan' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/karyawan/cuti/C1/batal',
            data: { Alasan: '' },
        });
    });

    it('catatan yang sudah dibatalkan tanpa menu aksi; tanpa izin tidak ada tombol catat', () => {
        RenderUji(
            <HalamanCuti
                {...Props([
                    { ...dasar, Status: 'Dibatalkan', LabelStatus: 'Dibatalkan', AlasanBatal: 'Salah tanggal' },
                ])}
            />,
        );
        expect(screen.getByText('Salah tanggal')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Aksi cuti Rina Wulandari/ })).toBeNull();
        cleanup();
        RenderUji(<HalamanCuti {...Props([dasar], false)} />);
        expect(screen.queryByRole('button', { name: 'Catat cuti atau izin' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Aksi cuti/ })).toBeNull();
    });
});
