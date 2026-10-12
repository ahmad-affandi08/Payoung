/**
 * PRD-10 (F-03): penyusun barcode 1D untuk label cetak, tanpa pustaka luar. Mengeluarkan deretan modul (`'1'` = garis
 * hitam, `'0'` = ruang putih) yang digambar sebagai SVG oleh `GambarBarcode`.
 *
 * Dua simbologi, dipilih dari isi barcode produk:
 * - **EAN-13** untuk barcode 13 digit berdigit periksa benar dan tidak berawalan 0 (barcode pabrik, barcode internal
 *   toko). Awalan 0 dikirim pemindai sebagai UPC-A 12 digit, sehingga tidak cocok dengan data produk.
 * - **Code 128** (set B + set C otomatis) untuk yang lain: SKU atau barcode buatan sendiri (huruf, angka, titik, strip).
 *
 * Tabel pola Code 128 dicocokkan dengan pustaka pembanding independen di `BarcodeTes.ts`.
 */

export type Simbologi = 'EAN13' | 'Code128';

export type HasilBarcode = {
    Simbologi: Simbologi;
    /** Isi yang akan dibaca pemindai (sama persis dengan barcode produk). */
    Kode: string;
    /** Deretan modul `'1'`/`'0'`, termasuk pelindung awal & akhir. Zona hening (kiri/kanan) tidak disertakan. */
    Modul: string;
};

/** Pola 11 modul untuk nilai 0–102 (data), 103–105 (awal A/B/C). */
export const PolaCode128 = [
    '11011001100',
    '11001101100',
    '11001100110',
    '10010011000',
    '10010001100',
    '10001001100',
    '10011001000',
    '10011000100',
    '10001100100',
    '11001001000',
    '11001000100',
    '11000100100',
    '10110011100',
    '10011011100',
    '10011001110',
    '10111001100',
    '10011101100',
    '10011100110',
    '11001110010',
    '11001011100',
    '11001001110',
    '11011100100',
    '11001110100',
    '11101101110',
    '11101001100',
    '11100101100',
    '11100100110',
    '11101100100',
    '11100110100',
    '11100110010',
    '11011011000',
    '11011000110',
    '11000110110',
    '10100011000',
    '10001011000',
    '10001000110',
    '10110001000',
    '10001101000',
    '10001100010',
    '11010001000',
    '11000101000',
    '11000100010',
    '10110111000',
    '10110001110',
    '10001101110',
    '10111011000',
    '10111000110',
    '10001110110',
    '11101110110',
    '11010001110',
    '11000101110',
    '11011101000',
    '11011100010',
    '11011101110',
    '11101011000',
    '11101000110',
    '11100010110',
    '11101101000',
    '11101100010',
    '11100011010',
    '11101111010',
    '11001000010',
    '11110001010',
    '10100110000',
    '10100001100',
    '10010110000',
    '10010000110',
    '10000101100',
    '10000100110',
    '10110010000',
    '10110000100',
    '10011010000',
    '10011000010',
    '10000110100',
    '10000110010',
    '11000010010',
    '11001010000',
    '11110111010',
    '11000010100',
    '10001111010',
    '10100111100',
    '10010111100',
    '10010011110',
    '10111100100',
    '10011110100',
    '10011110010',
    '11110100100',
    '11110010100',
    '11110010010',
    '11011011110',
    '11011110110',
    '11110110110',
    '10101111000',
    '10100011110',
    '10001011110',
    '10111101000',
    '10111100010',
    '11110101000',
    '11110100010',
    '10111011110',
    '10111101110',
    '11101011110',
    '11110101110',
    '11010000100',
    '11010010000',
    '11010011100',
] as const;

/** Pola penutup Code 128: 11 modul + 2 modul penutup. */
const penutupCode128 = '1100011101011';

const awalSetB = 104;
const awalSetC = 105;
const pindahKeSetC = 99;
const pindahKeSetB = 100;

type SetKode = 'B' | 'C';

/** Pola EAN-13: set L (A), G (B), R (C) per digit 0–9; urutan set sisi kiri ditentukan digit pertama. */
const polaEanA = [
    '0001101',
    '0011001',
    '0010011',
    '0111101',
    '0100011',
    '0110001',
    '0101111',
    '0111011',
    '0110111',
    '0001011',
] as const;
const polaEanB = [
    '0100111',
    '0110011',
    '0011011',
    '0100001',
    '0011101',
    '0111001',
    '0000101',
    '0010001',
    '0001001',
    '0010111',
] as const;
const polaEanC = [
    '1110010',
    '1100110',
    '1101100',
    '1000010',
    '1011100',
    '1001110',
    '1010000',
    '1000100',
    '1001000',
    '1110100',
] as const;
const urutanSisiKiriEan = [
    'AAAAAA',
    'AABABB',
    'AABBAB',
    'AABBBA',
    'ABAABB',
    'ABBAAB',
    'ABBBAA',
    'ABABAB',
    'ABABBA',
    'ABBABA',
] as const;

/** Lebar modul terkecil (mm) yang masih terbaca andal oleh pemindai kamera dan laser pada kertas label. */
export const LebarModulMinimumMm = 0.19;

/** Zona hening wajib di kiri dan kanan, dalam modul. */
export const ZonaHeningModul = 10;

/** Digit periksa EAN dari 12 digit: bobot 1,3 dari kiri; digit = (10 − jumlah mod 10) mod 10. */
export function HitungDigitPeriksaEan13(duaBelasDigit: string): number {
    let jumlah = 0;
    for (const [posisi, digit] of [...duaBelasDigit].entries()) {
        jumlah += Number(digit) * (posisi % 2 === 0 ? 1 : 3);
    }
    return (10 - (jumlah % 10)) % 10;
}

/** True bila `kode` 13 digit, digit periksa benar, dan tidak berawalan 0. */
export function CekEan13Sah(kode: string): boolean {
    return /^[1-9]\d{12}$/.test(kode) && HitungDigitPeriksaEan13(kode.slice(0, 12)) === Number(kode.slice(12));
}

export function PilihSimbologi(kode: string): Simbologi {
    return CekEan13Sah(kode) ? 'EAN13' : 'Code128';
}

/** True bila setiap karakter bisa dikodekan Code 128 set B (ASCII 32–126). */
export function CekBisaDikodekan(kode: string): boolean {
    return kode.length > 0 && [...kode].every((huruf) => huruf.charCodeAt(0) >= 32 && huruf.charCodeAt(0) <= 126);
}

function SusunEan13(kode: string): string {
    const digit = [...kode].map(Number);
    const urutan = urutanSisiKiriEan[digit[0] as number] as string;
    const kiri = digit
        .slice(1, 7)
        .map((d, i) => (urutan[i] === 'A' ? polaEanA[d] : polaEanB[d]))
        .join('');
    const kanan = digit
        .slice(7)
        .map((d) => polaEanC[d])
        .join('');
    return `101${kiri}01010${kanan}101`;
}

function CekDuaDigit(kode: string, indeks: number): boolean {
    return /^\d\d$/.test(kode.slice(indeks, indeks + 2));
}

/**
 * Urutan simbol data Code 128 terpendek (set B untuk huruf, set C untuk pasangan digit) setelah simbol awal. Pencarian
 * rekursif dengan memo; dua perpindahan set berturut-turut tidak pernah berguna, jadi dilarang agar tidak berputar.
 */
function CariSimbolTerpendek(kode: string, awal: SetKode): number[] {
    const memo = new Map<string, number[] | null>();

    const Cari = (indeks: number, set: SetKode, baruPindah: boolean): number[] | null => {
        if (indeks >= kode.length) {
            return [];
        }
        const kunci = `${String(indeks)}${set}${baruPindah ? '1' : '0'}`;
        if (memo.has(kunci)) {
            return memo.get(kunci) ?? null;
        }

        let terbaik: number[] | null = null;
        const Pertimbangkan = (calon: number[] | null) => {
            if (calon !== null && (terbaik === null || calon.length < terbaik.length)) {
                terbaik = calon;
            }
        };

        if (set === 'B') {
            const sisa = Cari(indeks + 1, 'B', false);
            Pertimbangkan(sisa === null ? null : [kode.charCodeAt(indeks) - 32, ...sisa]);
            if (!baruPindah) {
                const sisaC = Cari(indeks, 'C', true);
                Pertimbangkan(sisaC === null ? null : [pindahKeSetC, ...sisaC]);
            }
        } else {
            if (CekDuaDigit(kode, indeks)) {
                const sisa = Cari(indeks + 2, 'C', false);
                Pertimbangkan(sisa === null ? null : [Number(kode.slice(indeks, indeks + 2)), ...sisa]);
            }
            if (!baruPindah) {
                const sisaB = Cari(indeks, 'B', true);
                Pertimbangkan(sisaB === null ? null : [pindahKeSetB, ...sisaB]);
            }
        }

        memo.set(kunci, terbaik);
        return terbaik;
    };

    return Cari(0, awal, false) ?? [];
}

function SusunCode128(kode: string): string {
    const kandidatB = CariSimbolTerpendek(kode, 'B');
    const kandidatC = CekDuaDigit(kode, 0) ? CariSimbolTerpendek(kode, 'C') : null;
    const pakaiC = kandidatC !== null && kandidatC.length < kandidatB.length;
    const awal = pakaiC ? awalSetC : awalSetB;
    const data = pakaiC ? kandidatC : kandidatB;

    let jumlah = awal;
    for (const [posisi, nilai] of data.entries()) {
        jumlah += nilai * (posisi + 1);
    }
    const periksa = jumlah % 103;

    return [awal, ...data, periksa].map((nilai) => PolaCode128[nilai] as string).join('') + penutupCode128;
}

/**
 * Susun barcode `kode`. Melempar `Error` bila ada karakter di luar ASCII 32–126 (barcode produk Payoung hanya huruf,
 * angka, titik, dan strip, jadi ini tidak terjadi untuk data katalog).
 */
export function SusunBarcode(kode: string): HasilBarcode {
    if (!CekBisaDikodekan(kode)) {
        throw new Error('Barcode kosong atau berisi karakter yang tidak bisa dikodekan.');
    }

    const simbologi = PilihSimbologi(kode);

    return { Simbologi: simbologi, Kode: kode, Modul: simbologi === 'EAN13' ? SusunEan13(kode) : SusunCode128(kode) };
}

/** Garis hitam berdampingan sebagai pasangan (mulai, lebar) dalam modul; dipakai menggambar `<rect>` SVG. */
export function SusunGarisBarcode(modul: string): { Mulai: number; Lebar: number }[] {
    const garis: { Mulai: number; Lebar: number }[] = [];
    let mulai = -1;

    for (let i = 0; i <= modul.length; i += 1) {
        const hitam = modul[i] === '1';
        if (hitam && mulai < 0) {
            mulai = i;
        } else if (!hitam && mulai >= 0) {
            garis.push({ Mulai: mulai, Lebar: i - mulai });
            mulai = -1;
        }
    }

    return garis;
}

/** Lebar minimum (mm) agar barcode dengan `jumlahModul` modul tetap terbaca, termasuk zona hening kiri-kanan. */
export function HitungLebarMinimumMm(jumlahModul: number): number {
    return (jumlahModul + ZonaHeningModul * 2) * LebarModulMinimumMm;
}
