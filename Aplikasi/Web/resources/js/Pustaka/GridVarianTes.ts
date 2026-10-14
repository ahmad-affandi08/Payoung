import { describe, expect, it } from 'vitest';

import { AdaStok, KunciSel, RingkasStok, SusunGridVarian } from '@/Pustaka/GridVarian';
import type { BarisVarian } from '@/Tipe/Katalog';

function Varian(uuid: string, ukuran: string, warna: string): BarisVarian {
    return {
        Uuid: uuid,
        Nama: `Kaos ${ukuran} ${warna}`,
        Sku: null,
        Atribut: [
            { Nama: 'Ukuran', Nilai: ukuran },
            { Nama: 'Warna', Nilai: warna },
        ],
        HargaDasar: '75000.00',
        Status: 'Aktif',
    };
}

const definisi = [
    { Nama: 'Ukuran', Nilai: ['S', 'M', 'L', 'XL'] },
    { Nama: 'Warna', Nilai: ['Hitam', 'Putih'] },
];

describe('Grid ukuran × warna (§9.4, D-143)', () => {
    it('urutan nilai mengikuti definisi induk, bukan abjad; kombinasi yang belum jadi varian tidak punya sel', () => {
        const grid = SusunGridVarian(
            [
                Varian('V1', 'XL', 'Putih'),
                Varian('V2', 'L', 'Hitam'),
                Varian('V3', 'S', 'Hitam'),
                Varian('V4', 'M', 'Putih'),
            ],
            definisi,
        );

        expect(grid).not.toBeNull();
        expect(grid?.NamaBaris).toBe('Ukuran');
        expect(grid?.NamaKolom).toBe('Warna');
        expect(grid?.NilaiBaris).toEqual(['S', 'M', 'L', 'XL']);
        expect(grid?.NilaiKolom).toEqual(['Hitam', 'Putih']);
        expect(grid?.Sel[KunciSel('s', 'HITAM')]?.Uuid).toBe('V3');
        expect(grid?.Sel[KunciSel('S', 'Putih')]).toBeUndefined();
    });

    it('nilai yang tidak ada di definisi tetap ikut (ditambahkan di belakang)', () => {
        const grid = SusunGridVarian([Varian('V1', 'S', 'Hitam'), Varian('V2', 'XXL', 'Hitam')], definisi);

        expect(grid?.NilaiBaris).toEqual(['S', 'XXL']);
    });

    it('tidak berlaku: tanpa varian, satu atau tiga atribut, atau atribut berbeda antarvarian', () => {
        expect(SusunGridVarian([], definisi)).toBeNull();

        const satu: BarisVarian = { ...Varian('V1', 'S', 'Hitam'), Atribut: [{ Nama: 'Ukuran', Nilai: 'S' }] };
        expect(SusunGridVarian([satu], definisi)).toBeNull();

        const tiga: BarisVarian = {
            ...Varian('V1', 'S', 'Hitam'),
            Atribut: [...Varian('V1', 'S', 'Hitam').Atribut, { Nama: 'Bahan', Nilai: 'Katun' }],
        };
        expect(SusunGridVarian([tiga], definisi)).toBeNull();

        const beda: BarisVarian = {
            ...Varian('V2', 'M', 'Hitam'),
            Atribut: [
                { Nama: 'Ukuran', Nilai: 'M' },
                { Nama: 'Motif', Nilai: 'Polos' },
            ],
        };
        expect(SusunGridVarian([Varian('V1', 'S', 'Hitam'), beda], definisi)).toBeNull();
    });

    it('stok: teks pendek tanpa nol berlebih dan deteksi stok positif tanpa float', () => {
        expect(RingkasStok('10.0000')).toBe('10');
        expect(RingkasStok('2.5000')).toBe('2,5');
        expect(RingkasStok(undefined)).toBe('0');
        expect(AdaStok('10.0000')).toBe(true);
        expect(AdaStok('0.0000')).toBe(false);
        expect(AdaStok('-3.0000')).toBe(false);
        expect(AdaStok(undefined)).toBe(false);
    });
});
