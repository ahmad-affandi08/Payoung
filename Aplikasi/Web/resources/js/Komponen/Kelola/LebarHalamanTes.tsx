import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * Penjaga lebar blok halaman back-office. File penjaga: kalau test ini gagal, perbaiki halamannya, bukan test-nya.
 *
 * Latar belakang: tata letak sudah membatasi isi halaman (`max-w-6xl`, rata tengah). Beberapa halaman menyempitkannya
 * lagi dengan `max-w-2xl`/`max-w-3xl` pada `<form>`/`Card`/`Panel` dan membiarkannya rata kiri, sehingga sepertiga
 * sampai setengah sisi kanan layar kosong sementara halaman lain mengisi seluruh lebar (halaman Promo, Loyalti,
 * Aset tetap, Impor, Tiket bantuan, PIN). Blok halaman mengisi lebar isi; isian di dalamnya yang diatur lewat
 * `grid sm:grid-cols-2 lg:grid-cols-3`, bukan dengan menyempitkan wadahnya. Teks bacaan (`<p>`) tetap boleh
 * dibatasi, begitu pula kontrol tunggal seperti pemilih akun.
 */

const FOLDER_HALAMAN = ['resources/js/Halaman/Kelola', 'resources/js/Halaman/Pengelola'];

function BerkasTsx(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((entri) => {
        const jalur = join(folder, entri.name);

        return entri.isDirectory() ? BerkasTsx(jalur) : jalur.endsWith('.tsx') ? [jalur] : [];
    });
}

describe('Lebar blok halaman back-office', () => {
    it('<form>, Card, Panel, dan section tidak disempitkan dengan max-w-*', () => {
        const pelanggar: string[] = [];
        // Satu tag pembuka; `=>` di dalam properti dibuang dulu supaya `>` itu tidak dianggap penutup tag.
        const tag = /<(form|Card|Panel|section)\b((?:[^>]|=>)*?)>/g;

        for (const jalur of FOLDER_HALAMAN.flatMap((folder) => BerkasTsx(folder)).filter((j) => !/Tes\.tsx$/.test(j))) {
            const isi = readFileSync(jalur, 'utf8');

            for (const cocok of isi.matchAll(tag)) {
                if (/\bmax-w-(?:xs|sm|md|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl|\[)/.test(cocok[2] ?? '')) {
                    pelanggar.push(`${jalur.replace('resources/js/Halaman/', '')}: <${cocok[1] ?? ''}>`);
                }
            }
        }

        expect(pelanggar).toEqual([]);
    });
});
