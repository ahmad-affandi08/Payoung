import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * Penjaga tinggi tombol (D-201). File penjaga: kalau test ini gagal, perbaiki halamannya, bukan test-nya.
 *
 * Regresi yang dijaga: tombol bersebelahan berbeda tinggi (di Rekap gaji, "Ekspor" 36px di sebelah "Cetak semua
 * slip" 32px) karena satu tombol menimpa tinggi lewat `className="h-9"`. Tinggi tombol hanya boleh datang dari
 * `Tombol`/`Button` ukuran baku (`h-8 pointer-coarse:h-11`); tombol `variant="link"` tanpa bingkai dikecualikan.
 */

const AKAR = 'resources/js';

function BerkasHalaman(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((isi) => {
        const jalur = join(folder, isi.name);

        if (isi.isDirectory()) {
            return isi.name === 'Ui' ? [] : BerkasHalaman(jalur);
        }

        return jalur.endsWith('.tsx') && !/Tes\.tsx$/.test(isi.name) ? [jalur] : [];
    });
}

describe('tinggi tombol (D-201)', () => {
    it('tidak ada Button/Tombol/TombolEkspor yang menimpa tinggi bawaan dengan h-6/7/9/10/12', () => {
        const pelanggaran = BerkasHalaman(AKAR).flatMap((jalur) => {
            const isi = readFileSync(jalur, 'utf8');
            const buka = isi.matchAll(/<(Button|Tombol|TombolEkspor)\b[^>]*?className=(?:"([^"]*)"|\{`([^`]*)`\})/gs);

            return [...buka]
                .filter((cocok) => !/variant="link"/.test(cocok[0]))
                .filter((cocok) => /(^|\s)h-(6|7|9|10|12)(\s|$)/.test(cocok[2] ?? cocok[3] ?? ''))
                .map(() => jalur);
        });

        expect(pelanggaran).toEqual([]);
    });

    it('TombolEkspor memakai ukuran baku, bukan size="sm"', () => {
        const isi = readFileSync(join(AKAR, 'Komponen/Laporan/TombolEkspor.tsx'), 'utf8');

        expect(isi).not.toMatch(/size="sm"/);
        expect(isi).toContain('h-8 pointer-coarse:h-11');
    });
});
