import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { PenyediaAksiHalaman } from '@/Komponen/Kelola/KonteksAksiHalaman';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import { JejakHalaman } from '@/TataLetak/BagianTataLetak';

import TabelData from './TabelData';
import type { KolomTabel } from './Tipe';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Daftar di HP (D-228). Sebelum ini halaman daftar membuka dengan dua sampai empat baris tombol sebelum satu
 * baris data pun terlihat. Sekarang: baris 1 kotak cari, baris 2 Filter + menu "Lainnya" + satu-satunya tombol
 * berwarna (aksi utama halaman), dan baris produk memadatkan pasangan label-nilai jadi sebaris.
 */

type Barang = { Uuid: string; Nama: string; Harga: string; Sku: string };

const kolom: KolomTabel<Barang>[] = [
    { accessorKey: 'Nama', header: 'Nama', meta: { label: 'Nama', prioritas: 'utama', wajib: true } },
    { accessorKey: 'Sku', header: 'SKU', meta: { label: 'SKU', prioritas: 'penting' } },
    { accessorKey: 'Harga', header: 'Harga', meta: { label: 'Harga', angka: true, prioritas: 'penting' } },
];

const awal = {
    Data: [{ Uuid: '1', Nama: 'Air Mineral', Harga: '8000', Sku: 'PRD-1' }],
    Meta: { Halaman: 1, PerHalaman: 25, Total: 1, JumlahHalaman: 1 },
};

function AturLebar(lebar: 'hp' | 'desktop') {
    window.matchMedia = (kueri: string) =>
        ({
            matches:
                (kueri === '(max-width: 639px)' && lebar === 'hp') ||
                (kueri === '(max-width: 1023px)' && lebar !== 'desktop'),
            media: kueri,
            onchange: null,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
            dispatchEvent: () => false,
        }) as MediaQueryList;
}

const MatchMediaAsli = window.matchMedia;

function Halaman({ adaTabel = true }: { adaTabel?: boolean }) {
    return (
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            <PenyediaAksiHalaman>
                <AksiHalaman
                    utama={<button type="button">Tambah barang</button>}
                    lainnya={[{ label: 'Impor dari Excel', href: '/kelola/barang/impor' }]}
                />
                {adaTabel ? (
                    <TabelData
                        id="barang"
                        label="Daftar barang"
                        kolom={kolom}
                        sumber={{ mode: 'server', alamat: '/kelola/barang', awal }}
                        ambilIdBaris={(b) => b.Uuid}
                        cari="Cari barang"
                        ekspor={{ alamat: '/kelola/barang/ekspor', label: 'Ekspor ke Excel' }}
                        saring={[
                            {
                                id: 'Status',
                                label: 'Status',
                                jenis: 'pilihanBanyak',
                                opsi: [{ nilai: 'A', label: 'A' }],
                            },
                        ]}
                        kosong={{ judul: 'Belum ada barang.' }}
                    />
                ) : null}
            </PenyediaAksiHalaman>
        </QueryClientProvider>
    );
}

afterEach(() => {
    cleanup();
    window.matchMedia = MatchMediaAsli;
});

describe('Daftar di HP (D-228)', () => {
    it('aksi utama pindah ke baris Filter bilah alat, aksi sekunder dan ekspor masuk menu Lainnya', () => {
        AturLebar('hp');
        render(<Halaman />);

        const slot = document.querySelector('[data-slot="aksi-utama-halaman"]');
        expect(slot).not.toBeNull();
        expect(within(slot as HTMLElement).getByRole('button', { name: 'Tambah barang' })).toBeTruthy();

        // Tidak ada tombol Impor/Ekspor berwarna sendiri-sendiri: keduanya hanya muncul di menu.
        expect(screen.queryByRole('link', { name: 'Impor dari Excel' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Ekspor ke Excel' })).toBeNull();

        BukaMenu(screen.getByRole('button', { name: 'Aksi lainnya' }));
        expect(screen.getByRole('menuitem', { name: 'Impor dari Excel' }).getAttribute('href')).toBe(
            '/kelola/barang/impor',
        );
        expect(screen.getByRole('menuitem', { name: 'Ekspor ke Excel' }).getAttribute('href')).toBe(
            '/kelola/barang/ekspor',
        );
    });

    it('di layar lebar aksi tetap tombol di atas tabel dan bilah alat tidak punya wadah aksi utama', () => {
        AturLebar('desktop');
        render(<Halaman />);

        expect(document.querySelector('[data-slot="aksi-utama-halaman"]')).toBeNull();
        expect(screen.getByRole('button', { name: 'Tambah barang' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Impor dari Excel' }).getAttribute('href')).toBe(
            '/kelola/barang/impor',
        );
        expect(screen.queryByRole('button', { name: 'Aksi lainnya' })).toBeNull();
    });

    it('halaman tanpa tabel memakai satu baris: aksi utama + menu Lainnya', () => {
        AturLebar('hp');
        render(<Halaman adaTabel={false} />);

        expect(screen.getByRole('button', { name: 'Tambah barang' })).toBeTruthy();
        BukaMenu(screen.getByRole('button', { name: 'Aksi lainnya' }));
        expect(screen.getByRole('menuitem', { name: 'Impor dari Excel' })).toBeTruthy();
    });

    it('pasangan label-nilai baris produk mengalir sebaris, bukan satu baris tiap pasangan', () => {
        AturLebar('hp');
        render(<Halaman />);

        const daftar = screen.getByRole('list', { name: 'Daftar barang' });
        const pasangan = daftar.querySelector('dl');

        expect(pasangan?.className).toContain('flex-wrap');
        expect(pasangan?.className).not.toContain('grid');
        expect(within(daftar).getByText('SKU')).toBeTruthy();
        expect(within(daftar).getByText('PRD-1')).toBeTruthy();
    });
});

describe('Aksi sekunder: tab baru dan nonaktif (D-228)', () => {
    function HalamanAksi() {
        return (
            <PenyediaAksiHalaman>
                <AksiHalaman
                    lainnya={[
                        { label: 'Lihat status', href: '/status', tabBaru: true },
                        { label: 'Susutkan', saatKlik: () => undefined, nonaktif: true },
                    ]}
                />
            </PenyediaAksiHalaman>
        );
    }

    it('layar lebar: tautan tab baru memakai target _blank dan aksi nonaktif tidak bisa ditekan', () => {
        AturLebar('desktop');
        render(<HalamanAksi />);

        const tautan = screen.getByRole('link', { name: 'Lihat status' });
        expect(tautan.getAttribute('target')).toBe('_blank');
        expect(tautan.getAttribute('rel')).toBe('noreferrer');
        expect((screen.getByRole('button', { name: 'Susutkan' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('HP: butir yang sama ada di menu Lainnya, tautan tab baru tetap target _blank', () => {
        AturLebar('hp');
        render(<HalamanAksi />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi lainnya' }));
        expect(screen.getByRole('menuitem', { name: 'Lihat status' }).getAttribute('target')).toBe('_blank');
        expect(screen.getByRole('menuitem', { name: 'Susutkan' }).getAttribute('aria-disabled')).toBe('true');
    });
});

describe('Jejak halaman di HP (D-228)', () => {
    it('jejak tanpa tautan disembunyikan di HP, jejak yang punya jalan kembali tetap tampil', () => {
        const bagian = render(<JejakHalaman jejak={[{ label: 'Kopi Nusantara' }, { label: 'Produk' }]} />);
        expect(screen.getByRole('navigation', { name: 'Jejak halaman' }).className).toContain('max-sm:hidden');
        bagian.unmount();

        render(
            <JejakHalaman jejak={[{ label: 'Kopi Nusantara' }, { label: 'Pengaturan', href: '/kelola/pengaturan' }]} />,
        );
        expect(screen.getByRole('navigation', { name: 'Jejak halaman' }).className).not.toContain('max-sm:hidden');
    });
});
