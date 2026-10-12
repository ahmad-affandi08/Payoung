/**
 * PRD-10 (F-03): hitung tata letak label cetak. Dua jenis media:
 * - **A4**: lembar stiker berkolom × berbaris; stiker pertama bisa dilewati (lembar bekas pakai).
 * - **Gulung**: satu halaman per baris stiker (1–3 stiker berdampingan), halaman seukuran stiker. Dicetak lewat
 *   driver printer label/thermal pada ukuran kertas yang sama.
 *
 * Semua ukuran dalam milimeter. Fungsi di sini murni (tanpa DOM) supaya mudah diuji.
 */

export type JenisMedia = 'A4' | 'Gulung';

export type MediaLabel = {
    Kode: string;
    Nama: string;
    Jenis: JenisMedia;
    /** A4: kolom stiker per lembar. Gulung: stiker berdampingan per baris (1–3). */
    Kolom: number;
    /** A4: baris stiker per lembar. Gulung: selalu 1. */
    Baris: number;
    LebarMm: number;
    TinggiMm: number;
    JarakXMm: number;
    JarakYMm: number;
};

export type IsiLabel = {
    Nama: boolean;
    Sku: boolean;
    Harga: boolean;
    Satuan: boolean;
    Barcode: boolean;
};

export const IsiLabelBawaan: IsiLabel = { Nama: true, Sku: false, Harga: true, Satuan: true, Barcode: true };

export const LebarA4Mm = 210;
export const TinggiA4Mm = 297;

/** Batas ukuran stiker yang masuk akal untuk cetak (mm). */
export const BatasUkuranMm = { Min: 10, Maks: 200 } as const;

export const MaksKolomGulung = 3;
export const MaksKolomA4 = 10;
export const MaksBarisA4 = 30;
/** Pergeseran kalibrasi printer, ± mm. */
export const MaksGeserMm = 10;

export const MediaBawaan: MediaLabel[] = [
    {
        Kode: 'A4-65',
        Nama: 'A4 | 65 stiker (5 × 13) 38 × 21 mm',
        Jenis: 'A4',
        Kolom: 5,
        Baris: 13,
        LebarMm: 38.1,
        TinggiMm: 21.2,
        JarakXMm: 2.5,
        JarakYMm: 0,
    },
    {
        Kode: 'A4-40',
        Nama: 'A4 | 40 stiker (4 × 10) 46 × 25 mm',
        Jenis: 'A4',
        Kolom: 4,
        Baris: 10,
        LebarMm: 45.7,
        TinggiMm: 25.4,
        JarakXMm: 2.5,
        JarakYMm: 0,
    },
    {
        Kode: 'A4-24',
        Nama: 'A4 | 24 stiker (3 × 8) 70 × 37 mm',
        Jenis: 'A4',
        Kolom: 3,
        Baris: 8,
        LebarMm: 70,
        TinggiMm: 37,
        JarakXMm: 0,
        JarakYMm: 0,
    },
    {
        Kode: 'GULUNG-58x40',
        Nama: 'Gulung | 58 × 40 mm',
        Jenis: 'Gulung',
        Kolom: 1,
        Baris: 1,
        LebarMm: 58,
        TinggiMm: 40,
        JarakXMm: 0,
        JarakYMm: 0,
    },
    {
        Kode: 'GULUNG-50x30',
        Nama: 'Gulung | 50 × 30 mm',
        Jenis: 'Gulung',
        Kolom: 1,
        Baris: 1,
        LebarMm: 50,
        TinggiMm: 30,
        JarakXMm: 0,
        JarakYMm: 0,
    },
    {
        Kode: 'GULUNG-40x30',
        Nama: 'Gulung | 40 × 30 mm',
        Jenis: 'Gulung',
        Kolom: 1,
        Baris: 1,
        LebarMm: 40,
        TinggiMm: 30,
        JarakXMm: 0,
        JarakYMm: 0,
    },
    {
        Kode: 'GULUNG-33x15x3',
        Nama: 'Gulung | 33 × 15 mm, 3 berdampingan',
        Jenis: 'Gulung',
        Kolom: 3,
        Baris: 1,
        LebarMm: 33,
        TinggiMm: 15,
        JarakXMm: 2,
        JarakYMm: 0,
    },
];

export const KodeMediaKustomA4 = 'KUSTOM-A4';
export const KodeMediaKustomGulung = 'KUSTOM-GULUNG';

export const MediaKustomBawaan: Record<'A4' | 'Gulung', MediaLabel> = {
    A4: {
        Kode: KodeMediaKustomA4,
        Nama: 'A4 | ukuran sendiri',
        Jenis: 'A4',
        Kolom: 3,
        Baris: 8,
        LebarMm: 60,
        TinggiMm: 30,
        JarakXMm: 2,
        JarakYMm: 2,
    },
    Gulung: {
        Kode: KodeMediaKustomGulung,
        Nama: 'Gulung | ukuran sendiri',
        Jenis: 'Gulung',
        Kolom: 1,
        Baris: 1,
        LebarMm: 50,
        TinggiMm: 30,
        JarakXMm: 0,
        JarakYMm: 0,
    },
};

export type TataHalaman = {
    LebarHalamanMm: number;
    TinggiHalamanMm: number;
    /** Posisi stiker pertama dari tepi halaman (sudah termasuk geseran kalibrasi). */
    KiriMm: number;
    AtasMm: number;
    /** Jumlah stiker per halaman. */
    PerHalaman: number;
};

function Bulatkan(nilai: number): number {
    return Math.round(nilai * 100) / 100;
}

/** Lebar seluruh kolom stiker termasuk jarak antarkolom. */
export function HitungLebarBlokMm(media: MediaLabel): number {
    return media.Kolom * media.LebarMm + (media.Kolom - 1) * media.JarakXMm;
}

export function HitungTinggiBlokMm(media: MediaLabel): number {
    return media.Baris * media.TinggiMm + (media.Baris - 1) * media.JarakYMm;
}

/** Pesan galat bila ukuran media tidak masuk akal atau tidak muat di halaman; null bila sah. */
export function CekMediaSah(media: MediaLabel): string | null {
    const angka = [media.Kolom, media.Baris, media.LebarMm, media.TinggiMm, media.JarakXMm, media.JarakYMm];

    if (angka.some((nilai) => !Number.isFinite(nilai))) {
        return 'Isi semua ukuran label dengan angka.';
    }

    if (media.LebarMm < BatasUkuranMm.Min || media.TinggiMm < BatasUkuranMm.Min) {
        return `Ukuran stiker minimal ${String(BatasUkuranMm.Min)} mm.`;
    }

    if (media.LebarMm > BatasUkuranMm.Maks || media.TinggiMm > BatasUkuranMm.Maks) {
        return `Ukuran stiker maksimal ${String(BatasUkuranMm.Maks)} mm.`;
    }

    if (media.JarakXMm < 0 || media.JarakYMm < 0) {
        return 'Jarak antarstiker tidak boleh negatif.';
    }

    if (!Number.isInteger(media.Kolom) || !Number.isInteger(media.Baris) || media.Kolom < 1 || media.Baris < 1) {
        return 'Jumlah kolom dan baris harus bilangan bulat minimal 1.';
    }

    if (media.Jenis === 'Gulung') {
        return media.Kolom > MaksKolomGulung
            ? `Label gulung maksimal ${String(MaksKolomGulung)} stiker berdampingan.`
            : null;
    }

    if (media.Kolom > MaksKolomA4 || media.Baris > MaksBarisA4) {
        return `Lembar A4 maksimal ${String(MaksKolomA4)} kolom dan ${String(MaksBarisA4)} baris.`;
    }

    if (HitungLebarBlokMm(media) > LebarA4Mm || HitungTinggiBlokMm(media) > TinggiA4Mm) {
        return `Susunan stiker (${String(Bulatkan(HitungLebarBlokMm(media)))} × ${String(Bulatkan(HitungTinggiBlokMm(media)))} mm) lebih besar dari kertas A4. Kurangi kolom/baris atau kecilkan stiker.`;
    }

    return null;
}

/**
 * Tata halaman. A4: blok stiker dipusatkan di kertas lalu digeser `geser` (kalibrasi printer). Gulung: halaman seukuran
 * blok stiker dan hanya digeser `geser`.
 */
export function HitungTataHalaman(media: MediaLabel, geser: { XMm: number; YMm: number }): TataHalaman {
    const lebarBlok = HitungLebarBlokMm(media);
    const tinggiBlok = HitungTinggiBlokMm(media);

    if (media.Jenis === 'Gulung') {
        return {
            LebarHalamanMm: Bulatkan(lebarBlok),
            TinggiHalamanMm: Bulatkan(tinggiBlok),
            KiriMm: Bulatkan(geser.XMm),
            AtasMm: Bulatkan(geser.YMm),
            PerHalaman: media.Kolom,
        };
    }

    return {
        LebarHalamanMm: LebarA4Mm,
        TinggiHalamanMm: TinggiA4Mm,
        KiriMm: Bulatkan((LebarA4Mm - lebarBlok) / 2 + geser.XMm),
        AtasMm: Bulatkan((TinggiA4Mm - tinggiBlok) / 2 + geser.YMm),
        PerHalaman: media.Kolom * media.Baris,
    };
}

/** Indeks baris per stiker, diulang sebanyak `Jumlah` masing-masing. */
export function SebarkanStiker(baris: readonly { Jumlah: number }[]): number[] {
    return baris.flatMap((b, indeks) => Array.from({ length: Math.max(0, Math.floor(b.Jumlah)) }, () => indeks));
}

/**
 * Bagi stiker ke halaman. `lewati` sel pertama di halaman pertama dikosongkan (lembar bekas pakai; hanya A4).
 * Sel kosong ditandai `null`; halaman terakhir diisi `null` sampai penuh supaya susunan konsisten.
 */
export function BagiKeHalaman(sel: readonly number[], perHalaman: number, lewati: number): (number | null)[][] {
    const isi: (number | null)[] = [...Array.from({ length: Math.max(0, lewati) }, () => null), ...sel];
    const halaman: (number | null)[][] = [];

    for (let awal = 0; awal < isi.length; awal += perHalaman) {
        const potongan = isi.slice(awal, awal + perHalaman);
        halaman.push([...potongan, ...Array.from({ length: perHalaman - potongan.length }, () => null)]);
    }

    return halaman;
}

const mmPerPoin = 25.4 / 72;
const tinggiBarisNisbi = 1.15;
const padMm = 1;

export type TataIsiLabel = {
    NamaPt: number;
    NamaBaris: number;
    SkuPt: number;
    HargaPt: number;
    KodePt: number;
    PadMm: number;
    /** Tinggi yang tersisa untuk gambar barcode (mm); 0 bila barcode dimatikan. */
    TinggiBarcodeMm: number;
    /** False bila isi yang dipilih tidak muat di label ini (barcode lebih pendek dari 6 mm). */
    Muat: boolean;
};

function Jepit(nilai: number, min: number, maks: number): number {
    return Math.min(maks, Math.max(min, nilai));
}

function TinggiBarisMm(poin: number, baris = 1): number {
    return poin * mmPerPoin * tinggiBarisNisbi * baris;
}

/** Ukuran huruf & tinggi barcode untuk sebuah label; `adaBarcode` = baris ini punya barcode yang akan digambar. */
export function HitungTataIsi(tinggiMm: number, isi: IsiLabel, adaBarcode: boolean): TataIsiLabel {
    const namaPt = Jepit(tinggiMm * 0.17, 5.5, 10);
    const namaBaris = tinggiMm >= 30 ? 3 : tinggiMm >= 22 ? 2 : 1;
    const skuPt = Jepit(tinggiMm * 0.12, 4.5, 7);
    const hargaPt = Jepit(tinggiMm * 0.32, 8, 20);
    const kodePt = Jepit(tinggiMm * 0.11, 4.5, 7);
    const pakaiBarcode = isi.Barcode && adaBarcode;
    let terpakai = padMm * 2;

    if (isi.Nama) {
        terpakai += TinggiBarisMm(namaPt, namaBaris);
    }
    if (isi.Sku) {
        terpakai += TinggiBarisMm(skuPt);
    }
    if (isi.Harga) {
        terpakai += TinggiBarisMm(hargaPt);
    }
    if (pakaiBarcode) {
        terpakai += TinggiBarisMm(kodePt);
    }

    const sisa = Bulatkan(tinggiMm - terpakai);

    return {
        NamaPt: Bulatkan(namaPt),
        NamaBaris: namaBaris,
        SkuPt: Bulatkan(skuPt),
        HargaPt: Bulatkan(hargaPt),
        KodePt: Bulatkan(kodePt),
        PadMm: padMm,
        TinggiBarcodeMm: pakaiBarcode ? Math.max(0, sisa) : 0,
        Muat: !pakaiBarcode || sisa >= 6,
    };
}

/** Lebar gambar barcode (mm): selebar yang tersedia, tetapi modul tidak dibuat lebih dari 0,45 mm agar tetap rapi. */
export function HitungLebarBarcodeMm(jumlahModul: number, lebarTersediaMm: number): number {
    return Bulatkan(Math.min(lebarTersediaMm, (jumlahModul + 20) * 0.45));
}
