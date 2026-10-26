import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import LabelStatus from './LabelStatus';
import Pemberitahuan from './Pemberitahuan';

describe('komponen Umpan di atas shadcn/ui (API lama tetap)', () => {
    afterEach(() => cleanup());

    it('Pemberitahuan: bahaya & peringatan = alert, info & sukses = status. Judul bawaan berupa teks', () => {
        render(
            <>
                <Pemberitahuan jenis="bahaya">Gagal menyimpan.</Pemberitahuan>
                <Pemberitahuan jenis="peringatan" judul="Tagihan belum dibayar">
                    Bayar sebelum 27 Sep.
                </Pemberitahuan>
                <Pemberitahuan jenis="sukses">Produk disimpan.</Pemberitahuan>
                <Pemberitahuan jenis="info">Info saja.</Pemberitahuan>
            </>,
        );

        const peringatan = screen.getAllByRole('alert');
        expect(peringatan).toHaveLength(2);
        expect(peringatan[0]?.textContent).toBe('Ada masalah: Gagal menyimpan.');
        expect(peringatan[1]?.textContent).toContain('Tagihan belum dibayar');
        const status = screen.getAllByRole('status');
        expect(status.map((elemen) => elemen.textContent)).toEqual(['Berhasil: Produk disimpan.', 'Info: Info saja.']);
        expect(peringatan[0]?.getAttribute('data-slot')).toBe('alert');
    });

    it('LabelStatus: Badge berisi teks status', () => {
        render(<LabelStatus jenis="sukses" teks="Selesai" />);

        const label = screen.getByText('Selesai');
        expect(label.getAttribute('data-slot')).toBe('badge');
        expect(label.className).toContain('text-sukses');
    });
});
