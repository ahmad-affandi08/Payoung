import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

/*
 * Penjaga bahasa antarmuka (D-227). File penjaga: kalau test ini gagal, tulis ulang kalimatnya, bukan test-nya.
 *
 * Teks yang dibaca pengguna tidak memakai titik koma ("; ") untuk menyambung dua kalimat. Itu ciri tulisan mesin dan
 * jarang dipakai orang Indonesia sehari-hari. Pecah menjadi dua kalimat, atau sambung dengan koma dan kata sambung.
 * Yang diperiksa hanya literal teks dan teks JSX, bukan kode, jadi tipe `{ a: string; b: number }` aman.
 */

const AKAR = 'resources/js';

function BerkasSumber(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((isi) => {
        const jalur = join(folder, isi.name);

        if (isi.isDirectory()) {
            return BerkasSumber(jalur);
        }

        return /\.tsx?$/.test(isi.name) && !/Tes\.tsx?$/.test(isi.name) ? [jalur] : [];
    });
}

/** Teknis (CSP, User-Agent, cookie, daftar atribut) atau terlalu pendek: bukan kalimat untuk dibaca pengguna. */
function BukanProsa(teks: string): boolean {
    return (
        /&\w+;|Mozilla|AppleWebKit|Gecko|-src\b|frame-ancestors|base-uri|object-src|'self'|charset|Max-Age|Path=|SameSite|=\S*;\s/i.test(
            teks,
        ) ||
        (teks.match(/\s/g) ?? []).length < 2 ||
        /[\w-]+\s*:\s*[^;\n]{1,40};\s*[\w-]+\s*:/.test(teks) ||
        teks
            .split('; ')
            .slice(1)
            .some((bagian) => !/\s/.test(bagian.trim()))
    );
}

describe('Bahasa antarmuka tanpa titik koma', () => {
    it('tidak ada literal teks atau teks JSX yang menyambung kalimat dengan "; "', () => {
        const pelanggar: string[] = [];

        for (const jalur of BerkasSumber(AKAR)) {
            const isi = readFileSync(jalur, 'utf8');
            const sf = ts.createSourceFile(
                jalur,
                isi,
                ts.ScriptTarget.Latest,
                true,
                jalur.endsWith('x') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
            );
            const Kunjungi = (simpul: ts.Node): void => {
                const jenis = simpul.kind;

                if (
                    jenis === ts.SyntaxKind.StringLiteral ||
                    jenis === ts.SyntaxKind.NoSubstitutionTemplateLiteral ||
                    jenis === ts.SyntaxKind.TemplateHead ||
                    jenis === ts.SyntaxKind.TemplateMiddle ||
                    jenis === ts.SyntaxKind.TemplateTail ||
                    jenis === ts.SyntaxKind.JsxText
                ) {
                    const teks = isi.slice(simpul.getStart(sf), simpul.getEnd());

                    if (/(^\s*|[A-Za-z0-9)%\]}]); [a-z](?![\w]*[=:-])/.test(teks) && !BukanProsa(teks)) {
                        const baris = sf.getLineAndCharacterOfPosition(simpul.getStart(sf)).line + 1;
                        pelanggar.push(`${jalur}:${String(baris)}`);
                    }
                }

                ts.forEachChild(simpul, Kunjungi);
            };
            Kunjungi(sf);
        }

        expect(pelanggar).toEqual([]);
    }, 60_000);
});
