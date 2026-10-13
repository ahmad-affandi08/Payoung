import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarArtikelBantuan, { type RingkasArtikelBantuan } from '@/Halaman/Pengelola/Situs/Bantuan/Daftar';
import HalamanUbahArtikelBantuan, { UraiKonteks } from '@/Halaman/Pengelola/Situs/Bantuan/Ubah';
import ArtikelBantuan from '@/Halaman/Situs/ArtikelBantuan';
import PusatBantuan from '@/Halaman/Situs/PusatBantuan';
import { BuatHasilTabel } from '@/Komponen/Katalog/DataUjiKatalog';
import TombolBantuan, { AmbilJalurHalaman, type HasilBantuanKontekstual } from '@/Komponen/Bantuan/TombolBantuan';
import { IzinPengelola } from '@/Tipe/Pengelola';
import type { DataSitus } from '@/Tipe/Situs';

/*
 * PLT-12 Pusat Bantuan: halaman publik (daftar per kategori + cari, artikel + video + terkait), konsol (daftar, ubah,
 * konteks satu jalur per baris), dan lembar Bantuan kontekstual di back-office (kueri hanya saat dibuka).
 */

const uji = vi.hoisted(() => ({ props: {} as Record<string, unknown>, url: '/kelola/produk' }));

vi.mock('@inertiajs/react', async () => {
    const tiruan = (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia;

    return { ...tiruan, usePage: () => ({ props: uji.props, url: uji.url }) };
});

const situs = {
    NamaSitus: 'Payoung',
    Slogan: null,
    Logo: null,
    Menu: [{ Label: 'Harga', Tautan: '/harga' }],
    MenuKaki: [],
    TeksKaki: null,
    Kontak: { WhatsApp: null, TautanWhatsApp: null, Email: null, Telepon: null, Alamat: null, JamLayanan: null },
    MediaSosial: {},
    Pengumuman: null,
    TautanUnduh: {},
    TombolDaftar: { Label: 'Coba gratis', Tautan: 'https://dashboard.payoung.id/daftar' },
    TombolMasuk: { Label: 'Masuk', Tautan: 'https://dashboard.payoung.id/masuk' },
    WhatsAppMelayang: false,
    Tahun: 2026,
} satisfies DataSitus;

function Render(elemen: ReactElement) {
    return render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            {elemen}
        </QueryClientProvider>,
    );
}

function TautanKonsol({ children }: { children: ReactNode }) {
    return <>{children}</>;
}

/** Props bersama konsol Pengelola (tata letak membacanya dari halaman). */
function PropsKonsol(): Record<string, unknown> {
    return {
        NamaAplikasi: 'Kasir',
        Lingkungan: 'Staging',
        Kilat: null,
        Pengguna: {
            Uuid: 'P1',
            Nama: 'Kania Konten',
            Email: 'kania@contoh.id',
            KodePeran: [],
            Izin: [IzinPengelola.SitusLihat, IzinPengelola.SitusKelola],
        },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Pusat Bantuan publik', () => {
    beforeEach(() => {
        uji.url = '/pusat-bantuan';
    });

    it('daftar: artikel dikelompokkan per kategori, formulir cari membawa kata dan kategori, hapus saringan muncul bila difilter', () => {
        uji.props = {
            Situs: situs,
            Halaman: { Seo: { Judul: 'Pusat Bantuan | Payoung', Deskripsi: 'Panduan memakai Payoung.' } },
            Kelompok: [
                {
                    Kategori: 'Kasir',
                    Artikel: [
                        {
                            Slug: 'buka-shift',
                            Judul: 'Cara buka shift',
                            Ringkasan: 'Hitung kas awal.',
                            UrlVideo: 'https://youtu.be/a',
                        },
                    ],
                },
                {
                    Kategori: 'Katalog',
                    Artikel: [{ Slug: 'tambah-produk', Judul: 'Cara menambah produk', Ringkasan: null }],
                },
            ],
            Kategori: ['Kasir', 'Katalog'],
            KategoriAktif: 'Kasir',
            Cari: 'shift',
            Total: 2,
        };
        render(<PusatBantuan />);

        const kasir = screen.getByRole('region', { name: 'Kasir' });
        expect(within(kasir).getByRole('link', { name: 'Cara buka shift' }).getAttribute('href')).toBe(
            '/pusat-bantuan/buka-shift',
        );
        expect(within(kasir).getByText('Ada video penjelasan')).toBeTruthy();
        expect(screen.getByRole('status').textContent).toContain('2 artikel ditemukan untuk "shift"');
        expect(screen.getByRole('link', { name: 'Hapus saringan' }).getAttribute('href')).toBe('/pusat-bantuan');

        const form = screen.getByRole('search');
        expect(form.getAttribute('action')).toBe('/pusat-bantuan');
        expect((within(form).getByLabelText('Cari artikel bantuan') as HTMLInputElement).value).toBe('shift');
        expect((form.querySelector('input[name="kategori"]') as HTMLInputElement).value).toBe('Kasir');
        expect(screen.getByRole('link', { name: 'Katalog' }).getAttribute('href')).toBe(
            '/pusat-bantuan?kategori=Katalog&cari=shift',
        );
    });

    it('daftar kosong: pesan berbeda untuk belum ada artikel dan untuk pencarian tanpa hasil', () => {
        uji.props = {
            Situs: situs,
            Halaman: { Seo: { Judul: 'Pusat Bantuan', Deskripsi: 'x' } },
            Kelompok: [],
            Kategori: [],
            KategoriAktif: null,
            Cari: '',
            Total: 0,
        };
        render(<PusatBantuan />);
        expect(screen.getByText('Artikel bantuan segera hadir')).toBeTruthy();
        cleanup();

        uji.props = {
            Situs: situs,
            Halaman: { Seo: { Judul: 'Pusat Bantuan', Deskripsi: 'x' } },
            Kelompok: [],
            Kategori: [],
            KategoriAktif: null,
            Cari: 'xyz',
            Total: 0,
        };
        render(<PusatBantuan />);
        expect(screen.getByText('Artikel tidak ditemukan')).toBeTruthy();
        expect(screen.getByRole('status').textContent).toContain('Tidak ada artikel yang cocok.');
    });

    it('artikel: jejak, tautan video aman (tab baru, noopener), isi, dan artikel terkait', () => {
        uji.url = '/pusat-bantuan/buka-shift';
        uji.props = {
            Situs: situs,
            Halaman: { Seo: { Judul: 'Cara buka shift | Pusat Bantuan Payoung', Deskripsi: 'x' } },
            Artikel: {
                Slug: 'buka-shift',
                Judul: 'Cara buka shift',
                Ringkasan: 'Hitung kas awal laci.',
                Kategori: 'Kasir',
                UrlVideo: 'https://youtu.be/abc',
                Isi: '## Langkah\n\nTekan Buka shift.',
                DiperbaruiPada: '2026-10-13T03:00:00Z',
            },
            Terkait: [{ Slug: 'tutup-shift', Judul: 'Cara tutup shift', Ringkasan: 'Hitung uang di laci.' }],
        };
        render(<ArtikelBantuan />);

        const jejak = screen.getByRole('navigation', { name: 'Jejak' });
        expect(within(jejak).getByRole('link', { name: 'Pusat Bantuan' }).getAttribute('href')).toBe('/pusat-bantuan');
        expect(within(jejak).getByRole('link', { name: 'Kasir' }).getAttribute('href')).toBe(
            '/pusat-bantuan?kategori=Kasir',
        );
        const video = screen.getByRole('link', { name: /Tonton video penjelasan/ });
        expect(video.getAttribute('href')).toBe('https://youtu.be/abc');
        expect(video.getAttribute('target')).toBe('_blank');
        expect(video.getAttribute('rel')).toContain('noopener');
        expect(screen.getByText('Tekan Buka shift.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Cara tutup shift' }).getAttribute('href')).toBe(
            '/pusat-bantuan/tutup-shift',
        );
    });
});

describe('lembar Bantuan kontekstual', () => {
    const hasil: HasilBantuanKontekstual = {
        Halaman: [
            {
                Slug: 'produk',
                Judul: 'Mengelola produk',
                Ringkasan: 'Tambah dan ubah produk.',
                Kategori: 'Katalog',
                UrlVideo: 'https://youtu.be/p',
                Url: 'https://payoung.id/pusat-bantuan/produk',
            },
        ],
        Lainnya: [
            {
                Slug: 'stok',
                Judul: 'Mengelola stok',
                Ringkasan: null,
                Kategori: null,
                UrlVideo: null,
                Url: 'https://payoung.id/pusat-bantuan/stok',
            },
        ],
        UrlPusatBantuan: 'https://payoung.id/pusat-bantuan',
    };
    let ambil: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        uji.url = '/kelola/produk/label?a=1';
        uji.props = {};
        ambil = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(hasil) }));
        vi.stubGlobal('fetch', ambil);
    });

    it('tidak memuat apa pun sampai dibuka; lalu menampilkan artikel halaman ini, artikel lain, dan tautan dukungan', async () => {
        Render(<TombolBantuan bolehHubungiDukungan />);
        expect(ambil).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Bantuan' }));
        const lembar = await screen.findByRole('dialog');
        await within(lembar).findByText('Mengelola produk');

        expect(ambil).toHaveBeenCalledTimes(1);
        expect(String(ambil.mock.calls[0]?.[0])).toBe('/kelola/pusat-bantuan?jalur=%2Fkelola%2Fproduk%2Flabel&cari=');
        expect(within(lembar).getByRole('region', { name: 'Untuk halaman ini' })).toBeTruthy();
        expect(within(lembar).getByRole('region', { name: 'Artikel lain' })).toBeTruthy();
        const artikel = within(lembar).getByRole('link', { name: /Mengelola produk/ });
        expect(artikel.getAttribute('href')).toBe('https://payoung.id/pusat-bantuan/produk');
        expect(artikel.getAttribute('target')).toBe('_blank');
        expect(within(lembar).getByRole('link', { name: 'Tonton video' }).getAttribute('href')).toBe(
            'https://youtu.be/p',
        );
        expect(within(lembar).getByRole('link', { name: 'Buka Pusat Bantuan lengkap' }).getAttribute('href')).toBe(
            'https://payoung.id/pusat-bantuan',
        );
        expect(within(lembar).getByRole('link', { name: 'Hubungi tim dukungan' }).getAttribute('href')).toBe(
            '/kelola/bantuan/buat',
        );
    });

    it('mengetik di kotak cari memuat ulang dengan kata itu; tautan dukungan hilang tanpa izin tiket', async () => {
        Render(<TombolBantuan bolehHubungiDukungan={false} />);
        fireEvent.click(screen.getByRole('button', { name: 'Bantuan' }));
        const lembar = await screen.findByRole('dialog');
        await within(lembar).findByText('Mengelola produk');
        expect(within(lembar).queryByRole('link', { name: 'Hubungi tim dukungan' })).toBeNull();

        fireEvent.change(within(lembar).getByLabelText('Cari bantuan'), { target: { value: ' stok ' } });
        await waitFor(() => expect(ambil).toHaveBeenCalledTimes(2));
        expect(String(ambil.mock.calls[1]?.[0])).toContain('cari=stok');
    });

    it('galat jaringan memberi pesan yang bisa dipahami; hasil kosong menyarankan dukungan', async () => {
        ambil.mockImplementation(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        Render(<TombolBantuan bolehHubungiDukungan />);
        fireEvent.click(screen.getByRole('button', { name: 'Bantuan' }));
        expect((await screen.findByRole('alert')).textContent).toContain('Bantuan belum bisa dimuat');
        cleanup();

        ambil.mockImplementation(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({ Halaman: [], Lainnya: [], UrlPusatBantuan: 'https://payoung.id/pusat-bantuan' }),
            }),
        );
        Render(<TombolBantuan bolehHubungiDukungan />);
        fireEvent.click(screen.getByRole('button', { name: 'Bantuan' }));
        expect(await screen.findByText(/Belum ada artikel bantuan/)).toBeTruthy();
    });

    it('jalur halaman dipangkas dari query dan fragmen', () => {
        expect(AmbilJalurHalaman('/kelola/produk?x=1#a')).toBe('/kelola/produk');
        expect(AmbilJalurHalaman('/kelola')).toBe('/kelola');
    });
});

describe('konsol artikel bantuan', () => {
    const baris: RingkasArtikelBantuan = {
        Uuid: '01J9BNT0000000000000000001',
        Slug: 'buka-shift',
        Judul: 'Cara buka shift',
        Kategori: 'Kasir',
        Urutan: 1,
        JumlahKonteks: 2,
        Status: 'Terbit',
        LabelStatus: 'Terbit',
        Jalur: '/pusat-bantuan/buka-shift',
        DiterbitkanPada: '2026-10-13T03:00:00Z',
        DiubahPada: '2026-10-13T03:00:00Z',
    };

    it('daftar: baris menautkan ke ubah dan alamat publik, jumlah konteks tampil', () => {
        uji.url = '/situs/bantuan';
        uji.props = PropsKonsol();
        Render(
            <TautanKonsol>
                <HalamanDaftarArtikelBantuan
                    Artikel={BuatHasilTabel([baris])}
                    PilihanKategori={['Kasir']}
                    UrlPusatBantuan="https://payoung.id/pusat-bantuan"
                    Izin={{ Kelola: true }}
                />
            </TautanKonsol>,
        );

        expect(screen.getByRole('link', { name: 'Cara buka shift' }).getAttribute('href')).toBe(
            '/situs/bantuan/01J9BNT0000000000000000001',
        );
        expect(screen.getByText('/pusat-bantuan/buka-shift')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Buka Pusat Bantuan/ }).getAttribute('href')).toBe(
            'https://payoung.id/pusat-bantuan',
        );
        expect(screen.getAllByRole('button', { name: 'Tulis artikel bantuan' })).not.toHaveLength(0);
    });

    it('ubah: formulir memuat konteks satu per baris dan video; hanya baca tanpa izin menyembunyikan simpan', () => {
        uji.url = '/situs/bantuan/01J9BNT0000000000000000001';
        uji.props = PropsKonsol();
        const artikel = {
            ...baris,
            Ringkasan: 'Hitung kas awal.',
            Isi: 'Tekan Buka shift.',
            UrlVideo: 'https://youtu.be/abc',
            Konteks: ['/kelola/shift', '/kelola/laporan/shift'],
        };
        Render(
            <HalamanUbahArtikelBantuan
                Artikel={artikel}
                PilihanKategori={['Kasir']}
                UrlArtikel="https://payoung.id/pusat-bantuan/buka-shift"
                Izin={{ Kelola: true }}
            />,
        );

        expect((screen.getByLabelText(/Halaman konteks/) as HTMLTextAreaElement).value).toBe(
            '/kelola/shift\n/kelola/laporan/shift',
        );
        expect((screen.getByLabelText(/Tautan video/) as HTMLInputElement).value).toBe('https://youtu.be/abc');
        expect(screen.getByRole('button', { name: 'Simpan & perbarui situs' })).toBeTruthy();
        cleanup();

        Render(
            <HalamanUbahArtikelBantuan
                Artikel={artikel}
                PilihanKategori={[]}
                UrlArtikel="https://payoung.id/pusat-bantuan/buka-shift"
                Izin={{ Kelola: false }}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Simpan & perbarui situs' })).toBeNull();
    });

    it('UraiKonteks membuang baris kosong dan memangkas spasi', () => {
        expect(UraiKonteks('/kelola/shift\n\n  /kelola/produk  \n')).toEqual(['/kelola/shift', '/kelola/produk']);
        expect(UraiKonteks('')).toEqual([]);
    });
});
