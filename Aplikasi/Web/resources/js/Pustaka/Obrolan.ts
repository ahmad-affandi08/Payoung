/**
 * Logika murni obrolan langsung dengan tim dukungan (PLT-12, D-163): dipakai panel di lembar Bantuan (tenant) dan inbox
 * petugas di konsol. Percakapan diambil berkala (polling) dengan penanda "pesan terakhir yang sudah dimiliki".
 */
export type JenisPengirimObrolan = 'Pengguna' | 'Pengelola' | 'Sistem';

export type PesanObrolan = {
    Uuid: string;
    JenisPengirim: JenisPengirimObrolan;
    NamaPengirim: string;
    Isi: string;
    DibuatPada: string;
};

export type LayananObrolan = {
    Buka: boolean;
    PetugasOnline: number;
    DalamJamKerja: boolean;
    Pesan: string;
    SegarkanDetik: number;
    MaksPanjangPesan: number;
};

export type ObrolanTenant = {
    Uuid: string;
    Nomor: string;
    Status: string;
    LabelStatus: string;
    Petugas: string | null;
    Pesan: PesanObrolan[];
};

export type KeadaanObrolanTenant = {
    Layanan: LayananObrolan;
    Obrolan: ObrolanTenant | null;
    Baru?: boolean;
};

/** Menambahkan pesan baru ke daftar lama tanpa menggandakan pesan yang sudah ada (kunci: `Uuid`), urutan tetap. */
export function GabungPesan(lama: PesanObrolan[], baru: PesanObrolan[]): PesanObrolan[] {
    const ada = new Set(lama.map((pesan) => pesan.Uuid));
    const tambahan = baru.filter((pesan) => !ada.has(pesan.Uuid));

    return tambahan.length === 0 ? lama : [...lama, ...tambahan];
}

/** Penanda polling berikutnya: `Uuid` pesan terakhir, atau null bila belum ada pesan. */
export function AmbilPenanda(pesan: PesanObrolan[]): string | null {
    return pesan.length === 0 ? null : (pesan[pesan.length - 1]?.Uuid ?? null);
}

/** Pesan layak dikirim: tidak kosong setelah dipangkas dan tidak melewati batas karakter. */
export function CekBisaKirim(isi: string, maksimal: number): boolean {
    const panjang = isi.trim().length;

    return panjang > 0 && panjang <= maksimal;
}

/** Jumlah obrolan yang menunggu balasan petugas. */
export function HitungMenunggu(obrolan: { MenungguBalasan: boolean }[]): number {
    return obrolan.filter((baris) => baris.MenungguBalasan).length;
}

/** Judul tab peramban; angka di depan menandai obrolan yang menunggu balasan. */
export function SusunJudulTab(judulAsli: string, menunggu: number): string {
    return menunggu > 0 ? `(${String(menunggu)}) ${judulAsli}` : judulAsli;
}

/** Lama menunggu dalam bahasa sehari-hari: "baru saja", "3 mnt", "2 jam", "3 hari". */
export function FormatLamaMenunggu(dari: string, sekarang: number): string {
    const detik = Math.max(0, Math.floor((sekarang - new Date(dari).getTime()) / 1000));

    if (Number.isNaN(detik) || detik < 60) {
        return 'baru saja';
    }

    const menit = Math.floor(detik / 60);

    if (menit < 60) {
        return `${String(menit)} mnt`;
    }

    const jam = Math.floor(menit / 60);

    return jam < 24 ? `${String(jam)} jam` : `${String(Math.floor(jam / 24))} hari`;
}
