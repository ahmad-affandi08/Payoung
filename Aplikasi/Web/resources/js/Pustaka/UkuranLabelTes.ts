import { describe, expect, it } from 'vitest';

import {
    BagiKeHalaman,
    CekMediaSah,
    HitungLebarBarcodeMm,
    HitungTataHalaman,
    HitungTataIsi,
    IsiLabelBawaan,
    MediaBawaan,
    MediaKustomBawaan,
    SebarkanStiker,
} from '@/Pustaka/UkuranLabel';

const tanpaGeser = { XMm: 0, YMm: 0 };

describe('UkuranLabel: media bawaan', () => {
    it('semua media bawaan sah dan muat di halamannya', () => {
        for (const media of MediaBawaan) {
            expect(CekMediaSah(media), media.Nama).toBeNull();
        }
    });

    it('A4 65 stiker: blok dipusatkan, 65 stiker per lembar', () => {
        const media = MediaBawaan.find((m) => m.Kode === 'A4-65');
        const tata = HitungTataHalaman(media as (typeof MediaBawaan)[number], tanpaGeser);

        expect(tata.PerHalaman).toBe(65);
        expect(tata.LebarHalamanMm).toBe(210);
        expect(tata.TinggiHalamanMm).toBe(297);
        // (210 − (5 × 38,1 + 4 × 2,5)) / 2 = 4,75; (297 − 13 × 21,2) / 2 = 10,7.
        expect(tata.KiriMm).toBe(4.75);
        expect(tata.AtasMm).toBe(10.7);
    });

    it('lembar 211 × 166 mm: 40 stiker 38 × 18 mm, 5 × 8 dengan jarak 4 × 2 mm', () => {
        const media = MediaBawaan.find((m) => m.Kode === 'LEMBAR-40-38x18') as (typeof MediaBawaan)[number];
        const tata = HitungTataHalaman(media, tanpaGeser);

        expect(CekMediaSah(media)).toBeNull();
        expect(tata.PerHalaman).toBe(40);
        expect(tata.LebarHalamanMm).toBe(211);
        expect(tata.TinggiHalamanMm).toBe(166);
        // (211 − (5 × 38 + 4 × 4)) / 2 = 2,5; (166 − (8 × 18 + 7 × 2)) / 2 = 4.
        expect(tata.KiriMm).toBe(2.5);
        expect(tata.AtasMm).toBe(4);
    });

    it('geseran kalibrasi menggeser blok', () => {
        const media = MediaBawaan[0] as (typeof MediaBawaan)[number];
        const tata = HitungTataHalaman(media, { XMm: 1.5, YMm: -2 });

        expect(tata.KiriMm).toBe(6.25);
        expect(tata.AtasMm).toBe(8.7);
    });

    it('gulung: halaman seukuran stiker; tiga berdampingan = satu baris tiga stiker', () => {
        const satu = HitungTataHalaman(
            MediaBawaan.find((m) => m.Kode === 'GULUNG-58x40') as (typeof MediaBawaan)[number],
            tanpaGeser,
        );
        const tiga = HitungTataHalaman(
            MediaBawaan.find((m) => m.Kode === 'GULUNG-33x15x3') as (typeof MediaBawaan)[number],
            tanpaGeser,
        );

        expect(satu).toMatchObject({ LebarHalamanMm: 58, TinggiHalamanMm: 40, PerHalaman: 1, KiriMm: 0, AtasMm: 0 });
        expect(tiga).toMatchObject({ LebarHalamanMm: 103, TinggiHalamanMm: 15, PerHalaman: 3 });
    });
});

describe('UkuranLabel: validasi media', () => {
    it('menolak ukuran di luar batas, angka tidak sah, dan susunan melebihi kertas', () => {
        const a4 = MediaKustomBawaan.A4;
        const gulung = MediaKustomBawaan.Gulung;

        expect(CekMediaSah(a4)).toBeNull();
        expect(CekMediaSah({ ...a4, LebarMm: 5 })).toMatch(/minimal/);
        expect(CekMediaSah({ ...a4, TinggiMm: 250 })).toMatch(/maksimal/);
        expect(CekMediaSah({ ...a4, LebarMm: Number.NaN })).toMatch(/angka/);
        expect(CekMediaSah({ ...a4, JarakXMm: -1 })).toMatch(/negatif/);
        expect(CekMediaSah({ ...a4, Kolom: 2.5 })).toMatch(/bulat/);
        expect(CekMediaSah({ ...a4, Kolom: 4, LebarMm: 60 })).toMatch(/lebih besar dari kertas A4/);
        expect(CekMediaSah({ ...a4, Baris: 31 })).toMatch(/maksimal 10 kolom dan 30 baris/);
        expect(CekMediaSah({ ...gulung, Kolom: 4 })).toMatch(/maksimal 3/);
        expect(CekMediaSah({ ...gulung, Kolom: 3 })).toBeNull();
    });
});

describe('UkuranLabel: sebar & bagi ke halaman', () => {
    it('menyebarkan stiker sesuai jumlah per baris, mengabaikan jumlah tak sah', () => {
        expect(SebarkanStiker([{ Jumlah: 2 }, { Jumlah: 0 }, { Jumlah: 3 }, { Jumlah: -1 }])).toEqual([0, 0, 2, 2, 2]);
    });

    it('membagi per halaman, melewati sel pertama, dan mengisi sel kosong di halaman terakhir', () => {
        expect(BagiKeHalaman([0, 0, 1, 1, 1], 4, 0)).toEqual([
            [0, 0, 1, 1],
            [1, null, null, null],
        ]);
        expect(BagiKeHalaman([0, 1], 4, 3)).toEqual([
            [null, null, null, 0],
            [1, null, null, null],
        ]);
        expect(BagiKeHalaman([], 4, 0)).toEqual([]);
        expect(BagiKeHalaman([], 4, 2)).toEqual([[null, null, null, null]]);
    });

    it('gulung 3 berdampingan: 7 stiker = 3 halaman', () => {
        expect(BagiKeHalaman(SebarkanStiker([{ Jumlah: 7 }]), 3, 0)).toHaveLength(3);
    });
});

describe('UkuranLabel: tata isi', () => {
    it('label 38 × 21 mm: satu baris nama; barcode masih muat bila isi dibatasi', () => {
        const penuh = HitungTataIsi(21.2, IsiLabelBawaan, true);
        const tanpaNama = HitungTataIsi(21.2, { ...IsiLabelBawaan, Nama: false }, true);

        expect(penuh.NamaBaris).toBe(1);
        expect(tanpaNama.TinggiBarcodeMm).toBeGreaterThan(penuh.TinggiBarcodeMm);
        expect(penuh.Muat).toBe(true);
        expect(penuh.TinggiBarcodeMm).toBeGreaterThanOrEqual(6);
    });

    it('label sangat pendek dengan semua isi tidak muat; tanpa barcode selalu muat', () => {
        const pendek = HitungTataIsi(15, { ...IsiLabelBawaan, Sku: true }, true);

        expect(pendek.Muat).toBe(false);
        expect(HitungTataIsi(15, { ...IsiLabelBawaan, Sku: true }, false)).toMatchObject({
            Muat: true,
            TinggiBarcodeMm: 0,
        });
        expect(HitungTataIsi(40, IsiLabelBawaan, true).NamaBaris).toBe(3);
    });

    it('baris tanpa barcode atau barcode dimatikan tidak menyisakan tinggi barcode', () => {
        expect(HitungTataIsi(30, { ...IsiLabelBawaan, Barcode: false }, true).TinggiBarcodeMm).toBe(0);
        expect(HitungTataIsi(30, IsiLabelBawaan, false).TinggiBarcodeMm).toBe(0);
    });

    it('lebar barcode: selebar ruang tersedia, tetapi modul dibatasi 0,45 mm', () => {
        expect(HitungLebarBarcodeMm(95, 34)).toBe(34);
        expect(HitungLebarBarcodeMm(95, 200)).toBeCloseTo((95 + 20) * 0.45, 2);
    });
});
