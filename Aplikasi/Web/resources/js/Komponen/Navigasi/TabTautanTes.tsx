import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import TabTautan, { KelasItemTab, kelasItemTabPanel } from './TabTautan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

afterEach(() => cleanup());

describe('TabTautan: satu gaya tab untuk seluruh web', () => {
    it('tab aktif ditandai aria-current, teks brand, dan batang bawah. Tab lain tidak', () => {
        render(
            <TabTautan
                label="Bagian produk"
                tab={[
                    { label: 'Ringkasan', href: '/kelola/produk/1', aktif: true },
                    { label: 'Harga', href: '/kelola/produk/1/harga', aktif: false },
                ]}
            />,
        );

        const aktif = screen.getByRole('link', { name: 'Ringkasan' });
        const lain = screen.getByRole('link', { name: 'Harga' });
        expect(screen.getByRole('navigation', { name: 'Bagian produk' })).toBeTruthy();
        expect(aktif.getAttribute('aria-current')).toBe('page');
        expect(aktif.className).toContain('text-brand');
        expect(aktif.className).toContain('before:bg-brand');
        expect(lain.getAttribute('aria-current')).toBeNull();
        expect(lain.className).not.toContain('before:bg-brand');
    });

    it('tab panel (Radix) memakai ukuran & tanda aktif yang sama dengan tab tautan', () => {
        for (const kelas of ['before:h-[3px]', 'px-4', 'py-3', 'text-subjudul', 'font-semibold']) {
            expect(KelasItemTab(true)).toContain(kelas);
            expect(kelasItemTabPanel).toContain(kelas);
        }
        expect(kelasItemTabPanel).toContain('data-[state=active]:text-brand');
        expect(kelasItemTabPanel).toContain('data-[state=active]:before:bg-brand');
    });

    it('bilah hanya bergulir menyamping dan panah geser disembunyikan dari pembaca layar', () => {
        render(<TabTautan label="Bagian" tab={[{ label: 'Satu', href: '/a', aktif: true }]} />);

        const bilah = screen.getByRole('navigation', { name: 'Bagian' });
        expect(bilah.className).toContain('overflow-x-auto');
        expect(bilah.className).toContain('overflow-y-hidden');
        for (const panah of Array.from(document.querySelectorAll('[data-panah]'))) {
            expect(panah.getAttribute('aria-hidden')).toBe('true');
            expect(panah.className).toContain('opacity-0');
        }
    });

    it('jalur tab tidak melebar sebesar deretan tabnya saat jadi anak flex (halaman tidak ikut tergeser)', () => {
        render(<TabTautan label="Bagian" tab={[{ label: 'Satu', href: '/a', aktif: true }]} />);

        const jalur = screen.getByRole('navigation', { name: 'Bagian' }).parentElement as HTMLElement;
        expect(jalur.className).toContain('min-w-0');
        expect(jalur.className).toContain('max-w-full');
    });

    it('panah kanan muncul saat ada tab di luar layar dan menggeser bilah', () => {
        render(
            <TabTautan
                label="Bagian"
                tab={[
                    { label: 'Satu', href: '/a', aktif: true },
                    { label: 'Dua', href: '/b', aktif: false },
                ]}
            />,
        );
        const bilah = screen.getByRole('navigation', { name: 'Bagian' });
        Object.defineProperty(bilah, 'clientWidth', { configurable: true, value: 100 });
        Object.defineProperty(bilah, 'scrollWidth', { configurable: true, value: 400 });
        const Geser = vi.fn();
        bilah.scrollBy = Geser;

        fireEvent.scroll(bilah);

        const kanan = document.querySelector<HTMLElement>('[data-panah="kanan"]');
        expect(kanan?.className).toContain('opacity-100');
        fireEvent.click(kanan as HTMLElement);
        expect(Geser).toHaveBeenCalledWith({ left: 60, behavior: 'smooth' });
    });
});
