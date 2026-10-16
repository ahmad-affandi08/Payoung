/*
 * Terbilang Rupiah untuk dokumen tagihan (invoice, kuitansi): "1.250.000,00" → "Satu juta dua ratus lima puluh ribu
 * rupiah". Memakai BigInt dari string desimal, tidak pernah number/float, sehingga nominal triliunan tidak bergeser
 * (CLAUDE.md #7). Sen (dua angka di belakang koma) disebut bila bukan nol: "… rupiah dan lima puluh sen".
 */

const Satuan = [
    '',
    'satu',
    'dua',
    'tiga',
    'empat',
    'lima',
    'enam',
    'tujuh',
    'delapan',
    'sembilan',
    'sepuluh',
    'sebelas',
] as const;

const Tingkat = ['', 'ribu', 'juta', 'miliar', 'triliun', 'kuadriliun'] as const;

/** Bilangan 0–999 ("seratus dua puluh tiga"); kosong untuk 0. */
function UraikanRatusan(angka: number): string {
    const bagian: string[] = [];
    const ratusan = Math.floor(angka / 100);
    const sisa = angka % 100;

    if (ratusan === 1) {
        bagian.push('seratus');
    } else if (ratusan > 1) {
        bagian.push(`${Satuan[ratusan] ?? ''} ratus`);
    }

    if (sisa > 0) {
        if (sisa < 12) {
            bagian.push(Satuan[sisa] ?? '');
        } else if (sisa < 20) {
            bagian.push(`${Satuan[sisa - 10] ?? ''} belas`);
        } else {
            const puluhan = Math.floor(sisa / 10);
            const satuan = sisa % 10;
            bagian.push(`${Satuan[puluhan] ?? ''} puluh${satuan > 0 ? ` ${Satuan[satuan] ?? ''}` : ''}`);
        }
    }

    return bagian.join(' ');
}

/** Bilangan bulat tak negatif dalam kata ("nol" untuk 0). */
export function TerbilangBilangan(nilai: bigint): string {
    if (nilai < 0n) {
        throw new RangeError('Terbilang hanya untuk bilangan tak negatif.');
    }

    if (nilai === 0n) {
        return 'nol';
    }

    const kelompok: number[] = [];
    let sisa = nilai;

    while (sisa > 0n) {
        kelompok.push(Number(sisa % 1000n));
        sisa /= 1000n;
    }

    if (kelompok.length > Tingkat.length) {
        throw new RangeError('Bilangan terlalu besar untuk dieja.');
    }

    const bagian: string[] = [];

    for (let indeks = kelompok.length - 1; indeks >= 0; indeks -= 1) {
        const angka = kelompok[indeks] ?? 0;

        if (angka === 0) {
            continue;
        }

        // "seribu", bukan "satu ribu"; tingkat lain tetap "satu juta", "satu miliar".
        bagian.push(
            indeks === 1 && angka === 1
                ? 'seribu'
                : `${UraikanRatusan(angka)}${indeks > 0 ? ` ${Tingkat[indeks] ?? ''}` : ''}`,
        );
    }

    return bagian.join(' ');
}

/**
 * "1250000.00" → "Satu juta dua ratus lima puluh ribu rupiah". Nilai negatif atau bukan desimal → string kosong
 * (dokumen tidak mencetak ejaan yang salah).
 */
export function TerbilangRupiah(nilai: string): string {
    const cocok = /^(\d+)(?:\.(\d{1,2}))?$/.exec(nilai.trim());

    if (cocok === null) {
        return '';
    }

    const rupiah = BigInt(cocok[1] ?? '0');
    const sen = Number((cocok[2] ?? '').padEnd(2, '0') || '0');

    let teks: string;

    try {
        teks = `${TerbilangBilangan(rupiah)} rupiah`;

        if (sen > 0) {
            teks += ` dan ${TerbilangBilangan(BigInt(sen))} sen`;
        }
    } catch {
        return '';
    }

    return teks.charAt(0).toUpperCase() + teks.slice(1);
}
