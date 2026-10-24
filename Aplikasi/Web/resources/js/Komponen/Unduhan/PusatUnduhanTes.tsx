import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarUnduhan from '@/Halaman/Kelola/Unduhan/Daftar';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import TombolEkspor, { BuatAlamatEksporBesar } from '@/Komponen/Laporan/TombolEkspor';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisUnduhan } from '@/Tipe/Unduhan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Siap: BarisUnduhan = {
    Uuid: '01J9UNDUH000000000000000A1',
    Judul: 'Laporan penjualan detail 2026 10 01 2026 10 07',
    NamaBerkas: 'laporan-penjualan-detail-2026-10-01-2026-10-07.xlsx',
    Format: 'xlsx',
    Status: 'Siap',
    LabelStatus: 'Siap diunduh',
    UkuranByte: 2_621_440,
    DibuatPada: '2026-10-12T03:00:00Z',
    SelesaiPada: '2026-10-12T03:01:00Z',
    KedaluwarsaPada: '2099-10-19T03:01:00Z',
    DiunduhPada: null,
    Galat: null,
    BisaDiunduh: true,
};

const props = {
    OpsiStatus: [
        { Nilai: 'Siap', Label: 'Siap diunduh' },
        { Nilai: 'Gagal', Label: 'Gagal' },
    ],
    HariBerlaku: 7,
};

describe('Pusat Unduhan (D-124)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/unduhan');
        window.history.replaceState({}, '', '/kelola/unduhan');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('menampilkan berkas siap dengan ukuran dan format, berkas gagal dengan pesannya, dan masa berlaku', () => {
        const gagal: BarisUnduhan = {
            ...Siap,
            Uuid: '01J9UNDUH000000000000000A2',
            Judul: 'Daftar penjualan',
            Format: 'csv',
            Status: 'Gagal',
            LabelStatus: 'Gagal',
            BisaDiunduh: false,
            UkuranByte: 0,
            Galat: 'Berkas gagal disiapkan. Coba lagi dengan rentang tanggal atau filter yang lebih sempit.',
        };
        RenderUji(<HalamanDaftarUnduhan {...props} Unduhan={BuatHasilTabel([Siap, gagal])} />);

        expect(screen.getAllByText('Laporan penjualan detail 2026 10 01 2026 10 07').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Siap diunduh').length).toBeGreaterThan(0);
        expect(screen.getAllByText('2,5 MB').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Berkas gagal disiapkan/).length).toBeGreaterThan(0);
        expect(screen.getByText(/tersedia 7 hari/)).toBeTruthy();
    });

    it('daftar kosong memakai keadaan kosong', () => {
        RenderUji(<HalamanDaftarUnduhan {...props} Unduhan={BuatHasilTabel([])} />);

        expect(screen.getByText(/Belum ada berkas unduhan/)).toBeTruthy();
    });
});

describe('TombolEkspor: ekspor besar (D-124)', () => {
    it('BuatAlamatEksporBesar memaksa Excel dan menambah latarbelakang=1 tanpa menggandakan format', () => {
        expect(BuatAlamatEksporBesar('/kelola/penjualan/ekspor', 'cari=minyak&format=csv')).toBe(
            '/kelola/penjualan/ekspor?cari=minyak&format=xlsx&latarbelakang=1',
        );
    });

    it('pilihan Ekspor besar hanya muncul bila diminta', () => {
        render(<TombolEkspor alamat="/laporan/ekspor" query="dari=2026-10-01" />);
        BukaMenu(screen.getByRole('button', { name: 'Ekspor' }));
        expect(screen.queryByRole('menuitem', { name: /Ekspor besar/ })).toBeNull();
        cleanup();

        render(<TombolEkspor alamat="/laporan/ekspor" query="dari=2026-10-01" eksporBesar />);
        BukaMenu(screen.getByRole('button', { name: 'Ekspor' }));
        expect(screen.getByRole('menuitem', { name: /Ekspor besar/ }).getAttribute('href')).toBe(
            '/laporan/ekspor?dari=2026-10-01&format=xlsx&latarbelakang=1',
        );
        cleanup();
    });
});
