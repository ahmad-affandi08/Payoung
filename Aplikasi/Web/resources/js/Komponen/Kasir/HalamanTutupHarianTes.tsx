import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTutupHarian, { AmbilStatusHari } from '@/Halaman/Kelola/Kasir/TutupHarian';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisTutupHarian } from '@/Tipe/Kasir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const dasar: BarisTutupHarian = {
    Kunci: '',
    Outlet: '01JOUTLETSOLO0000000000000',
    NamaOutlet: 'Outlet Solo',
    TanggalBisnis: '',
    Berjalan: false,
    Ditutup: false,
    DitutupPada: null,
    DitutupOleh: null,
    JumlahTransaksi: null,
    PenjualanBersih: null,
    ShiftBelumDitutup: 0,
    Peringatan: [],
};

const hari: BarisTutupHarian[] = [
    { ...dasar, Kunci: 'a', TanggalBisnis: '2026-10-15', Berjalan: true, ShiftBelumDitutup: 1 },
    {
        ...dasar,
        Kunci: 'b',
        TanggalBisnis: '2026-10-14',
        Peringatan: [{ Kode: 'PenjualanPerluTinjauan', Pesan: '2 penjualan ditandai perlu ditinjau.' }],
    },
    {
        ...dasar,
        Kunci: 'c',
        TanggalBisnis: '2026-10-13',
        Ditutup: true,
        DitutupPada: '2026-10-14T01:00:00Z',
        DitutupOleh: 'Sari Pemilik',
        JumlahTransaksi: 12,
        PenjualanBersih: '462000.00',
    },
    {
        ...dasar,
        Kunci: 'd',
        TanggalBisnis: '2026-10-12',
        Ditutup: true,
        DitutupPada: '2026-10-13T23:15:00Z',
        DitutupOleh: 'Sari Pemilik',
        DitutupOtomatis: true,
        JumlahTransaksi: 9,
        PenjualanBersih: '315000.00',
    },
];

describe('Tutup harian (F-15)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/kasir/tutup-harian'));
    afterEach(() => cleanup());

    it('status hari: shift belum ditutup, peringatan, ditutup', () => {
        expect(hari.map((h) => AmbilStatusHari(h).teks)).toEqual([
            '1 shift belum ditutup',
            '1 peringatan',
            'Ditutup',
            'Ditutup',
        ]);
        RenderUji(<HalamanTutupHarian Hari={hari} Izin={{ Kelola: true }} />);
        expect(screen.getAllByText(/Sari Pemilik/).length).toBeGreaterThan(0);
        // D-23 D: ditutup otomatis oleh jadwal pagi ditandai "otomatis", bukan nama Owner.
        expect(screen.getAllByText(/\| otomatis/).length).toBeGreaterThan(0);
    });

    it('peringatan wajib dicentang sebelum hari bisa ditutup', () => {
        RenderUji(<HalamanTutupHarian Hari={hari} Izin={{ Kelola: true }} />);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi Outlet Solo 14/ })[0] as HTMLElement);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Tutup hari' }));
        expect(screen.getByText('2 penjualan ditandai perlu ditinjau.')).toBeTruthy();
        const tombol = screen.getByRole('button', { name: 'Tutup hari' }) as HTMLButtonElement;
        expect(tombol.disabled).toBe(true);
        fireEvent.click(screen.getByRole('checkbox'));
        expect(tombol.disabled).toBe(false);
        fireEvent.click(tombol);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/kasir/tutup-harian',
            { Outlet: '01JOUTLETSOLO0000000000000', TanggalBisnis: '2026-10-14', AbaikanPeringatan: true },
            expect.anything(),
        );
    });

    it('hari dengan shift terbuka tidak bisa ditutup, tanpa izin kelola tidak ada aksi', () => {
        RenderUji(<HalamanTutupHarian Hari={hari} Izin={{ Kelola: true }} />);
        BukaMenu(screen.getAllByRole('button', { name: /Aksi Outlet Solo 15/ })[0] as HTMLElement);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Tutup hari' }));
        expect(screen.getByText(/Masih ada 1 shift/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Tutup hari' }) as HTMLButtonElement).disabled).toBe(true);

        cleanup();
        RenderUji(<HalamanTutupHarian Hari={hari} Izin={{ Kelola: false }} />);
        expect(screen.queryAllByRole('button', { name: /Aksi Outlet Solo/ })).toHaveLength(0);
    });
});
