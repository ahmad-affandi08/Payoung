/// <reference types="node" />
import { cleanup, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import BidangPilihan from './BidangPilihan';
import BidangTeks from './BidangTeks';
import GrupCentang from './GrupCentang';

/*
 * Tanda * merah otomatis untuk bidang wajib (PRD v1.77). jsdom tidak merender ::after, jadi yang diuji:
 * (1) aturan CSS-nya ada di Gaya/Aplikasi.css, dan (2) komponen menandai kontrolnya sehingga selektor itu cocok.
 */

// Vitest berjalan dari akar Aplikasi/Web (lokasi vite.config.ts).
const css = readFileSync(join(process.cwd(), 'resources', 'js', 'Gaya', 'Aplikasi.css'), 'utf8');

describe('Tanda wajib otomatis (*)', () => {
    afterEach(() => cleanup());

    it('Aplikasi.css memberi * merah pada label Field berisi kontrol wajib dan legenda fieldset wajib', () => {
        expect(css).toContain(
            "[data-slot='field']:has(:is(input, textarea, select)[required], [aria-required='true'], [data-wajib])",
        );
        expect(css).toContain("[data-slot='field-set'][data-wajib] > [data-slot='field-legend']::after");
        expect(css).toMatch(/content: ' \*' \/ '';\s*color: var\(--color-bahaya\);/);
        // Label + InputGroup di luar Field (BidangJumlah, BidangHpp, PemilihTanggal).
        expect(css).toContain("+ [data-slot='input-group'] :is(input, textarea, select)[required]");
    });

    it('BidangTeks required: input wajib berada di Field yang sama dengan labelnya', () => {
        render(<BidangTeks label="Nama usaha" nilai="" saatBerubah={() => undefined} required />);

        const input = screen.getByLabelText('Nama usaha');
        expect(input.hasAttribute('required')).toBe(true);
        const field = input.closest('[data-slot="field"]');
        expect(field?.querySelector(':scope > [data-slot="field-label"]')?.textContent).toBe('Nama usaha');
    });

    it('BidangPilihan required: pemicu diberi aria-required & data-wajib, tanpa required tidak', () => {
        const opsi = [{ Nilai: '33.74', Label: 'Kota Semarang' }];
        const { rerender: RenderUlang } = render(
            <BidangPilihan label="Kota" nilai="" opsi={opsi} saatBerubah={() => undefined} required />,
        );

        const pemicu = screen.getByRole('combobox', { name: 'Kota' });
        expect(pemicu.getAttribute('aria-required')).toBe('true');
        expect(pemicu.hasAttribute('data-wajib')).toBe(true);

        RenderUlang(<BidangPilihan label="Kota" nilai="" opsi={opsi} saatBerubah={() => undefined} />);
        expect(pemicu.hasAttribute('aria-required')).toBe(false);
        expect(pemicu.hasAttribute('data-wajib')).toBe(false);
    });

    it('GrupCentang required: fieldset diberi data-wajib', () => {
        render(
            <GrupCentang
                legenda="Peran"
                opsi={[{ nilai: 'kasir', label: 'Kasir' }]}
                terpilih={[]}
                saatBerubah={() => undefined}
                required
            />,
        );

        expect(screen.getByRole('group', { name: 'Peran' }).hasAttribute('data-wajib')).toBe(true);
    });
});
