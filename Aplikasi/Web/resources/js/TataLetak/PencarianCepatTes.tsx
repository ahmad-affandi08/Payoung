import { act, cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { tiruanRouter, AturHalamanUji } from '@/Komponen/Katalog/TiruanInertia';
import { RenderDenganKueri } from '@/Pengujian/RenderKueri';

import PencarianCepat, { CocokkanHalaman, type HalamanPencarian, type SumberPencarian } from './PencarianCepat';
import { SaringMenuTerlihat, SusunPencarian } from './TataLetakAplikasi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const halaman: HalamanPencarian[] = [
    { label: 'Beranda', href: '/kelola', grup: null },
    { label: 'Produk', href: '/kelola/produk', grup: 'Produk' },
    { label: 'Pemasok', href: '/kelola/pembelian/pemasok', grup: 'Pembelian' },
];

const sumber: SumberPencarian[] = [
    {
        id: 'produk',
        label: 'Produk',
        alamat: '/kelola/produk',
        ikon: 'Produk',
        AmbilHasil: (b) => ({ judul: String(b.Nama), keterangan: null, href: `/kelola/produk/${String(b.Uuid)}` }),
    },
];

const panggilanFetch: string[] = [];

beforeEach(() => {
    AturHalamanUji();
    panggilanFetch.length = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string) => {
            panggilanFetch.push(url);

            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ Data: [{ Uuid: 'P1', Nama: 'Teh Tarik' }], Meta: { Total: 1 } }),
            });
        }),
    );
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('Pencarian cepat di kepala halaman', () => {
    it('Ctrl+K membuka palet; halaman disaring per kata (label atau grup)', () => {
        RenderDenganKueri(<PencarianCepat halaman={halaman} sumber={sumber} />);

        fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
        expect(screen.getByRole('dialog')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Kata pencarian'), { target: { value: 'pembelian' } });
        expect(screen.getByRole('option', { name: /Pemasok/ })).toBeTruthy();
        expect(screen.queryByRole('option', { name: /Beranda/ })).toBeNull();
        expect(CocokkanHalaman(halaman[1] as HalamanPencarian, 'PRO')).toBe(true);
    });

    it('data dicari ke endpoint TabelData setelah jeda, memilih hasil membuka detailnya', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        RenderDenganKueri(<PencarianCepat halaman={halaman} sumber={sumber} />);

        fireEvent.click(screen.getByRole('button', { name: 'Pencarian cepat' }));
        fireEvent.change(screen.getByLabelText('Kata pencarian'), { target: { value: 'teh' } });
        expect(panggilanFetch).toHaveLength(0);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(350);
        });
        await waitFor(() => expect(screen.getByRole('option', { name: /Teh Tarik/ })).toBeTruthy());
        expect(panggilanFetch[0]).toBe('/kelola/produk?cari=teh');

        fireEvent.click(screen.getByRole('option', { name: /Teh Tarik/ }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/produk/P1');
    });

    it('halaman & sumber data mengikuti menu yang boleh dilihat (izin)', () => {
        const aksesKosong = { Pemilik: false, Izin: [] };
        const tanpaIzin = SusunPencarian(SaringMenuTerlihat(aksesKosong), aksesKosong);
        expect(tanpaIzin.sumber.map((s) => s.id)).not.toContain('produk');
        expect(tanpaIzin.halaman.some((h) => h.href === '/kelola')).toBe(true);

        const aksesProduk = { Pemilik: false, Izin: ['produk.lihat'] };
        const denganProduk = SusunPencarian(SaringMenuTerlihat(aksesProduk), aksesProduk);
        expect(denganProduk.sumber.map((s) => s.id)).toContain('produk');
        expect(denganProduk.halaman.find((h) => h.href === '/kelola/produk')?.grup).toBe('Produk');
    });

    it('halaman yang pindah ke Pengaturan tetap bisa ditemukan lewat pencarian (D-27)', () => {
        // Regresi: setelah Outlet, Pengguna, Satuan, dsb. keluar dari menu samping, Ctrl+K adalah satu-satunya
        // jalan cepat ke sana selain halaman Pengaturan.
        const akses = { Pemilik: true, Izin: [] };
        const { halaman } = SusunPencarian(SaringMenuTerlihat(akses), akses);
        const alamat = halaman.map((h) => h.href);

        expect(alamat).toContain('/kelola/outlet');
        // D-101: anggota tim pindah ke menu Tim; Pengaturan menyisakan Peran & izin.
        expect(alamat).toContain('/kelola/peran');
        expect(alamat).toContain('/kelola/tim');
        expect(alamat).toContain('/kelola/satuan');
        expect(alamat).toContain('/kelola/kasir/struk');
        expect(halaman.find((h) => h.href === '/kelola/outlet')?.grup).toBe('Pengaturan › Usaha');
        // Tidak ada alamat kembar walau halaman Pengaturan juga ada di menu samping.
        expect(alamat.filter((href, i) => alamat.indexOf(href) !== i)).toEqual([]);
    });
    it('perintah kerja bengkel hanya ikut Ctrl+K untuk usaha bengkel (SVC-WRK), bukan toko kelontong', () => {
        const akses = { Pemilik: true, Izin: [] };
        const Alamat = (sektor: string[]) =>
            SusunPencarian(SaringMenuTerlihat(akses, { sektorOutlet: sektor }), akses, undefined, sektor).halaman.map(
                (h) => h.href,
            );

        expect(Alamat(['RTL-GEN'])).not.toContain('/kelola/bengkel/perintah-kerja');
        expect(Alamat(['RTL-GEN'])).not.toContain('/kelola/bengkel/kendaraan');
        expect(Alamat(['SVC-WRK'])).toContain('/kelola/bengkel/perintah-kerja');
        expect(Alamat(['RTL-GEN', 'SVC-WRK'])).toContain('/kelola/bengkel/kendaraan');
    });
    it('riwayat nomor seri (tab Kartu stok) ikut Ctrl+K selama Kartu stok terlihat, lengkap dengan sumber data nomor', () => {
        const aksesStok = { Pemilik: false, Izin: ['persediaan.lihat'] };
        const { halaman, sumber } = SusunPencarian(SaringMenuTerlihat(aksesStok), aksesStok);

        expect(halaman.find((h) => h.href === '/kelola/persediaan/kartu-stok/nomor-seri')?.label).toBe(
            'Riwayat nomor seri / IMEI',
        );

        const nomorSeri = sumber.find((s) => s.id === 'nomor-seri');

        expect(
            nomorSeri?.AmbilHasil({ Uuid: 'U1', Nomor: 'IMEI-1', NamaProduk: 'Ponsel', LabelStatus: 'Tersedia' }),
        ).toEqual({
            judul: 'IMEI-1',
            keterangan: 'Ponsel | Tersedia',
            href: '/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-1&unit=U1',
        });

        const aksesKosong = { Pemilik: false, Izin: [] };
        const tanpaIzin = SusunPencarian(SaringMenuTerlihat(aksesKosong), aksesKosong);

        expect(tanpaIzin.halaman.some((h) => h.href === '/kelola/persediaan/kartu-stok/nomor-seri')).toBe(false);
        expect(tanpaIzin.sumber.map((s) => s.id)).not.toContain('nomor-seri');
    });
});
