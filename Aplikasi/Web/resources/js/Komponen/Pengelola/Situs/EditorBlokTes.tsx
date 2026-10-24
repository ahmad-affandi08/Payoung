import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import EditorBlok, { BuatNilaiKosong } from './EditorBlok';
import type { NilaiBlok, SkemaBlok } from './Tipe';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
    usePage: () => ({ props: { Gambar: [], errors: {} }, url: '/situs/halaman' }),
}));

const skemaKeunggulan: SkemaBlok = {
    Judul: ['Teks', 140],
    Kolom: ['Pilihan', ['2', '3', '4']],
    TombolUtama: ['Tombol'],
    Item: ['Daftar', 1, 3, { Ikon: ['Ikon'], Judul: ['Teks', 80, true] }],
};

afterEach(cleanup);

describe('Editor blok situs D-21', () => {
    it('nilai kosong mengikuti skema: daftar diisi item minimum, tombol berisi label & tautan', () => {
        expect(BuatNilaiKosong(skemaKeunggulan)).toEqual({
            Judul: '',
            Kolom: '',
            TombolUtama: { Label: '', Tautan: '' },
            Item: [{ Ikon: null, Judul: '' }],
        });
    });

    it('mengubah judul, menambah item sampai batas, dan menampilkan masalah per bidang', () => {
        let blok: NilaiBlok = { Jenis: 'Keunggulan', ...BuatNilaiKosong(skemaKeunggulan) };
        const TiruanBerubah = vi.fn((b: NilaiBlok) => {
            blok = b;
        });
        const Render = () =>
            render(
                <EditorBlok
                    indeks={0}
                    jumlah={1}
                    blok={blok}
                    skema={skemaKeunggulan}
                    labelJenis="Keunggulan"
                    galat={{ 'Bagian.0.Item.0.Judul': 'Wajib diisi.' }}
                    ikon={['Zap']}
                    bolehUbah
                    saatBerubah={TiruanBerubah}
                    saatPindah={vi.fn()}
                    saatGandakan={vi.fn()}
                    saatHapus={vi.fn()}
                />,
            );
        Render();

        expect(screen.getByText('Wajib diisi.')).toBeTruthy();
        expect(screen.getByText(/perlu diperbaiki/)).toBeTruthy();

        fireEvent.change(screen.getAllByLabelText('Judul')[0] as HTMLElement, {
            target: { value: 'Tetap jalan offline' },
        });
        expect(blok.Judul).toBe('Tetap jalan offline');

        fireEvent.click(screen.getByRole('button', { name: /Tambah item/ }));
        expect((blok.Item as NilaiBlok[]).length).toBe(2);

        cleanup();
        blok = {
            ...blok,
            Item: [
                { Ikon: null, Judul: 'a' },
                { Ikon: null, Judul: 'b' },
                { Ikon: null, Judul: 'c' },
            ],
        };
        Render();
        expect(screen.queryByRole('button', { name: /Tambah item/ })).toBeNull();
    });
});
