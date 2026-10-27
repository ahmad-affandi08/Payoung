import { render, screen } from '@testing-library/react';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import AksiHalaman from './AksiHalaman';

/*
 * Penjaga posisi tombol aksi halaman (D-27). File penjaga: kalau test ini gagal, perbaiki halamannya, bukan test-nya.
 *
 * Latar belakang: tiap halaman daftar merakit baris aksinya sendiri. Sebagian memakai `justify-between` sehingga
 * tombol "Tambah" di kanan, sebagian hanya `<div>` biasa sehingga tombolnya di kiri. Akibatnya posisi tombol
 * berpindah-pindah antar halaman dan pengguna harus mencarinya tiap kali.
 */

describe('AksiHalaman', () => {
    it('aksi rata kanan walau tanpa keterangan', () => {
        render(
            <AksiHalaman>
                <button type="button">Tambah sesuatu</button>
            </AksiHalaman>,
        );

        const tombol = screen.getByRole('button', { name: 'Tambah sesuatu' });
        // `ml-auto` (bukan `justify-between`) supaya tetap kanan meski keterangan tidak diisi.
        expect(tombol.parentElement?.className).toContain('ml-auto');
    });

    it('keterangan di kiri, aksi tetap di kanan', () => {
        render(
            <AksiHalaman keterangan={<p>Keterangan halaman</p>}>
                <button type="button">Tambah sesuatu</button>
            </AksiHalaman>,
        );

        const baris = screen.getByText('Keterangan halaman').parentElement;
        expect(baris?.firstElementChild?.textContent).toBe('Keterangan halaman');
        expect(baris?.lastElementChild?.className).toContain('ml-auto');
    });
});

/** Semua berkas `.tsx` di bawah folder ini, rekursif. */
function BerkasTsx(folder: string): string[] {
    return readdirSync(folder, { withFileTypes: true }).flatMap((isi) => {
        const jalur = join(folder, isi.name);

        if (isi.isDirectory()) {
            return BerkasTsx(jalur);
        }

        return isi.name.endsWith('.tsx') ? [jalur] : [];
    });
}

/*
 * D-28: konsol ikut dijaga. Sebelumnya penjaga ini hanya memeriksa `Halaman/Kelola`, jadi Platform Pengelola
 * memakai pola sendiri — 17 halaman daftarnya menaruh tombol utama di kepala halaman lewat prop `aksi`
 * `TataLetakPengelola`, sementara back-office tenant sudah memakai baris sendiri di atas tabel (D-27).
 */
const FOLDER_HALAMAN = ['resources/js/Halaman/Kelola', 'resources/js/Halaman/Pengelola'];

/** Semua berkas halaman back-office (tenant & konsol), bukan berkas penjaga. */
function BerkasBackOffice(): string[] {
    return FOLDER_HALAMAN.flatMap((folder) => BerkasTsx(folder)).filter((jalur) => !/Tes\.tsx$/.test(jalur));
}

/** Jalur tanpa awalan folder, supaya pesan gagalnya pendek. */
function Ringkas(jalur: string): string {
    return jalur.replace('resources/js/Halaman/', '');
}

describe('Baris aksi di halaman daftar', () => {
    it('tidak ada tombol Tambah/Buat yang dibungkus <div> telanjang (bikin rata kiri)', () => {
        const pelanggar: string[] = [];

        for (const jalur of BerkasBackOffice()) {
            const isi = readFileSync(jalur, 'utf8');
            const batasTabel = isi.indexOf('<TabelData');

            if (batasTabel === -1) {
                continue;
            }

            // Hanya baris aksi di atas tabel; tombol simpan di dalam <form> memang rata kiri dan bukan urusan ini.
            const kepala = isi.slice(0, batasTabel);
            const sebelumForm = kepala.includes('<form') ? kepala.slice(0, kepala.indexOf('<form')) : kepala;

            const bungkusVariabel = /<div>\{\w*[tT]ombol\w*\}<\/div>/.test(sebelumForm);

            // Isi setiap `<div>` telanjang diperiksa sampai `</div>`-nya saja. Tanpa batas itu, pencocokan
            // melompati elemen lain dan tautan "Kembali ke ..." (yang memang benar di kiri) ikut tertuduh.
            const bungkusLangsung = sebelumForm
                .split('<div>')
                .slice(1)
                .map((potongan) => potongan.split('</div>')[0] ?? '')
                .some((isiDiv) => /<(?:Button|Tombol)\b/.test(isiDiv) && />\s*(?:Tambah|Buat) /.test(isiDiv));

            if (bungkusVariabel || bungkusLangsung) {
                pelanggar.push(Ringkas(jalur));
            }
        }

        expect(pelanggar).toEqual([]);
    });

    it('tombol utama tidak dititipkan ke bilah alat tabel (aksiAlat)', () => {
        // D-27: bilah alat berisi kontrol yang bekerja pada isi tabel (cari, saring, atur kolom, ekspor). Tombol
        // utama di sana membuat tingginya berbeda dari halaman lain dan bisa terkubur saat bilahnya membungkus di HP.
        const pelanggar: string[] = [];

        for (const jalur of BerkasBackOffice()) {
            if (readFileSync(jalur, 'utf8').includes('aksiAlat')) {
                pelanggar.push(Ringkas(jalur));
            }
        }

        expect(pelanggar).toEqual([]);
    });

    it('setiap halaman daftar dengan aksi utama memakai AksiHalaman', () => {
        const pelanggar: string[] = [];

        for (const jalur of BerkasBackOffice()) {
            const isi = readFileSync(jalur, 'utf8');
            const batasTabel = isi.indexOf('<TabelData');

            if (batasTabel === -1) {
                continue;
            }

            const kepala = isi.slice(0, batasTabel);
            const sebelumForm = kepala.includes('<form') ? kepala.slice(0, kepala.indexOf('<form')) : kepala;
            const adaAksi = /<(?:Button|Tombol)\b[\s\S]{0,200}?>\s*(?:Tambah|Buat|Mulai) /.test(sebelumForm);

            if (adaAksi && !isi.includes('AksiHalaman')) {
                pelanggar.push(Ringkas(jalur));
            }
        }

        expect(pelanggar).toEqual([]);
    });

    it('halaman daftar konsol tidak menitipkan aksi utama ke kepala halaman', () => {
        // Prop `aksi` `TataLetakPengelola` disisakan untuk halaman rincian/formulir. Di halaman daftar, tombol
        // utamanya harus berada di baris sendiri di atas tabel — sama seperti back-office tenant (D-27).
        const pelanggar: string[] = [];

        for (const jalur of BerkasTsx('resources/js/Halaman/Pengelola')) {
            if (/Tes\.tsx$/.test(jalur)) {
                continue;
            }

            const isi = readFileSync(jalur, 'utf8');

            if (!isi.includes('<TabelData')) {
                continue;
            }

            // Prop `aksi` milik tata letak, bukan `aksi` pada `Panel` di dalam halaman.
            const tag = /<TataLetakPengelola\b[\s\S]*?\n\s*>/.exec(isi)?.[0] ?? '';

            if (/\n\s*aksi=\{/.test(tag)) {
                pelanggar.push(Ringkas(jalur));
            }
        }

        expect(pelanggar).toEqual([]);
    });
    it('halaman bertabel tidak menumpuk dua tombol atau lebih sebagai anak AksiHalaman (D-228)', () => {
        // Anak AksiHalaman di HP dipindahkan apa adanya ke baris Filter bilah alat tabel. Dua tombol atau lebih membuat
        // barisnya sesak (dulu bahkan terpotong di kiri layar). Aksi utama masuk `utama`/`langkah`, sisanya `lainnya`
        // supaya di HP jadi menu "Lainnya".
        const pelanggar: string[] = [];

        for (const jalur of BerkasBackOffice()) {
            const isi = readFileSync(jalur, 'utf8');

            if (!isi.includes('<TabelData')) {
                continue;
            }

            let dari = 0;

            for (;;) {
                const awal = isi.indexOf('<AksiHalaman', dari);

                if (awal === -1) {
                    break;
                }

                // Akhir tag pembuka: ">" pertama di luar kurung kurawal (atribut bisa memuat JSX dan panah fungsi).
                let kedalaman = 0;
                let akhirTag = awal + '<AksiHalaman'.length;

                for (; akhirTag < isi.length; akhirTag += 1) {
                    const huruf = isi[akhirTag];

                    if (huruf === '{') {
                        kedalaman += 1;
                    } else if (huruf === '}') {
                        kedalaman -= 1;
                    } else if (huruf === '>' && kedalaman === 0) {
                        break;
                    }
                }

                dari = akhirTag;

                if (isi[akhirTag - 1] === '/') {
                    continue;
                }

                const tutup = isi.indexOf('</AksiHalaman>', akhirTag);
                let anak = isi.slice(akhirTag + 1, tutup);
                const variabel = /^\s*\{(\w+)\}\s*$/.exec(anak)?.[1];

                if (variabel !== undefined) {
                    // Definisi `const x = ...;` sampai titik koma di luar tanda kurung.
                    const mulai = isi.indexOf(`const ${variabel}`);
                    let dalam = 0;
                    let selesai = mulai;

                    for (; selesai < isi.length; selesai += 1) {
                        const huruf = isi[selesai];

                        if (huruf === '(' || huruf === '{' || huruf === '[') {
                            dalam += 1;
                        } else if (huruf === ')' || huruf === '}' || huruf === ']') {
                            dalam -= 1;
                        } else if (huruf === ';' && dalam === 0) {
                            break;
                        }
                    }

                    anak = mulai === -1 ? '' : isi.slice(mulai, selesai);
                }

                if ((anak.match(/<(?:Tombol|Button|TombolBuat)\b/g) ?? []).length >= 2) {
                    pelanggar.push(Ringkas(jalur));
                }
            }
        }

        expect(pelanggar).toEqual([]);
    });
});
