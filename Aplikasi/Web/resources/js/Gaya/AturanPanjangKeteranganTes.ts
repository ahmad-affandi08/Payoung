import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

/*
 * Penjaga panjang keterangan (D-270). File penjaga: kalau test ini gagal, persingkat kalimatnya, bukan test-nya.
 *
 * Orang membuka back-office untuk bekerja, bukan membaca. Satu keterangan di halaman tenant paling banyak
 * BATAS_KATA kata (sasaran penulisan sekitar 15 kata). Penjelasan panjang, rumus, dan aturan internal dipindah ke
 * artikel Pusat Bantuan (`SiapkanArtikelBantuanBawaan`) dan dihubungkan lewat tombol Bantuan halaman.
 *
 * Yang diperiksa: teks JSX dan literal teks kalimat di halaman tenant (Halaman/Kelola), komponen bersama, dan tata
 * letak. Konsol Pengelola (alat internal petugas), situs pemasaran, halaman hukum, dan komponen `Ui` (kelas CSS)
 * tidak termasuk.
 */

const BATAS_KATA = 30;
const AKAR = 'resources/js';
const DIKECUALIKAN = [
    `${join(AKAR, 'Halaman', 'Pengelola')}`,
    `${join(AKAR, 'Halaman', 'Situs')}`,
    `${join(AKAR, 'Halaman', 'Publik')}`,
    `${join(AKAR, 'Komponen', 'Ui')}`,
    `${join(AKAR, 'Komponen', 'Situs')}`,
    `${join(AKAR, 'Komponen', 'Pengelola')}`,
];

function BerkasSumber(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((isi) => {
        const jalur = join(folder, isi.name);

        if (DIKECUALIKAN.includes(jalur)) {
            return [];
        }

        if (isi.isDirectory()) {
            return BerkasSumber(jalur);
        }

        return /\.tsx$/.test(isi.name) && !/Tes\.tsx$/.test(isi.name) ? [jalur] : [];
    });
}

/** Kalimat untuk dibaca orang: ada huruf biasa, tanda baca akhir, dan bukan daftar kelas CSS atau kode. */
function KalimatUntukManusia(teks: string): boolean {
    const kata = teks.split(' ');
    const teknis = kata.filter((k) => /[[\]:/=]|^-|\d-/.test(k)).length;

    return /[a-z]{3}/.test(teks) && /[.?!]/.test(teks) && teknis / kata.length < 0.2;
}

describe('Panjang keterangan di halaman tenant', () => {
    it(`tidak ada keterangan yang lebih dari ${String(BATAS_KATA)} kata`, () => {
        const pelanggar: string[] = [];

        for (const jalur of BerkasSumber(AKAR)) {
            const isi = readFileSync(jalur, 'utf8');
            const sf = ts.createSourceFile(jalur, isi, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
            const Kunjungi = (simpul: ts.Node): void => {
                const jenis = simpul.kind;

                if (
                    jenis === ts.SyntaxKind.JsxText ||
                    jenis === ts.SyntaxKind.StringLiteral ||
                    jenis === ts.SyntaxKind.NoSubstitutionTemplateLiteral
                ) {
                    const mentah =
                        jenis === ts.SyntaxKind.JsxText ? simpul.getText(sf) : (simpul as ts.StringLiteral).text;
                    const teks = mentah.replace(/\s+/g, ' ').trim();
                    const jumlah = teks === '' ? 0 : teks.split(' ').length;

                    if (jumlah > BATAS_KATA && KalimatUntukManusia(teks)) {
                        const baris = sf.getLineAndCharacterOfPosition(simpul.getStart(sf)).line + 1;
                        pelanggar.push(`${jalur}:${String(baris)} (${String(jumlah)} kata)`);
                    }
                }

                ts.forEachChild(simpul, Kunjungi);
            };
            Kunjungi(sf);
        }

        expect(pelanggar).toEqual([]);
    });
});
