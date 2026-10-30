import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import TombolBantuan from '@/Komponen/Bantuan/TombolBantuan';
import { AturHalamanUji, TambahPropsHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { HitungPosisiBalon } from '@/Komponen/Tur/BalonTur';
import PenyediaTur from '@/Komponen/Tur/PenyediaTur';
import { CariTarget, KodeTurDashboard, SusunLangkahAktif, TurDashboard } from '@/Pustaka/DefinisiTur';

/*
 * PLT-12 tur produk: definisi langkah (target terlihat/opsional), penawaran otomatis di Beranda sekali saja, balon (maju,
 * mundur, lewati, Esc, fokus), pencatatan hasil ke server, dan tombol "Mulai tur" di lembar Bantuan.
 */

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const elemenUji: HTMLElement[] = [];

/** Pasang elemen bertanda `data-tur` dengan ukuran tetap (jsdom tidak punya tata letak, ukurannya selalu 0). */
function PasangTarget(nama: string, kotak = { top: 10, left: 100, width: 120, height: 32 }): HTMLElement {
    const elemen = document.createElement('button');
    elemen.setAttribute('data-tur', nama);
    elemen.textContent = nama;
    elemen.getBoundingClientRect = () =>
        ({
            ...kotak,
            right: kotak.left + kotak.width,
            bottom: kotak.top + kotak.height,
            x: kotak.left,
            y: kotak.top,
            toJSON: () => ({}),
        }) as DOMRect;
    document.body.appendChild(elemen);
    elemenUji.push(elemen);

    return elemen;
}

function RenderTur() {
    const klien = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={klien}>
            <PenyediaTur>
                <p>Isi halaman</p>
            </PenyediaTur>
        </QueryClientProvider>,
    );
}

function Tawarkan() {
    act(() => {
        vi.advanceTimersByTime(1000);
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    AturHalamanUji({}, '/kelola');
    Element.prototype.scrollIntoView = vi.fn();
    Object.defineProperty(window, 'innerWidth', { value: 1280, configurable: true });
    Object.defineProperty(window, 'innerHeight', { value: 800, configurable: true });
});

afterEach(() => {
    cleanup();
    elemenUji.splice(0).forEach((e) => e.remove());
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('definisi tur', () => {
    it('kode tur dashboard cocok dengan KodeTurProduk di server dan setiap langkah punya judul serta isi', () => {
        expect(KodeTurDashboard).toBe('dashboard.v1');
        expect(TurDashboard.Langkah.length).toBeGreaterThanOrEqual(5);
        expect(new Set(TurDashboard.Langkah.map((l) => l.Kunci)).size).toBe(TurDashboard.Langkah.length);

        for (const langkah of TurDashboard.Langkah) {
            expect(langkah.Judul.length).toBeGreaterThan(0);
            expect(langkah.Isi.length).toBeGreaterThan(0);
        }
    });

    it('target dipilih menurut urutan nama dan hanya yang terlihat. Elemen berukuran nol diabaikan', () => {
        const tersembunyi = PasangTarget('menu-samping', { top: 0, left: 0, width: 0, height: 0 });
        const tombol = PasangTarget('tombol-menu');

        expect(CariTarget(['menu-samping', 'tombol-menu'])).toBe(tombol);
        expect(CariTarget(['tidak-ada'])).toBeNull();
        expect(CariTarget(undefined)).toBeNull();
        expect(tersembunyi.isConnected).toBe(true);
    });

    it('langkah opsional tanpa target terlihat dibuang. Langkah tanpa target yang wajib tetap ada', () => {
        const tanpaTarget = SusunLangkahAktif(TurDashboard).map((l) => l.Kunci);
        expect(tanpaTarget).toEqual(['sambutan', 'menu', 'pencarian', 'notifikasi', 'siap']);

        PasangTarget('bantuan');
        expect(SusunLangkahAktif(TurDashboard).map((l) => l.Kunci)).toEqual([
            'sambutan',
            'menu',
            'pencarian',
            'notifikasi',
            'bantuan',
            'siap',
        ]);
    });
});

describe('letak balon', () => {
    const layar = { lebar: 1280, tinggi: 800 };
    const balon = { lebar: 360, tinggi: 200 };

    it('di bawah target bila muat, di atas bila tidak, di tengah bila tanpa target', () => {
        expect(HitungPosisiBalon({ atas: 10, kiri: 600, lebar: 100, tinggi: 40 }, balon, layar)).toEqual({
            atas: 62,
            kiri: 470,
        });
        expect(HitungPosisiBalon({ atas: 700, kiri: 600, lebar: 100, tinggi: 40 }, balon, layar)).toEqual({
            atas: 488,
            kiri: 470,
        });
        expect(HitungPosisiBalon(null, balon, layar)).toEqual({ atas: 300, kiri: 460 });
    });

    it('target setinggi layar (menu samping): balon di sisi kanannya sejajar bagian atas, atau di kirinya bila kanan tidak muat', () => {
        expect(HitungPosisiBalon({ atas: 56, kiri: 0, lebar: 240, tinggi: 744 }, balon, layar)).toEqual({
            atas: 56,
            kiri: 252,
        });
        expect(HitungPosisiBalon({ atas: 56, kiri: 1000, lebar: 240, tinggi: 744 }, balon, layar)).toEqual({
            atas: 56,
            kiri: 628,
        });
        expect(HitungPosisiBalon({ atas: 0, kiri: 0, lebar: 1280, tinggi: 800 }, balon, layar)).toEqual({
            atas: 300,
            kiri: 460,
        });
    });

    it('tidak keluar dari tepi layar kiri dan kanan', () => {
        expect(HitungPosisiBalon({ atas: 10, kiri: 0, lebar: 40, tinggi: 40 }, balon, layar).kiri).toBe(16);
        expect(HitungPosisiBalon({ atas: 10, kiri: 1240, lebar: 40, tinggi: 40 }, balon, layar).kiri).toBe(
            1280 - 360 - 16,
        );
    });
});

describe('penawaran otomatis di Beranda', () => {
    it('ditawarkan sekali bagi pengguna yang belum melihatnya', () => {
        TambahPropsHalamanUji({ TurDilihat: [] });
        RenderTur();
        expect(screen.queryByRole('dialog')).toBeNull();

        Tawarkan();
        const dialog = screen.getByRole('dialog', { name: 'Selamat datang di Payoung' });
        expect(within(dialog).getByText('Langkah 1 dari 5')).toBeTruthy();
    });

    it('tidak ditawarkan di halaman selain Beranda (tanpa prop TurDilihat), bila sudah dilihat, atau saat akses dukungan', () => {
        RenderTur();
        Tawarkan();
        expect(screen.queryByRole('dialog')).toBeNull();
        cleanup();

        TambahPropsHalamanUji({ TurDilihat: [KodeTurDashboard] });
        RenderTur();
        Tawarkan();
        expect(screen.queryByRole('dialog')).toBeNull();
        cleanup();

        TambahPropsHalamanUji({ TurDilihat: [], AksesDukungan: { Mode: 'BacaSaja' } });
        RenderTur();
        Tawarkan();
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('tidak ditawarkan saat ada pesan hasil aksi yang sedang tampil', () => {
        TambahPropsHalamanUji({ TurDilihat: [], Kilat: 'Produk disimpan.' });
        RenderTur();
        Tawarkan();
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});

describe('menjalankan tur', () => {
    beforeEach(() => {
        TambahPropsHalamanUji({ TurDilihat: [] });
    });

    it('Lanjut dan Kembali berpindah langkah. Langkah target menyorot elemen. Langkah terakhir bertombol Selesai', () => {
        PasangTarget('tombol-menu');
        PasangTarget('pencarian-cepat');
        PasangTarget('notifikasi');
        RenderTur();
        Tawarkan();

        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(screen.getByRole('dialog', { name: 'Menu samping' })).toBeTruthy();
        expect(screen.getByText('Langkah 2 dari 5')).toBeTruthy();
        expect(document.querySelector('[data-slot="tur"] .border-brand')).not.toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Kembali' }));
        expect(screen.getByRole('dialog', { name: 'Selamat datang di Payoung' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Kembali' })).toBeNull();

        for (let i = 0; i < 3; i += 1) {
            fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        }
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(screen.getByRole('dialog', { name: 'Siap mulai' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Lewati tur' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Selesai' })).toBeTruthy();
    });

    it('menyelesaikan tur mencatat Selesai ke server dan menutup balon', () => {
        RenderTur();
        Tawarkan();
        const jumlah = screen.getByText(/Langkah 1 dari/).textContent?.match(/dari (\d+)/)?.[1] ?? '0';

        for (let i = 0; i < Number(jumlah) - 1; i += 1) {
            fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        }
        fireEvent.click(screen.getByRole('button', { name: 'Selesai' }));

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(tiruanRouter.post).toHaveBeenCalledTimes(1);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/tur/dashboard.v1',
            { Status: 'Selesai' },
            expect.objectContaining({ preserveScroll: true, only: ['TurDilihat'] }),
        );
    });

    it('Lewati tur dan tombol Esc mencatat Dilewati', () => {
        RenderTur();
        Tawarkan();
        fireEvent.click(screen.getByRole('button', { name: 'Lewati tur' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/tur/dashboard.v1',
            { Status: 'Dilewati' },
            expect.anything(),
        );
        cleanup();

        TambahPropsHalamanUji({ TurDilihat: [] });
        RenderTur();
        Tawarkan();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(tiruanRouter.post).toHaveBeenCalledTimes(2);
    });

    it('tidak ditawarkan ulang selama permintaan catat belum dijawab server (props belum berubah)', () => {
        RenderTur();
        Tawarkan();
        fireEvent.click(screen.getByRole('button', { name: 'Lewati tur' }));
        Tawarkan();

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(tiruanRouter.post).toHaveBeenCalledTimes(1);
    });

    it('balon adalah dialog modal yang diberi fokus; Tab berputar di dalam balon', () => {
        RenderTur();
        Tawarkan();
        const dialog = screen.getByRole('dialog');

        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(dialog.getAttribute('aria-describedby')).not.toBeNull();
        expect(document.activeElement).toBe(dialog);

        const lanjut = screen.getByRole('button', { name: 'Lanjut' });
        lanjut.focus();
        fireEvent.keyDown(lanjut, { key: 'Tab' });
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Lewati tur' }));
        fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'Tab', shiftKey: true });
        expect(document.activeElement).toBe(lanjut);
    });

    it('akses dukungan yang menjalankan tur dari Bantuan tidak mencatat apa pun atas nama pemilik akun', async () => {
        TambahPropsHalamanUji({ TurDilihat: [], AksesDukungan: { Mode: 'BacaSaja' } });
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve({
                            Halaman: [],
                            Lainnya: [],
                            UrlPusatBantuan: 'https://payoung.id/pusat-bantuan',
                        }),
                }),
            ),
        );
        vi.useRealTimers();
        const klien = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={klien}>
                <PenyediaTur>
                    <TombolBantuan bolehHubungiDukungan={false} />
                </PenyediaTur>
            </QueryClientProvider>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Bantuan' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Mulai tur keliling dashboard' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Lewati tur' }));

        expect(screen.queryByRole('dialog', { name: 'Selamat datang di Payoung' })).toBeNull();
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });
});

describe('tombol Bantuan', () => {
    it('Mulai tur keliling dashboard menutup lembar lalu menjalankan tur dari langkah pertama', async () => {
        const Ambil = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({ Halaman: [], Lainnya: [], UrlPusatBantuan: 'https://payoung.id/pusat-bantuan' }),
            }),
        );
        vi.stubGlobal('fetch', Ambil);
        vi.useRealTimers();
        const klien = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={klien}>
                <PenyediaTur>
                    <TombolBantuan bolehHubungiDukungan={false} />
                </PenyediaTur>
            </QueryClientProvider>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Bantuan' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Mulai tur keliling dashboard' }));

        expect(await screen.findByRole('dialog', { name: 'Selamat datang di Payoung' })).toBeTruthy();
        expect(screen.queryByRole('dialog', { name: 'Bantuan' })).toBeNull();
    });
});
