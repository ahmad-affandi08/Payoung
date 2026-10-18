/** Kelompok jenis usaha di pemilih sektor (langkah Jenis usaha panduan awal). Urutan = urutan tampil. */
export type KelompokSektor = 'Makanan' | 'Retail' | 'Jasa' | 'Bisnis' | 'Lainnya';

export type TampilanSektor = {
    /** URL ilustrasi jenis usaha (D-178), `Aset/Sektor/{KodePascalCase}.webp`; selalu ada (kode tak dikenal memakai ilustrasi awalannya). */
    Ilustrasi: string;
    Kelompok: KelompokSektor;
};

export type TataKelompokSektor = {
    Label: string;
    /** Kelas warna titik penanda kelompok di judul kelompok. */
    KelasTitik: string;
};

export const TATA_KELOMPOK_SEKTOR: Record<KelompokSektor, TataKelompokSektor> = {
    Makanan: { Label: 'Makanan & minuman', KelasTitik: 'bg-brand' },
    Retail: { Label: 'Toko & retail', KelasTitik: 'bg-aksen' },
    Jasa: { Label: 'Jasa & layanan', KelasTitik: 'bg-brand-gelap' },
    Bisnis: { Label: 'Bisnis & perusahaan', KelasTitik: 'bg-teks-sekunder' },
    Lainnya: { Label: 'Lainnya', KelasTitik: 'bg-teks-sekunder' },
};

const URUTAN_KELOMPOK: KelompokSektor[] = ['Makanan', 'Retail', 'Jasa', 'Bisnis', 'Lainnya'];

/**
 * Semua berkas di `Aset/Sektor` dipetakan lewat nama berkas tanpa ekstensi = kode template dalam PascalCase tanpa tanda
 * hubung (`FNB-CAF` -> `FnbCaf.webp`, sesuai konvensi nama berkas).
 * Dibuat `Spesifikasi/Merek/BuatIlustrasiSektor.py` + `BuatTurunanIlustrasiSektor.py`.
 */
const berkas = import.meta.glob<string>('../Aset/Sektor/*.webp', {
    eager: true,
    query: '?url',
    import: 'default',
});

const ilustrasiPerBerkas: Record<string, string> = Object.fromEntries(
    Object.entries(berkas).map(([jalur, url]) => [(jalur.split('/').pop() ?? '').replace(/\.\w+$/, ''), url]),
);

/** `FNB-CAF` -> `FnbCaf`: nama berkas ilustrasi untuk satu kode template. */
export function AmbilNamaBerkasIlustrasi(kode: string): string {
    return kode
        .split('-')
        .map((bagian) => bagian.charAt(0).toUpperCase() + bagian.slice(1).toLowerCase())
        .join('');
}

function CariIlustrasi(kode: string): string | undefined {
    return ilustrasiPerBerkas[AmbilNamaBerkasIlustrasi(kode)];
}

/** Kelompok dan ilustrasi bawaan untuk kode template baru yang belum punya ilustrasi sendiri. */
const DASAR_AWALAN: Record<string, { Kelompok: KelompokSektor; KodeIlustrasi: string }> = {
    FNB: { Kelompok: 'Makanan', KodeIlustrasi: 'FNB-RST' },
    RTL: { Kelompok: 'Retail', KodeIlustrasi: 'RTL-GEN' },
    WHS: { Kelompok: 'Retail', KodeIlustrasi: 'WHS-DST' },
    SVC: { Kelompok: 'Jasa', KodeIlustrasi: 'SVC-GEN' },
    BIZ: { Kelompok: 'Bisnis', KodeIlustrasi: 'BIZ-JSA' },
};

const DASAR_LAINNYA = { Kelompok: 'Lainnya' as const, KodeIlustrasi: 'RTL-GEN' };

/** Ilustrasi dan kelompok untuk satu kode template sektor (`FNB-CAF`, `SVC-LDR`, ...). */
export function AmbilTampilanSektor(kode: string): TampilanSektor {
    const dasar = DASAR_AWALAN[kode.split('-')[0] ?? ''] ?? DASAR_LAINNYA;

    return {
        Ilustrasi: CariIlustrasi(kode) ?? CariIlustrasi(dasar.KodeIlustrasi) ?? '',
        Kelompok: dasar.Kelompok,
    };
}

export type GrupSektor<T> = { Kelompok: KelompokSektor; Label: string; KelasTitik: string; Item: T[] };

/** Mengelompokkan daftar template menurut kelompok (urutan tetap; urutan di dalam kelompok mengikuti server); kelompok kosong dibuang. */
export function KelompokkanSektor<T extends { Kode: string }>(daftar: T[]): GrupSektor<T>[] {
    return URUTAN_KELOMPOK.map((kelompok) => ({
        Kelompok: kelompok,
        Label: TATA_KELOMPOK_SEKTOR[kelompok].Label,
        KelasTitik: TATA_KELOMPOK_SEKTOR[kelompok].KelasTitik,
        Item: daftar.filter((item) => AmbilTampilanSektor(item.Kode).Kelompok === kelompok),
    })).filter((grup) => grup.Item.length > 0);
}
