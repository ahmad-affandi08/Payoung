import { cleanup, render, screen } from '@testing-library/react';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import TabelForm from './TabelForm';
import { TableBody, TableCell, TableRow } from '@/Komponen/Ui/table';

/*
 * Penjaga tabel isian/rincian (D-28, §17.4.3 & §17.4.11). File penjaga: kalau test ini gagal, perbaiki halamannya.
 *
 * Regresi yang dijaga: `TabelData` sudah terstandar, tetapi tabel yang memang dikecualikan (baris berisi bidang
 * yang diedit, rincian dokumen kecil) dirakit sendiri — **11 nilai `min-w-[...]` berbeda di 13 berkas**, dari 420px
 * sampai 880px, dua di antaranya dalam `rem`. Tabel dengan kolom sejenis jadi punya titik gulir berbeda-beda. Dan
 * container gulir bawaan `Table` shadcn tidak punya `tabIndex`, jadi pengguna keyboard tidak bisa menggeser tabel
 * yang lebih lebar dari layar (WCAG 2.1.1).
 */

const AKAR = 'resources/js';

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

describe('Tabel isian/rincian', () => {
    it('tidak ada lebar minimum arbitrer pada <Table>: lebarnya lewat preset TabelForm', () => {
        const pelanggar: string[] = [];

        for (const jalur of BerkasSumber(AKAR)) {
            if (jalur.endsWith('Komponen/TabelData/TabelForm.tsx')) {
                continue;
            }

            for (const cocok of readFileSync(jalur, 'utf8').matchAll(/<Table\b[^>]*>/g)) {
                if (/min-w-\[/.test(cocok[0])) {
                    pelanggar.push(`${jalur.replace(`${AKAR}/`, '')}: ${cocok[0].replace(/\s+/g, ' ').slice(0, 60)}`);
                }
            }
        }

        expect(pelanggar).toEqual([]);
    });

    it('area gulirnya jadi region bernama yang bisa difokus keyboard', () => {
        render(
            <TabelForm label="Bahan resep" lebar="lebar">
                <TableBody>
                    <TableRow>
                        <TableCell>Gula</TableCell>
                    </TableRow>
                </TableBody>
            </TabelForm>,
        );

        const area = screen.getByRole('region', { name: 'Bahan resep' });

        // Tanpa tabIndex, tabel yang lebih lebar dari layar tidak bisa digeser dengan keyboard (WCAG 2.1.1).
        expect(area.getAttribute('tabindex')).toBe('0');
        expect(area.className).toContain('overflow-x-auto');
        expect(area.querySelector('table')?.className).toContain('min-w-[768px]');
    });

    it('aturan CSS mematikan container gulir bawaan shadcn di dalam TabelForm', () => {
        // Tanpa ini ada dua area gulir bersarang, dan yang menggulir justru yang tidak bisa difokus.
        const css = readFileSync(join(AKAR, 'Gaya/Aplikasi.css'), 'utf8');

        expect(css).toMatch(/\.tabel-form > \[data-slot='table-container'\]\s*\{[^}]*overflow:\s*visible/);
    });
    it('tumpuk: wadah menandai data-tumpuk dan CSS mengubah baris jadi kartu di bawah 1024px', () => {
        render(
            <TabelForm label="Baris dokumen" lebar="dokumen" tumpuk>
                <TableBody>
                    <TableRow>
                        <TableCell data-label="Jumlah">2</TableCell>
                    </TableRow>
                </TableBody>
            </TabelForm>,
        );

        expect(screen.getByRole('region', { name: 'Baris dokumen' }).hasAttribute('data-tumpuk')).toBe(true);

        const css = readFileSync(join(AKAR, 'Gaya/Aplikasi.css'), 'utf8');

        expect(css).toMatch(/@media \(max-width: 1023px\)\s*\{\s*\.tabel-form\[data-tumpuk\]/);
        expect(css).toMatch(
            /\.tabel-form\[data-tumpuk\] tbody > tr > td\[data-label\]::before\s*\{[^}]*attr\(data-label\)/,
        );
    });

    it('tumpuk: tabel isian dokumen berkolom banyak (D-289) tidak kembali bergulir ke samping di HP', () => {
        const wajib = [
            'Komponen/Pembelian/IsianBarisPembelian.tsx',
            'Halaman/Kelola/Persediaan/StokAwal/Form.tsx',
            'Halaman/Kelola/Akuntansi/JurnalUmum/Buat.tsx',
            'Halaman/Kelola/Pembelian/NotaDebit/Buat.tsx',
            'Halaman/Kelola/Pembelian/BiayaTambahan/Buat.tsx',
            'Komponen/Katalog/TabelHargaBertingkat.tsx',
        ];
        const belum = wajib.filter((berkas) => !/<TabelForm\s+tumpuk/.test(readFileSync(join(AKAR, berkas), 'utf8')));

        expect(belum).toEqual([]);
    });

    it('tumpuk: setiap halaman yang memakainya memberi judul kolom (data-label) pada selnya', () => {
        const tanpaLabel: string[] = [];

        for (const jalur of BerkasSumber(AKAR)) {
            const isi = readFileSync(jalur, 'utf8');

            if (/<TabelForm\s+tumpuk/.test(isi) && !isi.includes('data-label=')) {
                tanpaLabel.push(jalur.replace(`${AKAR}/`, ''));
            }
        }

        expect(tanpaLabel).toEqual([]);
    });
});
