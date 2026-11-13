import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/*
 * Penjaga kontainer terluar di HP (D-285). File penjaga: kalau test ini gagal, perbaiki CSS-nya, bukan test-nya.
 *
 * Yang dijaga: di bawah 640px badan halaman back-office rapat sampai tepi layar (menembus gutter tata letak) dan
 * kartu di dalamnya diratakan, supaya tidak ada kontainer di dalam kontainer di dalam kontainer. Tautan dan tombol
 * tidak boleh ikut diratakan (hilangnya border membuatnya tampak seperti teks biasa).
 */

const css = readFileSync('resources/js/Gaya/Aplikasi.css', 'utf8');
const mulai = css.indexOf('@media (max-width: 639px) {\n    .badan-halaman {');
const blokHp = mulai === -1 ? '' : css.slice(mulai);

describe('Badan halaman di HP (D-285)', () => {
    it('kontainer terluar menembus gutter dan tanpa border sisi serta sudut', () => {
        expect(mulai).toBeGreaterThan(-1);
        expect(blokHp).toMatch(/\.badan-halaman\s*\{[^}]*margin-inline:\s*calc\(var\(--spacing\) \* -4\)/);
        expect(blokHp).toMatch(/\.badan-halaman\s*\{[^}]*border-inline-width:\s*0/);
        expect(blokHp).toMatch(/\.badan-halaman\s*\{[^}]*border-radius:\s*0/);
    });

    it('kartu di dalamnya diratakan tanpa border, sudut, bayangan, dan latar', () => {
        expect(blokHp).toMatch(/\[data-slot='card'\]/);
        expect(blokHp).toMatch(
            /border-width:\s*0;[^}]*border-radius:\s*0;[^}]*background:\s*transparent;[^}]*box-shadow:\s*none/,
        );
    });

    it('tautan, tombol, dan kotak bergaris putus atau berwarna merek tidak ikut diratakan', () => {
        expect(blokHp.replace(/\s+/g, ' ')).toContain(
            ".border-brand, .border-dashed, a, button, label, summary, [role='button']",
        );
    });

    it('kartu yang langsung di badan halaman dipisah garis tipis selebar layar', () => {
        expect(blokHp).toMatch(
            /border-top:\s*1px solid var\(--color-garis\);\s*padding-inline:\s*calc\(var\(--spacing\) \* 4\)/,
        );
    });
});
