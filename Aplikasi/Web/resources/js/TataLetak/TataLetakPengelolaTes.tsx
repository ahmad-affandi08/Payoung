import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { PropsBersamaPengelola } from '@/Tipe/Pengelola';

import TataLetakAutentikasiPengelola from './TataLetakAutentikasiPengelola';
import TataLetakPengelola, { CekMenuPengelolaAktif } from './TataLetakPengelola';

let propsHalaman: PropsBersamaPengelola;
let urlHalaman = '/';

const tiruanRouter = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    router: tiruanRouter,
    usePage: () => ({ props: propsHalaman, url: urlHalaman }),
}));

function BuatProps(izin: string[], tambahan: Partial<PropsBersamaPengelola> = {}): PropsBersamaPengelola {
    return {
        NamaAplikasi: 'Kasir',
        Lingkungan: 'Produksi',
        Kilat: null,
        Pengguna: { Uuid: '01J', Nama: 'Dewi Anggraini', Email: 'dewi@contoh.id', KodePeran: [], Izin: izin },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
        ...tambahan,
    };
}

describe('TataLetakPengelola: menu sesuai izin, penanda lingkungan, banner (P-01, §13.8)', () => {
    afterEach(() => {
        cleanup();
        tiruanRouter.post.mockReset();
        document.cookie = 'sidebar_state=; path=/; max-age=0';
    });

    it('menu utama hanya berisi item yang diizinkan. Menu aktif menurut segmen pertama URL', () => {
        propsHalaman = BuatProps(['katalog.lihat', 'audit.lihat']);
        urlHalaman = '/katalog/addon';
        render(
            <TataLetakPengelola judul="Katalog" aksi={<button type="button">Tambah paket</button>}>
                isi
            </TataLetakPengelola>,
        );

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        expect(Array.from(utama.querySelectorAll('a')).map((a) => a.textContent)).toEqual([
            'Beranda',
            'Katalog',
            'Log audit',
        ]);
        expect(within(utama).getByRole('link', { name: 'Katalog' }).getAttribute('aria-current')).toBe('page');
        expect(screen.getByRole('button', { name: 'Tambah paket' })).toBeTruthy();
        expect(CekMenuPengelolaAktif('/', '/tenant')).toBe(false);
        expect(CekMenuPengelolaAktif('/referensi/tarif-pajak', '/referensi/wilayah')).toBe(true);
    });

    it('pencarian cepat Ctrl+K: halaman dari menu yang diizinkan, sumber tenant hanya bila Tenant ada di menu', () => {
        propsHalaman = BuatProps(['tenant.lihat', 'audit.lihat']);
        urlHalaman = '/';
        render(
            <QueryClientProvider client={new QueryClient()}>
                <TataLetakPengelola judul="Beranda">isi</TataLetakPengelola>
            </QueryClientProvider>,
        );

        fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('Tenant')).toBeTruthy();
        expect(within(dialog).getByText('Log audit')).toBeTruthy();
        // Tanpa izin lihat tagihan & dukungan, halamannya tidak ditawarkan.
        expect(within(dialog).queryByText('Tagihan')).toBeNull();
        expect(within(dialog).queryByText('Dukungan')).toBeNull();
    });

    it('menu dikelompokkan menurut pekerjaan; Tenant tepat di bawah Beranda, bukan paling bawah (D-30)', () => {
        propsHalaman = BuatProps([
            'tenant.lihat',
            'tagihan.lihat',
            'dukungan.tiket.lihat',
            'operasional.lihat',
            'katalog.lihat',
            'template.lihat',
            'rilis.lihat',
            'situs.lihat',
            'integrasi.lihat',
            'referensi.lihat',
            'legal.lihat',
            'tim.anggota.lihat',
            'audit.lihat',
        ]);
        urlHalaman = '/flag-fitur';
        render(<TataLetakPengelola judul="Rilis aplikasi">isi</TataLetakPengelola>);

        const utama = screen.getByRole('navigation', { name: 'Menu utama' });
        expect(
            Array.from(utama.querySelectorAll('[data-slot="sidebar-group-label"]')).map((l) => l.textContent),
        ).toEqual(['Pekerjaan harian', 'Produk & pemasaran', 'Data platform', 'Internal']);
        expect(
            Array.from(utama.querySelectorAll('a'))
                .map((a) => a.textContent)
                .slice(0, 3),
        ).toEqual(['Beranda', 'Tenant', 'Tagihan']);
        // Halaman flag fitur berada di bawah entri Rilis aplikasi lewat `alamatLain`, jadi menunya ikut menyala.
        expect(within(utama).getByRole('link', { name: 'Rilis aplikasi' }).getAttribute('aria-current')).toBe('page');
        expect(within(utama).queryByRole('link', { name: 'Flag fitur' })).toBeNull();
    });

    it('kepala sidebar memakai gradasi merek dan logo putih, bukan nama platform', () => {
        propsHalaman = BuatProps([]);
        const { container } = render(<TataLetakPengelola judul="Beranda">isi</TataLetakPengelola>);

        const kepala = container.querySelector('[data-slot="sidebar-header"]');
        expect(kepala?.className).toContain('bg-linear-to-br');
        expect(kepala?.className).toContain('from-brand-gelap');
        expect(kepala?.className).toContain('to-brand');
        expect(kepala?.querySelector('img[src*="LogoHorizontalPutih.webp"]')).toBeTruthy();
        expect(kepala?.querySelector('img[src*="IkonMerekPutih.png"]')).toBeTruthy();
        expect(kepala?.textContent).not.toContain('Kasir | Pengelola');
    });

    it('penanda lingkungan selalu tampil dan banner integrasi & operasional ditampilkan', () => {
        propsHalaman = BuatProps([], {
            PeringatanSuperAdmin: true,
            PeringatanIntegrasi: ['Kunci DOKU kedaluwarsa.'],
            PeringatanOperasional: ['Antrean tertunda 20 menit.'],
        });
        urlHalaman = '/';
        render(<TataLetakPengelola judul="Halo">isi</TataLetakPengelola>);

        expect(screen.getByText('Produksi: perubahan berdampak ke tenant sungguhan')).toBeTruthy();
        expect(screen.getByText('Super Admin aktif kurang dari 2')).toBeTruthy();
        expect(screen.getByText('Status integrasi')).toBeTruthy();
        expect(screen.getByText('Kunci DOKU kedaluwarsa.')).toBeTruthy();
        expect(screen.getByText('Masalah operasional')).toBeTruthy();
        expect(screen.getAllByRole('alert')).toHaveLength(3);
    });

    it('Keluar dari menu akun mem-POST /keluar', () => {
        propsHalaman = BuatProps([]);
        render(<TataLetakPengelola judul="Halo">isi</TataLetakPengelola>);

        fireEvent.keyDown(screen.getByRole('button', { name: 'Menu akun Dewi Anggraini' }), { key: 'Enter' });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Keluar' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith('/keluar');
    });

    it('tata letak autentikasi Pengelola: penanda lingkungan, judul, kilat, dan Toaster', () => {
        propsHalaman = BuatProps([], { Lingkungan: 'Staging', Kilat: 'Tautan terkirim.' });
        render(<TataLetakAutentikasiPengelola judul="Masuk">isi formulir</TataLetakAutentikasiPengelola>);

        expect(screen.getByText('Staging: data uji')).toBeTruthy();
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Masuk');
        expect(screen.getByRole('status').textContent).toContain('Tautan terkirim.');
        expect(screen.getByRole('region', { name: /^Notifikasi/ })).toBeTruthy();
    });
});
