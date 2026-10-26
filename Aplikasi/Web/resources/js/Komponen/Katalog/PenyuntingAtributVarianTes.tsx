import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import PenyuntingAtributVarian from '@/Komponen/Katalog/PenyuntingAtributVarian';

describe('Penyunting atribut varian', () => {
    afterEach(() => cleanup());

    it('atribut pertama langsung tampil tanpa menekan Tambah atribut, dan baru tersimpan setelah diisi', () => {
        const SaatBerubah = vi.fn();
        render(<PenyuntingAtributVarian nilai={[]} saatBerubah={SaatBerubah} />);

        expect(screen.getByText('Atribut 1')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tambah atribut' })).toBeNull();
        expect(SaatBerubah).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText(/Nama atribut/), { target: { value: 'Ukuran' } });
        expect(SaatBerubah).toHaveBeenCalledWith([{ Nama: 'Ukuran', Nilai: [] }]);
    });

    it('mode baca saja tidak menampilkan isian kosong', () => {
        render(<PenyuntingAtributVarian nilai={[]} saatBerubah={vi.fn()} disabled />);

        expect(screen.queryByText('Atribut 1')).toBeNull();
    });

    it('tombol Tambah atribut muncul setelah ada atribut pertama', () => {
        render(<PenyuntingAtributVarian nilai={[{ Nama: 'Ukuran', Nilai: ['S'] }]} saatBerubah={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'Tambah atribut' })).toBeTruthy();
    });
});
