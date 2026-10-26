import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { BagianSitus } from '@/Tipe/Situs';

import RenderBagian from './Bagian/RenderBagian';
import { SELANG_OTOMATIS_MS } from './Bagian/BagianHeroGeser';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...sisa }: { href: string; children: ReactNode }) => (
        <a href={href} {...sisa}>
            {children}
        </a>
    ),
    usePage: () => ({ props: {}, url: '/' }),
}));

type HeroGeser = Extract<BagianSitus, { Jenis: 'HeroGeser' }>;

function BuatSorotan(i: number): HeroGeser['Sorotan'][number] {
    return {
        Label: `Label ${i}`,
        Judul: `Judul sorotan ${i}`,
        Teks: `Kalimat sorotan ${i}.`,
        TombolUtama: { Label: `Coba ${i}`, Tautan: '/daftar' },
        TombolKedua: null,
        Gambar: null,
        Spesimen: i === 1 ? 'Kasir' : 'KasirBayar',
    };
}

function BuatHero(jumlah = 3, tambahan: Partial<HeroGeser> = {}): BagianSitus {
    return {
        Jenis: 'HeroGeser',
        Sorotan: Array.from({ length: jumlah }, (_, i) => BuatSorotan(i + 1)),
        Poin: [{ Teks: 'Tetap jalan tanpa internet' }],
        Catatan: 'Gratis untuk usaha mikro.',
        ...tambahan,
    };
}

function AturReduksiGerak(kurangi: boolean) {
    window.matchMedia = ((kueri: string) => ({
        matches: kurangi && kueri.includes('prefers-reduced-motion'),
        media: kueri,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        addListener: () => undefined,
        removeListener: () => undefined,
        dispatchEvent: () => false,
        onchange: null,
    })) as unknown as typeof window.matchMedia;
}

/** Slide yang sedang terlihat = yang tidak `invisible`. */
function SlideAktif(): HTMLElement[] {
    return screen
        .getAllByRole('group', { hidden: true })
        .filter((el) => el.getAttribute('aria-roledescription') === 'slide' && el.classList.contains('visible'));
}

beforeEach(() => {
    vi.useFakeTimers();
    AturReduksiGerak(false);
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('Pembuka geser (carousel hero beranda)', () => {
    it('semua teks sorotan ada di HTML, dengan satu h1 dan peran carousel yang bisa dibaca pembaca layar', () => {
        render(<RenderBagian bagian={[BuatHero(3)]} />);

        const wadah = screen.getByRole('region', { name: 'Sorotan Payoung' });
        expect(wadah.getAttribute('aria-roledescription')).toBe('carousel');

        expect(screen.getAllByRole('heading', { level: 1, hidden: true })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1, name: 'Judul sorotan 1' })).toBeTruthy();
        // Sorotan lain tetap ada di DOM (terbaca mesin pencari) sebagai h2.
        expect(screen.getAllByRole('heading', { level: 2, hidden: true }).map((h) => h.textContent)).toEqual([
            'Judul sorotan 2',
            'Judul sorotan 3',
        ]);

        const slide = screen
            .getAllByRole('group', { hidden: true })
            .filter((e) => e.getAttribute('aria-roledescription') === 'slide');
        expect(slide.map((s) => s.getAttribute('aria-label'))).toEqual(['1 dari 3', '2 dari 3', '3 dari 3']);
        expect(SlideAktif()).toHaveLength(1);
        expect(screen.getByText('Tetap jalan tanpa internet')).toBeTruthy();
    });

    it('tombol panah dan titik berlabel Indonesia mengganti sorotan; titik aktif ditandai aria-current', () => {
        render(<RenderBagian bagian={[BuatHero(3)]} />);

        fireEvent.click(screen.getByRole('button', { name: 'Sorotan berikutnya' }));
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('2 dari 3');
        expect(screen.getByRole('button', { name: 'Sorotan 2 dari 3: Label 2' }).getAttribute('aria-current')).toBe(
            'true',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Sorotan sebelumnya' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sorotan sebelumnya' }));
        // Dari sorotan pertama kembali ke yang terakhir (melingkar).
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('3 dari 3');

        fireEvent.click(screen.getByRole('button', { name: 'Sorotan 1 dari 3: Label 1' }));
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('1 dari 3');
    });

    it('berganti otomatis tiap 5 detik, berhenti setelah satu putaran, dan tombol Putar menyambungkannya lagi', () => {
        render(<RenderBagian bagian={[BuatHero(3)]} />);
        const Aktif = () => SlideAktif()[0]?.getAttribute('aria-label');

        expect(SELANG_OTOMATIS_MS).toBe(5000);
        expect(Aktif()).toBe('1 dari 3');

        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS));
        expect(Aktif()).toBe('2 dari 3');
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS));
        expect(Aktif()).toBe('3 dari 3');
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS));
        // Kembali ke awal lalu berhenti: tidak berputar tanpa henti (PRD §17.6.4).
        expect(Aktif()).toBe('1 dari 3');
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS * 5));
        expect(Aktif()).toBe('1 dari 3');

        fireEvent.click(screen.getByRole('button', { name: 'Putar pergantian otomatis' }));
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS));
        expect(Aktif()).toBe('2 dari 3');
    });

    it('terjeda saat kursor di atasnya atau fokus di dalamnya, dan jalan lagi setelah dilepas', () => {
        render(<RenderBagian bagian={[BuatHero(3)]} />);
        const wadah = screen.getByRole('region', { name: 'Sorotan Payoung' });
        const Aktif = () => SlideAktif()[0]?.getAttribute('aria-label');

        fireEvent.mouseEnter(wadah);
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS * 3));
        expect(Aktif()).toBe('1 dari 3');

        fireEvent.mouseLeave(wadah);
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS));
        expect(Aktif()).toBe('2 dari 3');

        fireEvent.focus(within(wadah).getByRole('button', { name: 'Sorotan berikutnya' }));
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS * 3));
        expect(Aktif()).toBe('2 dari 3');
    });

    it('memilih sorotan sendiri menghentikan pergantian otomatis; tombol Jeda juga', () => {
        render(<RenderBagian bagian={[BuatHero(3)]} />);
        const Aktif = () => SlideAktif()[0]?.getAttribute('aria-label');

        fireEvent.click(screen.getByRole('button', { name: 'Sorotan 3 dari 3: Label 3' }));
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS * 3));
        expect(Aktif()).toBe('3 dari 3');

        cleanup();
        render(<RenderBagian bagian={[BuatHero(3)]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Jeda pergantian otomatis' }));
        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS * 3));
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('1 dari 3');
    });

    it('prefers-reduced-motion: tanpa pergantian otomatis dan tanpa tombol Jeda/Putar', () => {
        AturReduksiGerak(true);
        render(<RenderBagian bagian={[BuatHero(3)]} />);

        act(() => void vi.advanceTimersByTime(SELANG_OTOMATIS_MS * 4));
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('1 dari 3');
        expect(screen.queryByRole('button', { name: /pergantian otomatis/ })).toBeNull();
        // Panah dan titik tetap bisa dipakai.
        fireEvent.click(screen.getByRole('button', { name: 'Sorotan berikutnya' }));
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('2 dari 3');
    });

    it('tombol panah papan ketik mengganti sorotan', () => {
        render(<RenderBagian bagian={[BuatHero(3)]} />);
        const wadah = screen.getByRole('region', { name: 'Sorotan Payoung' });

        fireEvent.keyDown(wadah, { key: 'ArrowRight' });
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('2 dari 3');
        fireEvent.keyDown(wadah, { key: 'ArrowLeft' });
        fireEvent.keyDown(wadah, { key: 'ArrowLeft' });
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('3 dari 3');
    });

    it('geser jari ke kiri/kanan di HP mengganti sorotan; geseran pendek diabaikan', () => {
        render(<RenderBagian bagian={[BuatHero(3)]} />);
        const panggung = screen.getAllByRole('group', { hidden: true })[0]?.parentElement as HTMLElement;

        const Geser = (dari: number, ke: number) => {
            fireEvent.pointerDown(panggung, { pointerType: 'touch', clientX: dari, clientY: 100 });
            fireEvent.pointerUp(panggung, { pointerType: 'touch', clientX: ke, clientY: 104 });
        };

        Geser(200, 190);
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('1 dari 3');
        Geser(250, 100);
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('2 dari 3');
        Geser(100, 250);
        expect(SlideAktif()[0]?.getAttribute('aria-label')).toBe('1 dari 3');
    });

    it('gambar sorotan jauh baru dimuat saat gilirannya hampir tiba (tidak menarik semua gambar sekaligus)', () => {
        render(<RenderBagian bagian={[BuatHero(5)]} />);
        const Gambar = () => document.querySelectorAll('img').length;

        // Sorotan 1 (aktif) dan 2 (berikutnya) sudah dimuat; 3 sampai 5 belum.
        const awal = Gambar();
        expect(awal).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('button', { name: 'Sorotan 4 dari 5: Label 4' }));
        expect(Gambar()).toBeGreaterThan(awal);
    });

    it('satu sorotan tidak menampilkan kendali geser', () => {
        render(<RenderBagian bagian={[BuatHero(1)]} />);

        expect(screen.queryByRole('button', { name: 'Sorotan berikutnya' })).toBeNull();
    });
});

describe('Blok beranda baru', () => {
    it('TabUsaha: pola tab ARIA, panah papan ketik, dan hanya panel aktif yang terlihat', () => {
        const blok: BagianSitus = {
            Jenis: 'TabUsaha',
            Label: null,
            Judul: 'Satu aplikasi, enam cara pakai',
            Subjudul: null,
            Item: ['Kafe', 'Toko', 'Salon'].map((nama) => ({
                Label: nama,
                Ikon: 'Store',
                Judul: `Judul ${nama}`,
                Teks: `Teks ${nama}`,
                Poin: [{ Teks: `Poin ${nama}` }],
                Gambar: null,
                Spesimen: 'Kasir' as const,
                Tombol: { Label: `Lihat ${nama}`, Tautan: '/fitur' },
            })),
        };
        render(<RenderBagian bagian={[blok]} />);

        const tab = screen.getAllByRole('tab');
        expect(tab.map((t) => t.textContent)).toEqual(['Kafe', 'Toko', 'Salon']);
        expect(tab[0]?.getAttribute('aria-selected')).toBe('true');
        expect(tab[1]?.getAttribute('tabindex')).toBe('-1');
        expect(screen.getByRole('tabpanel', { name: 'Kafe' }).hasAttribute('hidden')).toBe(false);
        // Panel lain tetap ada di DOM tetapi disembunyikan.
        expect(screen.getAllByRole('tabpanel', { hidden: true })[1]?.hasAttribute('hidden')).toBe(true);

        fireEvent.keyDown(screen.getByRole('tablist'), { key: 'ArrowRight' });
        expect(screen.getAllByRole('tab')[1]?.getAttribute('aria-selected')).toBe('true');
        expect(screen.getByRole('tabpanel', { name: 'Toko' }).textContent).toContain('Poin Toko');

        fireEvent.keyDown(screen.getByRole('tablist'), { key: 'End' });
        expect(screen.getAllByRole('tab')[2]?.getAttribute('aria-selected')).toBe('true');
    });

    it('Langkah: daftar bernomor berurutan dengan tombol ajakan', () => {
        const blok: BagianSitus = {
            Jenis: 'Langkah',
            Label: null,
            Judul: 'Empat langkah',
            Subjudul: null,
            Item: [
                { Ikon: null, Judul: 'Daftar', Teks: 'Cukup email.' },
                { Ikon: null, Judul: 'Siapkan', Teks: null },
            ],
            Tombol: { Label: 'Coba gratis sekarang', Tautan: '/daftar' },
        };
        render(<RenderBagian bagian={[blok]} />);

        const butir = within(screen.getByRole('list')).getAllByRole('listitem');
        expect(butir).toHaveLength(2);
        expect(butir[0]?.textContent).toContain('Langkah 1: Daftar');
        expect(screen.getByRole('link', { name: 'Coba gratis sekarang' }).getAttribute('href')).toBe('/daftar');
    });

    it('Integrasi: kelompok dengan daftar nama sebagai teks (tanpa gambar logo pihak ketiga)', () => {
        const blok: BagianSitus = {
            Jenis: 'Integrasi',
            Label: null,
            Judul: 'Terhubung',
            Subjudul: null,
            Kelompok: [
                {
                    Ikon: 'Printer',
                    Judul: 'Printer',
                    Teks: 'Cetak struk.',
                    Item: [{ Nama: 'Bluetooth' }, { Nama: 'LAN' }],
                },
            ],
            Catatan: 'Pesanan ojol dimasukkan manual.',
        };
        const { container } = render(<RenderBagian bagian={[blok]} />);

        expect(screen.getByRole('heading', { level: 3, name: 'Printer' })).toBeTruthy();
        expect(screen.getByText('Bluetooth')).toBeTruthy();
        expect(screen.getByText('Pesanan ojol dimasukkan manual.')).toBeTruthy();
        expect(container.querySelectorAll('img')).toHaveLength(0);
    });
});
