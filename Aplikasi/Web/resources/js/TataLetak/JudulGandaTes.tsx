import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * Penjaga judul halaman tunggal (D-28). File penjaga: kalau test ini gagal, perbaiki halamannya, bukan test-nya.
 *
 * Regresi yang dijaga: `TataLetakAplikasi`, `TataLetakPengelola`, dan `TataLetakPanduanAwal` sudah merender `judul`
 * sebagai `<h1>` di atas isi halaman. Halaman yang masih merender `<JudulHalaman>`/`<h1>` sendiri menampilkan judul
 * yang sama dua kali (contoh nyata: "Toko online & pengiriman") dan memberi pembaca layar dua `<h1>`.
 * Keterangan di bawah judul tetap sah — tulis sebagai paragraf biasa, bukan judul kedua.
 */

function BerkasTsx(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((isi) => {
        const jalur = join(folder, isi.name);

        if (isi.isDirectory()) {
            return BerkasTsx(jalur);
        }

        return isi.name.endsWith('.tsx') ? [jalur] : [];
    });
}

describe('Halaman tidak merender judul kedua di dalam tata letak', () => {
    it('tidak ada <JudulHalaman>/<h1> di halaman yang sudah dibungkus tata letak ber-judul', () => {
        const pelanggar = [...BerkasTsx('resources/js/Halaman'), ...BerkasTsx('resources/js/Komponen')]
            .filter((jalur) => {
                const isi = readFileSync(jalur, 'utf8');

                return /<TataLetak(Aplikasi|Pengelola|PanduanAwal)\b/.test(isi) && /<JudulHalaman\b|<h1\b/.test(isi);
            })
            .map((jalur) => jalur.replace('resources/js/', ''));

        expect(pelanggar).toEqual([]);
    });
});
