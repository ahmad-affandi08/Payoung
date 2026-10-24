import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import KeadaanKosong from './KeadaanKosong';

afterEach(() => cleanup());

describe('KeadaanKosong (D-18)', () => {
    it('dengan ilustrasi: gambar dekoratif (tersembunyi dari pembaca layar) dan isi di tengah', () => {
        const { container } = render(
            <KeadaanKosong judul="Belum ada produk." ilustrasi>
                <a href="/kelola/produk/buat">Tambah produk</a>
            </KeadaanKosong>,
        );

        const gambar = container.querySelector('img');
        // Satu ilustrasi untuk semua daftar (D-18 revisi): tidak ada lagi aset per subjek.
        expect(gambar?.getAttribute('src')).toContain('Kosong.webp');
        expect(gambar?.getAttribute('alt')).toBe('');
        expect(gambar?.getAttribute('aria-hidden')).toBe('true');
        // Dimensi ditulis supaya tidak ada geseran tata letak saat gambarnya selesai dimuat.
        expect(gambar?.getAttribute('width')).toBe('1254');
        expect(gambar?.getAttribute('loading')).toBe('lazy');
        expect(screen.getByText('Belum ada produk.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Tambah produk' })).toBeTruthy();
        expect(container.firstElementChild?.className).toContain('text-center');
    });

    it('tanpa ilustrasi (hasil cari/filter kosong): tanpa gambar, rata kiri', () => {
        const { container } = render(<KeadaanKosong judul="Tidak ada hasil." />);

        expect(container.querySelector('img')).toBeNull();
        expect(container.firstElementChild?.className).toContain('text-left');
    });
});
