import { describe, expect, it } from 'vitest';

import {
    CekBisaDikodekan,
    CekEan13Sah,
    HitungDigitPeriksaEan13,
    HitungLebarMinimumMm,
    PilihSimbologi,
    PolaCode128,
    SusunBarcode,
    SusunGarisBarcode,
} from '@/Pustaka/Barcode';

const penutup = '1100011101011';

/** Pembaca Code 128 mandiri: membongkar deretan modul kembali menjadi teks, memeriksa digit periksa dan penutup. */
function BacaCode128(modul: string): string {
    expect(modul.endsWith(penutup)).toBe(true);
    const isi = modul.slice(0, -penutup.length);
    expect(isi.length % 11).toBe(0);
    const nilai = Array.from({ length: isi.length / 11 }, (_, i) => {
        const indeks = PolaCode128.indexOf(isi.slice(i * 11, i * 11 + 11) as (typeof PolaCode128)[number]);
        expect(indeks).toBeGreaterThanOrEqual(0);
        return indeks;
    });
    const [awal = 0, ...sisa] = nilai;
    const periksa = sisa.pop() as number;
    expect([103, 104, 105]).toContain(awal);
    expect((awal + sisa.reduce((jumlah, v, i) => jumlah + v * (i + 1), 0)) % 103).toBe(periksa);

    let setKode: 'A' | 'B' | 'C' = awal === 105 ? 'C' : 'B';
    let teks = '';
    for (const v of sisa) {
        if (setKode === 'C') {
            if (v === 100) {
                setKode = 'B';
            } else {
                expect(v).toBeLessThan(100);
                teks += String(v).padStart(2, '0');
            }
        } else if (v === 99) {
            setKode = 'C';
        } else {
            expect(v).toBeLessThanOrEqual(94);
            teks += String.fromCharCode(v + 32);
        }
    }
    return teks;
}

describe('Barcode: pemilihan simbologi', () => {
    it('EAN-13 hanya untuk 13 digit berdigit periksa benar dan tidak berawalan 0', () => {
        expect(HitungDigitPeriksaEan13('899123456789')).toBe(1);
        expect(CekEan13Sah('8991234567891')).toBe(true);
        expect(PilihSimbologi('8991234567891')).toBe('EAN13');
        expect(PilihSimbologi('8991234567890')).toBe('Code128');
        expect(PilihSimbologi('0123456789012')).toBe('Code128');
        expect(PilihSimbologi('12345678')).toBe('Code128');
        expect(PilihSimbologi('SKU-001')).toBe('Code128');
    });

    it('barcode internal buatan toko (awalan 20) adalah EAN-13 sah', () => {
        const duaBelas = '200000000042';
        const kode = `${duaBelas}${String(HitungDigitPeriksaEan13(duaBelas))}`;

        expect(PilihSimbologi(kode)).toBe('EAN13');
    });

    it('menolak kosong dan karakter di luar ASCII cetak', () => {
        expect(CekBisaDikodekan('')).toBe(false);
        expect(CekBisaDikodekan('café')).toBe(false);
        expect(CekBisaDikodekan('A-1.b')).toBe(true);
        expect(() => SusunBarcode('')).toThrow();
        expect(() => SusunBarcode('naïf')).toThrow();
    });
});

describe('Barcode: pola sama dengan pustaka pembanding (python-barcode 0.16.1)', () => {
    it('EAN-13 8991234567891', () => {
        const hasil = SusunBarcode('8991234567891');

        expect(hasil.Simbologi).toBe('EAN13');
        expect(hasil.Modul).toBe(
            '10100010110010111001100100110110100001010001101010100111010100001000100100100011101001100110101',
        );
        expect(hasil.Modul).toHaveLength(95);
    });

    it.each([
        [
            'ABC-123',
            '1101001000010100011000100010110001000100011010011011100100111001101100111001011001011100101100001001100011101011',
        ],
        ['12345678', '1101001110010110011100100010110001110001011011000010100100011101101100011101011'],
        [
            'SKU.001-X',
            '11010010000110111010001011000111011011101110100110011101001110110010011101100100111001101001101110011100010110110010011101100011101011',
        ],
    ])('Code 128 %s', (kode, modul) => {
        expect(SusunBarcode(kode).Modul).toBe(modul);
    });
});

describe('Barcode: Code 128 dibaca kembali sama persis', () => {
    it.each([
        '1',
        '12',
        '123',
        '1234',
        '12345',
        '123456',
        '1234567',
        '8991234567890',
        '0123456789012',
        '00000000',
        'A1',
        '1A',
        'X12',
        'X1234',
        'X123456',
        'X12345Y',
        'ab-12.cd',
        'KOPI-250G',
        'AB1234567890CD',
        '12345678901234567890',
        'ZZZ99999999',
    ])('%s', (kode) => {
        const hasil = SusunBarcode(kode);

        expect(hasil.Simbologi).toBe('Code128');
        expect(BacaCode128(hasil.Modul)).toBe(kode);
    });

    it('deretan digit memakai set C sehingga lebih pendek dari set B murni', () => {
        const angka = SusunBarcode('12345678901234').Modul.length;
        const huruf = SusunBarcode('ABCDEFGHIJKLMN').Modul.length;

        expect(angka).toBeLessThan(huruf);
    });
});

describe('Barcode: tabel pola Code 128', () => {
    it('106 pola unik: 11 modul, 3 garis, jumlah lebar garis genap (sifat baku Code 128)', () => {
        expect(PolaCode128).toHaveLength(106);
        expect(new Set(PolaCode128).size).toBe(106);
        for (const pola of PolaCode128) {
            expect(pola).toMatch(/^1[01]{9}0$/);
            const garis = SusunGarisBarcode(pola);
            expect(garis).toHaveLength(3);
            expect(garis.reduce((jumlah, g) => jumlah + g.Lebar, 0) % 2).toBe(0);
        }
    });
});

describe('Barcode: garis dan lebar minimum', () => {
    it('SusunGarisBarcode menggabungkan modul hitam berurutan', () => {
        expect(SusunGarisBarcode('0110100111')).toEqual([
            { Mulai: 1, Lebar: 2 },
            { Mulai: 4, Lebar: 1 },
            { Mulai: 7, Lebar: 3 },
        ]);
        expect(SusunGarisBarcode('000')).toEqual([]);
    });

    it('lebar minimum mencakup zona hening dan tumbuh bersama panjang barcode', () => {
        const ean = HitungLebarMinimumMm(95);
        const panjang = HitungLebarMinimumMm(SusunBarcode('ABCDEFGHIJKLM').Modul.length);

        expect(ean).toBeCloseTo((95 + 20) * 0.19, 5);
        expect(panjang).toBeGreaterThan(ean);
    });
});
