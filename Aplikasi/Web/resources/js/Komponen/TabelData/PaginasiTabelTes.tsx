import { describe, expect, it } from 'vitest';

import { DaftarHalaman } from './PaginasiTabel';

describe('DaftarHalaman (D-276)', () => {
    it('sampai tujuh halaman semuanya ditampilkan', () => {
        expect(DaftarHalaman(1, 1)).toEqual([1]);
        expect(DaftarHalaman(3, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    });

    it('halaman awal: tetangga kanan, elipsis, lalu halaman terakhir', () => {
        expect(DaftarHalaman(1, 33)).toEqual([1, 2, '…', 33]);
    });

    it('halaman tengah: pertama, elipsis, tetangga kiri-kanan, elipsis, terakhir', () => {
        expect(DaftarHalaman(15, 33)).toEqual([1, '…', 14, 15, 16, '…', 33]);
    });

    it('celah satu halaman diisi nomornya, bukan elipsis', () => {
        expect(DaftarHalaman(4, 33)).toEqual([1, 2, 3, 4, 5, '…', 33]);
        expect(DaftarHalaman(30, 33)).toEqual([1, '…', 29, 30, 31, 32, 33]);
    });

    it('halaman terakhir: halaman pertama, elipsis, lalu dua halaman terakhir', () => {
        expect(DaftarHalaman(33, 33)).toEqual([1, '…', 32, 33]);
    });
});
