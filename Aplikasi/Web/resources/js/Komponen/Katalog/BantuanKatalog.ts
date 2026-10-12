/**
 * Bantuan bersama layar F-03 (Master Produk, Harga & Pajak). Murni teks: tidak ada number untuk uang/kuantitas.
 */
import type { JenisProduk, TigaKeadaan } from '@/Tipe/Katalog';

/** Pola SKU Backend (DesainF03 C.2): huruf/angka di depan, lalu huruf, angka, titik, garis bawah, strip, garis miring. */
export const PolaSku = /^[A-Za-z0-9][A-Za-z0-9._\-/]{0,63}$/;
/** Pola barcode Backend (DesainF03 C.2). Check digit tidak diperiksa: kode lokal sering tidak valid (H.8). */
export const PolaBarcode = /^[A-Za-z0-9\-.]{3,64}$/;

/** Pesan galat barcode lokal sebelum dikirim, atau null. Server tetap memeriksa keunikan (BR-03.1). */
export function PeriksaBarcode(barcode: string, sudahAda: string[]): string | null {
    if (!PolaBarcode.test(barcode)) {
        return 'Barcode 3–64 karakter: huruf, angka, titik, atau strip.';
    }

    if (sudahAda.some((lain) => lain.toLowerCase() === barcode.toLowerCase())) {
        return `Barcode ${barcode} sudah dipakai di produk ini.`;
    }

    return null;
}

/**
 * Ambil galat server yang kuncinya berawalan `awalan.` lalu buang awalannya:
 * ({"Satuan.0.Barcode.1": "…"}, "Satuan.0") → {"Barcode.1": "…"}.
 */
export function AmbilGalatBerawalan(
    galat: Record<string, string | undefined>,
    awalan: string,
): Record<string, string | undefined> {
    const hasil: Record<string, string | undefined> = {};

    for (const [kunci, pesan] of Object.entries(galat)) {
        if (kunci.startsWith(`${awalan}.`)) {
            hasil[kunci.slice(awalan.length + 1)] = pesan;
        }
    }

    return hasil;
}

/** True bila ada galat dengan kunci persis salah satu awalan atau berawalan `awalan.` (penanda galat per tab). */
export function CekAdaGalat(galat: Record<string, string | undefined>, kunciDaftar: string[]): boolean {
    return Object.entries(galat).some(
        ([kunci, pesan]) =>
            Boolean(pesan) && kunciDaftar.some((awalan) => kunci === awalan || kunci.startsWith(`${awalan}.`)),
    );
}

/** Label pilihan tiga keadaan untuk tampilan ringkasan. */
export function LabelTigaKeadaan(nilai: TigaKeadaan, labelIkut: string): string {
    if (nilai === 'Ikut') {
        return labelIkut;
    }

    return nilai === 'Ya' ? 'Ya' : 'Tidak';
}

/** Sisipkan `baru` ke daftar string tanpa duplikat (tidak peka huruf besar/kecil). */
export function TambahUnik(daftar: string[], baru: string): string[] {
    const bersih = baru.trim();

    return bersih === '' || daftar.some((item) => item.toLowerCase() === bersih.toLowerCase())
        ? daftar
        : [...daftar, bersih];
}

/** Pindahkan item pada indeks `dari` ke `ke` (urutan kelompok pilihan, dsb.). */
export function PindahkanItem<T>(daftar: T[], dari: number, ke: number): T[] {
    if (ke < 0 || ke >= daftar.length || dari === ke) {
        return daftar;
    }

    const salinan = [...daftar];
    const [item] = salinan.splice(dari, 1);

    if (item !== undefined) {
        salinan.splice(ke, 0, item);
    }

    return salinan;
}

/** Jenis yang boleh menjadi bahan resep atau bahan pilihan (DesainF03 C.1 `CekBolehBahan`). */
export const JenisBahan: JenisProduk[] = ['BahanBaku', 'Stok', 'Produksi'];

/** Jenis yang boleh menjadi komponen paket (bisa dijual, bukan Paket, bukan IndukVarian). */
export const JenisKomponenPaket: JenisProduk[] = ['Stok', 'Resep', 'Produksi', 'Jasa', 'NonStok', 'Konsinyasi'];

/**
 * Kombinasi varian (produk kartesius) untuk pratinjau generator: [{Nama:"Ukuran",Nilai:["M","L"]}] → [["M"],["L"]].
 * Atribut tanpa nilai diabaikan.
 */
export function HitungKombinasiVarian(atribut: { Nama: string; Nilai: string[] }[]): string[][] {
    return atribut
        .filter((item) => item.Nilai.length > 0)
        .reduce<string[][]>(
            (hasil, item) => hasil.flatMap((kombinasi) => item.Nilai.map((nilai) => [...kombinasi, nilai])),
            [[]],
        )
        .filter((kombinasi) => kombinasi.length > 0);
}

/** Peristiwa yang dikirim ke isian barcode yang masih berisi ketikan belum ditambahkan (lihat `BidangBarcode`). */
export const PERISTIWA_BARCODE_TERTUNDA = 'barcode-tertunda';

/**
 * Tahan Lanjut/Simpan bila di dalam [wadah] ada isian barcode yang diketik tetapi belum ditambahkan: isian itu
 * difokuskan dan menjelaskan apa yang harus dilakukan. Barcode valid sudah ditambahkan otomatis saat isian
 * ditinggalkan, jadi yang tersisa di sini hanyalah ketikan tidak valid atau duplikat. true = ditahan.
 */
export function TahanBilaBarcodeTertunda(wadah: ParentNode | null): boolean {
    const isian = wadah?.querySelector<HTMLInputElement>('[data-barcode-tertunda="true"]');

    if (!isian) {
        return false;
    }

    const Tampilkan = () => {
        isian.focus();
        isian.dispatchEvent(new Event(PERISTIWA_BARCODE_TERTUNDA));
    };
    const panelTersembunyi = isian.closest<HTMLElement>('[role="tabpanel"][hidden]');

    if (panelTersembunyi) {
        // Mode tab: buka tab pemilik isian itu dulu, baru fokuskan setelah panelnya tampil.
        const idPemicu = panelTersembunyi.getAttribute('aria-labelledby');

        if (idPemicu !== null) {
            document.getElementById(idPemicu)?.click();
        }

        window.setTimeout(Tampilkan, 0);
    } else {
        Tampilkan();
    }

    return true;
}
