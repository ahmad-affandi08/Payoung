import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * Penjaga keterangan di bawah judul halaman back-office tenant (D-292). File penjaga: kalau test ini gagal, perbaiki
 * halamannya, bukan test-nya.
 *
 * CSS `.badan-halaman` memasang keterangan rapat ke judul dengan huruf keterangan hanya bila `<p>` itu anak langsung badan
 * halaman (`h1 + p`). Keterangan yang dibungkus `<div>`, atau ditaruh di slot `keterangan` milik `AksiHalaman`, tampil
 * lebih besar dan berjarak dari judul. Dulu ±30 halaman begitu (Toko online, semua halaman "Tambah …", Satuan, dst.).
 */

const AKAR = 'resources/js/Halaman/Kelola';

/** Halaman yang sah menaruh teks di slot `keterangan` AksiHalaman: penghitung status, bukan keterangan halaman. */
const PENGECUALIAN_SLOT = new Set(['Grosir/Kanvas/Daftar.tsx']);

function BerkasTsx(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((isi) => {
        const jalur = join(folder, isi.name);

        if (isi.isDirectory()) {
            return BerkasTsx(jalur);
        }

        return jalur.endsWith('.tsx') && !jalur.endsWith('Tes.tsx') ? [jalur] : [];
    });
}

function Ringkas(jalur: string): string {
    return jalur.replace(`${AKAR}/`, '');
}

/** Indeks tepat setelah tag pembuka yang dimulai di `mulai` (kurung kurawal di atribut dihitung bersarang). */
function AkhirTag(isi: string, mulai: number): number {
    let kedalaman = 0;
    let kutip: string | null = null;

    for (let i = mulai; i < isi.length; i += 1) {
        const huruf = isi[i] as string;

        if (kutip !== null) {
            if (huruf === kutip && isi[i - 1] !== '\\') {
                kutip = null;
            }
        } else if ((huruf === '"' || huruf === "'" || huruf === '`') && kedalaman > 0) {
            kutip = huruf;
        } else if (huruf === '{') {
            kedalaman += 1;
        } else if (huruf === '}') {
            kedalaman -= 1;
        } else if (huruf === '>' && kedalaman === 0) {
            return i + 1;
        }
    }

    return -1;
}

describe('Keterangan di bawah judul halaman', () => {
    it('tidak ditaruh di slot keterangan AksiHalaman (aksi saja di sana)', () => {
        const pelanggar: string[] = [];

        for (const jalur of BerkasTsx(AKAR)) {
            if (PENGECUALIAN_SLOT.has(Ringkas(jalur))) {
                continue;
            }

            if (/<AksiHalaman\s+keterangan=\{\s*<p\b/.test(readFileSync(jalur, 'utf8'))) {
                pelanggar.push(Ringkas(jalur));
            }
        }

        expect(pelanggar).toEqual([]);
    });

    it('tidak dibungkus <div> pertama di dalam TataLetakAplikasi', () => {
        const pelanggar: string[] = [];

        for (const jalur of BerkasTsx(AKAR)) {
            const isi = readFileSync(jalur, 'utf8');
            const awal = isi.indexOf('<TataLetakAplikasi');

            if (awal === -1) {
                continue;
            }

            const akhir = AkhirTag(isi, awal);
            const sisa = isi
                .slice(akhir, akhir + 900)
                .replace(/^\s*(<Head[^>]*\/>\s*)*/, '')
                .trimStart();

            if (!/^<div\b[^>]*>/.test(sisa)) {
                continue;
            }

            const paragraf = /<p\b[^>]*className="([^"]*)"/.exec(sisa);
            const bagian = /<(h2|Panel|form|TabelData|Card)\b/.exec(sisa);

            if (
                paragraf !== null &&
                paragraf[1]?.includes('teks-sekunder') &&
                (bagian === null || paragraf.index < bagian.index)
            ) {
                pelanggar.push(Ringkas(jalur));
            }
        }

        expect(pelanggar).toEqual([]);
    });
});
