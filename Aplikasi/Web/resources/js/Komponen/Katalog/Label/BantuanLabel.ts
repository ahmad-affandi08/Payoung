import { CekBisaDikodekan } from '@/Pustaka/Barcode';
import {
    IsiLabelBawaan,
    KodeMediaKustomA4,
    KodeMediaKustomGulung,
    MediaBawaan,
    MediaKustomBawaan,
    type IsiLabel,
    type MediaLabel,
} from '@/Pustaka/UkuranLabel';
import type { BarisLabelServer } from '@/Tipe/Katalog';

/** Baris yang sedang disiapkan untuk dicetak: data server + barcode yang dipilih (jika satuan punya lebih dari satu). */
export type BarisCetak = BarisLabelServer & { BarcodeDipilih: string | null };

export const MaksJumlahPerBaris = 500;
export const MaksTotalStiker = 5000;

export function KeBarisCetak(baris: BarisLabelServer): BarisCetak {
    return { ...baris, BarcodeDipilih: baris.Barcode[0] ?? null };
}

/** Jumlah stiker dari isian teks: bilangan bulat 0–500; selain angka dianggap 0. */
export function SaringJumlah(teks: string): number {
    const angka = Number.parseInt(teks.replace(/\D/g, ''), 10);

    return Number.isFinite(angka) ? Math.min(MaksJumlahPerBaris, angka) : 0;
}

export function HitungTotalStiker(baris: readonly { Jumlah: number }[]): number {
    return baris.reduce((jumlah, b) => jumlah + Math.max(0, b.Jumlah), 0);
}

/** Barcode yang akan digambar pada baris ini, atau null (belum punya / tidak bisa dikodekan). */
export function AmbilBarcodeCetak(baris: BarisCetak): string | null {
    return baris.BarcodeDipilih !== null && CekBisaDikodekan(baris.BarcodeDipilih) ? baris.BarcodeDipilih : null;
}

export type PreferensiLabel = {
    KodeMedia: string;
    Isi: IsiLabel;
    GeserXMm: number;
    GeserYMm: number;
    Kustom: { A4: MediaLabel; Gulung: MediaLabel };
};

const kunciPenyimpanan = 'payoung.cetak-label.v1';

export const PreferensiBawaan: PreferensiLabel = {
    KodeMedia: MediaBawaan[0]?.Kode ?? '',
    Isi: IsiLabelBawaan,
    GeserXMm: 0,
    GeserYMm: 0,
    Kustom: MediaKustomBawaan,
};

function CekAngka(nilai: unknown): nilai is number {
    return typeof nilai === 'number' && Number.isFinite(nilai);
}

function CekMedia(nilai: unknown, jenis: 'A4' | 'Gulung'): nilai is MediaLabel {
    if (typeof nilai !== 'object' || nilai === null) {
        return false;
    }
    const m = nilai as Record<string, unknown>;

    return (
        m.Jenis === jenis &&
        typeof m.Kode === 'string' &&
        typeof m.Nama === 'string' &&
        [m.Kolom, m.Baris, m.LebarMm, m.TinggiMm, m.JarakXMm, m.JarakYMm].every(CekAngka)
    );
}

/** Pilihan terakhir pengguna dari penyimpanan peramban; null bila kosong, rusak, atau penyimpanan tidak tersedia. */
export function MuatPreferensi(): PreferensiLabel | null {
    try {
        const mentah = window.localStorage.getItem(kunciPenyimpanan);
        if (mentah === null) {
            return null;
        }
        const data = JSON.parse(mentah) as Record<string, unknown>;
        const isi = data.Isi as Record<string, unknown> | undefined;
        const kustom = data.Kustom as Record<string, unknown> | undefined;
        const kodeSah =
            typeof data.KodeMedia === 'string' &&
            (data.KodeMedia === KodeMediaKustomA4 ||
                data.KodeMedia === KodeMediaKustomGulung ||
                MediaBawaan.some((m) => m.Kode === data.KodeMedia));

        if (
            !kodeSah ||
            isi === undefined ||
            !(['Nama', 'Sku', 'Harga', 'Satuan', 'Barcode'] as const).every((k) => typeof isi[k] === 'boolean') ||
            !CekAngka(data.GeserXMm) ||
            !CekAngka(data.GeserYMm) ||
            kustom === undefined ||
            !CekMedia(kustom.A4, 'A4') ||
            !CekMedia(kustom.Gulung, 'Gulung')
        ) {
            return null;
        }

        return {
            KodeMedia: data.KodeMedia as string,
            Isi: isi as unknown as IsiLabel,
            GeserXMm: data.GeserXMm,
            GeserYMm: data.GeserYMm,
            Kustom: { A4: kustom.A4, Gulung: kustom.Gulung },
        };
    } catch {
        return null;
    }
}

/** False bila ada isian angka yang belum sah (mis. sedang diketik): pilihan terakhir yang sah tetap tersimpan. */
export function CekPreferensiSah(preferensi: PreferensiLabel): boolean {
    return (
        CekAngka(preferensi.GeserXMm) &&
        CekAngka(preferensi.GeserYMm) &&
        [preferensi.Kustom.A4, preferensi.Kustom.Gulung].every((m) =>
            [m.Kolom, m.Baris, m.LebarMm, m.TinggiMm, m.JarakXMm, m.JarakYMm].every(CekAngka),
        )
    );
}

export function SimpanPreferensi(preferensi: PreferensiLabel): void {
    if (!CekPreferensiSah(preferensi)) {
        return;
    }

    try {
        window.localStorage.setItem(kunciPenyimpanan, JSON.stringify(preferensi));
    } catch {
        // Penyimpanan diblokir (mode privat): pilihan tidak diingat, halaman tetap berfungsi.
    }
}

export function CariMedia(kode: string, kustom: PreferensiLabel['Kustom']): MediaLabel {
    if (kode === KodeMediaKustomA4) {
        return kustom.A4;
    }
    if (kode === KodeMediaKustomGulung) {
        return kustom.Gulung;
    }

    return MediaBawaan.find((m) => m.Kode === kode) ?? (MediaBawaan[0] as MediaLabel);
}
