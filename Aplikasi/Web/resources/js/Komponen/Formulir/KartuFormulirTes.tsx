import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import KartuFormulir from './KartuFormulir';

/*
 * Penjaga keterangan halaman "Tambah …" (D-292). CSS `.badan-halaman` hanya memasang keterangan rapat ke judul bila
 * `<p>` itu anak langsung badan halaman. Pembungkus `<div>` membuatnya tampil besar dan berjarak dari judul.
 */
afterEach(cleanup);

describe('KartuFormulir', () => {
    it('keterangan dan kartu menjadi anak langsung badan halaman, tanpa pembungkus', () => {
        render(
            <div data-testid="badan">
                <KartuFormulir keterangan="Untuk apa data ini dipakai.">
                    <span>Isi formulir</span>
                </KartuFormulir>
            </div>,
        );

        const badan = screen.getByTestId('badan');
        const keterangan = screen.getByText('Untuk apa data ini dipakai.');

        expect(keterangan.tagName).toBe('P');
        expect(keterangan.parentElement).toBe(badan);
        expect(screen.getByText('Isi formulir').closest('[data-slot="card"]')?.parentElement).toBe(badan);
    });

    it('tanpa keterangan hanya kartu yang dirender', () => {
        render(
            <div data-testid="badan">
                <KartuFormulir>
                    <span>Isi</span>
                </KartuFormulir>
            </div>,
        );

        expect(screen.getByTestId('badan').querySelector('p')).toBeNull();
    });
});
