import type { BarisVarian } from '@/Tipe/Katalog';

/**
 * Grid ukuran × warna (§9.4, D-143): varian dengan tepat dua atribut ditata sebagai matriks. Baris = atribut pertama
 * definisi induk (mis. Ukuran), kolom = atribut kedua (mis. Warna); urutan nilai mengikuti definisi induk, bukan abjad
 * (S, M, L, XL tidak diurutkan menjadi L, M, S, XL). Sel yang kombinasinya belum jadi varian bernilai undefined.
 */
export type GridVarian = {
    NamaBaris: string;
    NamaKolom: string;
    NilaiBaris: string[];
    NilaiKolom: string[];
    /** Kunci `KunciSel(baris, kolom)` → varian. */
    Sel: Record<string, BarisVarian>;
};

type DefinisiAtribut = { Nama: string; Nilai: string[] };

export function KunciSel(baris: string, kolom: string): string {
    return `${baris.trim().toLowerCase()}|${kolom.trim().toLowerCase()}`;
}

function NilaiAtribut(varian: BarisVarian, nama: string): string | undefined {
    return varian.Atribut.find((a) => a.Nama.trim().toLowerCase() === nama.trim().toLowerCase())?.Nilai;
}

/** Nilai berurutan menurut definisi, lalu nilai tambahan yang hanya ada di varian (urutan kemunculan). */
function UrutkanNilai(definisi: DefinisiAtribut | undefined, ditemukan: string[]): string[] {
    const hasil: string[] = [];
    const sudah = new Set<string>();
    const Tambah = (nilai: string) => {
        const kunci = nilai.trim().toLowerCase();

        if (!sudah.has(kunci)) {
            sudah.add(kunci);
            hasil.push(nilai);
        }
    };

    for (const nilai of definisi?.Nilai ?? []) {
        if (ditemukan.some((d) => d.trim().toLowerCase() === nilai.trim().toLowerCase())) {
            Tambah(nilai);
        }
    }

    ditemukan.forEach(Tambah);

    return hasil;
}

/**
 * Susun grid dari varian. Null bila tidak berlaku: tanpa varian, atau ada varian yang tidak punya tepat dua atribut
 * bernama sama dengan varian lain (satu atau tiga atribut tetap memakai tabel daftar).
 */
export function SusunGridVarian(varian: BarisVarian[], definisi: DefinisiAtribut[]): GridVarian | null {
    const pertama = varian[0];

    if (pertama === undefined || pertama.Atribut.length !== 2) {
        return null;
    }

    const namaBaris = pertama.Atribut[0]?.Nama ?? '';
    const namaKolom = pertama.Atribut[1]?.Nama ?? '';
    const sel: Record<string, BarisVarian> = {};
    const nilaiBaris: string[] = [];
    const nilaiKolom: string[] = [];

    for (const satu of varian) {
        const baris = NilaiAtribut(satu, namaBaris);
        const kolom = NilaiAtribut(satu, namaKolom);

        if (satu.Atribut.length !== 2 || baris === undefined || kolom === undefined || baris === '' || kolom === '') {
            return null;
        }

        sel[KunciSel(baris, kolom)] = satu;
        nilaiBaris.push(baris);
        nilaiKolom.push(kolom);
    }

    const CariDefinisi = (nama: string) =>
        definisi.find((d) => d.Nama.trim().toLowerCase() === nama.trim().toLowerCase());

    return {
        NamaBaris: namaBaris,
        NamaKolom: namaKolom,
        NilaiBaris: UrutkanNilai(CariDefinisi(namaBaris), nilaiBaris),
        NilaiKolom: UrutkanNilai(CariDefinisi(namaKolom), nilaiKolom),
        Sel: sel,
    };
}

/** Jumlah stok server ("10.0000") ke teks pendek ("10"); kosong/0 → "0". */
export function RingkasStok(stok: string | undefined): string {
    if (stok === undefined || stok === '') {
        return '0';
    }

    const [bulat = '0', pecahan = ''] = stok.split('.');
    const sisa = pecahan.replace(/0+$/, '');

    return sisa === '' ? bulat : `${bulat},${sisa}`;
}

/** Apakah stok server bernilai lebih dari nol (tanpa float). */
export function AdaStok(stok: string | undefined): boolean {
    return stok !== undefined && /[1-9]/.test(stok.replace(/^-/, '')) && !stok.startsWith('-');
}
