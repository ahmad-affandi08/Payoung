import { cleanup, render, screen } from '@testing-library/react';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import JudulHalaman from './JudulHalaman';

/*
 * Penjaga judul halaman (D-28, §17.4.11). File penjaga: kalau test ini gagal, perbaiki halamannya.
 *
 * Regresi yang dijaga: kelima tata letak memakai `text-judul font-bold text-teks-utama`, tetapi halaman yang
 * berdiri sendiri menulis kombinasinya sendiri — QR meja & cetak pesanan `font-semibold`, reservasi publik lupa
 * `text-teks-utama` sehingga warnanya ikut warisan. Judul halaman adalah hal pertama yang dibaca pengguna dan
 * pembaca layar; ukurannya tidak boleh berbeda per modul.
 */

const AKAR = 'resources/js';

/**
 * Pengecualian: `Komponen/Situs/Bagian/BagianHero` memilih `h1`/`h2` menurut posisi bloknya (hero pertama =
 * judul halaman, hero berikutnya = judul bagian). Tingkat yang bergantung posisi tidak bisa diungkapkan
 * `JudulHalaman`, jadi blok itu tetap menentukan tagnya sendiri.
 */
const DIKECUALIKAN = ['Komponen/Umpan/JudulHalaman.tsx', 'Komponen/Situs/Bagian/BagianHero.tsx'];

/** Semua berkas `.tsx` di luar `Komponen/Ui/` (keluaran CLI shadcn) dan di luar file penjaga. */
function BerkasSumber(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((isi) => {
        const jalur = join(folder, isi.name);

        if (isi.isDirectory()) {
            return isi.name === 'Ui' ? [] : BerkasSumber(jalur);
        }

        return jalur.endsWith('.tsx') && !/Tes\.tsx$/.test(isi.name) ? [jalur] : [];
    });
}

afterEach(cleanup);

describe('Judul halaman', () => {
    it('<h1> hanya didefinisikan JudulHalaman', () => {
        const pelanggar = BerkasSumber(AKAR)
            .map((jalur) => jalur.replace(`${AKAR}/`, ''))
            .filter((jalur) => !DIKECUALIKAN.includes(jalur))
            .filter((jalur) => {
                // Komentar dibuang dulu: beberapa berkas menyebut `<h1>` di dokumentasinya, dan itu bukan render.
                const isi = readFileSync(join(AKAR, jalur), 'utf8')
                    .replace(/\/\*[\s\S]*?\*\//g, '')
                    .replace(/\/\/[^\n]*/g, '');

                return /<h1[\s>]|['"]h1['"]/.test(isi);
            });

        expect(pelanggar).toEqual([]);
    });

    it('skala bawaan sama dengan judul di tata letak', () => {
        render(<JudulHalaman>Daftar produk</JudulHalaman>);

        const judul = screen.getByRole('heading', { level: 1, name: 'Daftar produk' });
        expect(judul.className).toContain('text-judul');
        expect(judul.className).toContain('font-bold');
        expect(judul.className).toContain('text-teks-utama');
    });

    it('skala situs & ringkas memakai ukuran lain tanpa menumpuk dua kelas ukuran', () => {
        render(
            <>
                <JudulHalaman skala="situs">Blog</JudulHalaman>
                <JudulHalaman skala="ringkas">Struk belum tersedia</JudulHalaman>
            </>,
        );

        const situs = screen.getByRole('heading', { level: 1, name: 'Blog' });
        expect(situs.className).toContain('text-judul-bagian-hp');
        expect(situs.className).not.toContain('text-judul ');

        const ringkas = screen.getByRole('heading', { level: 1, name: 'Struk belum tersedia' });
        expect(ringkas.className).toContain('text-subjudul');
        expect(ringkas.className).not.toContain('text-judul-bagian');
    });

    it('skala toko memakai ukuran sorotan besar dan warna bisa ditimpa tanpa kehilangan ukurannya', () => {
        render(
            <JudulHalaman skala="toko" className="text-permukaan">
                Kopi Senja
            </JudulHalaman>,
        );

        const toko = screen.getByRole('heading', { level: 1, name: 'Kopi Senja' });
        expect(toko.className).toContain('text-sorotan-besar-hp');
        expect(toko.className).toContain('sm:text-sorotan-besar');
        expect(toko.className).toContain('text-permukaan');
        expect(toko.className).not.toContain('text-teks-utama');
    });
});
