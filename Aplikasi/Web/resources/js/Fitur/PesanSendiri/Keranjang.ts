/**
 * Keranjang halaman pesan sendiri QR meja (F-17): disimpan di localStorage per token meja agar tidak hilang saat
 * halaman dimuat ulang. Hanya menyimpan pilihan tamu (produk, jumlah, pilihan, catatan); harga selalu dari server.
 * Jumlah = bilangan bulat porsi 1–50 (bukan uang).
 */
export const BATAS_JUMLAH = 50;
export const BATAS_BARIS = 30;

export type BarisKeranjang = {
    /** Uuid baris (ULID), dipakai juga sebagai Uuid baris pesanan saat dikirim. */
    Uuid: string;
    UuidProduk: string;
    /** PRD v2.06: anak varian pilihan tamu bila `UuidProduk` induk varian. */
    UuidVarian?: string;
    Jumlah: number;
    Pilihan: string[];
    Catatan: string;
};

export type KelompokPilihanMenu = {
    Uuid: string;
    Nama: string;
    MinimalPilih: number;
    MaksimalPilih: number;
    Pilihan: { Uuid: string; Nama: string; Harga: string }[];
};

function KunciKeranjang(token: string): string {
    return `pesan-sendiri:${token}:keranjang`;
}

function KunciRiwayat(token: string): string {
    return `pesan-sendiri:${token}:pesanan`;
}

function BacaJson(kunci: string): unknown {
    try {
        const teks = window.localStorage.getItem(kunci);

        return teks === null ? null : (JSON.parse(teks) as unknown);
    } catch {
        return null;
    }
}

function TulisJson(kunci: string, nilai: unknown): void {
    try {
        window.localStorage.setItem(kunci, JSON.stringify(nilai));
    } catch {
        // Penyimpanan penuh/diblokir (mode privat): keranjang tetap jalan di memori.
    }
}

function CekBaris(nilai: unknown): nilai is BarisKeranjang {
    if (typeof nilai !== 'object' || nilai === null) {
        return false;
    }

    const b = nilai as Record<string, unknown>;

    return (
        typeof b.Uuid === 'string' &&
        typeof b.UuidProduk === 'string' &&
        (b.UuidVarian === undefined || typeof b.UuidVarian === 'string') &&
        typeof b.Jumlah === 'number' &&
        Number.isInteger(b.Jumlah) &&
        b.Jumlah >= 1 &&
        b.Jumlah <= BATAS_JUMLAH &&
        Array.isArray(b.Pilihan) &&
        b.Pilihan.every((p) => typeof p === 'string') &&
        typeof b.Catatan === 'string'
    );
}

export function BacaKeranjang(token: string): BarisKeranjang[] {
    const nilai = BacaJson(KunciKeranjang(token));

    return Array.isArray(nilai) ? nilai.filter(CekBaris).slice(0, BATAS_BARIS) : [];
}

export function SimpanKeranjang(token: string, keranjang: BarisKeranjang[]): void {
    TulisJson(KunciKeranjang(token), keranjang);
}

/** Berapa lama kartu "Pesanan terakhir" boleh bertahan di HP tamu (D-338). Cukup untuk makan dan membayar. */
export const BATAS_RIWAYAT_MS = 6 * 60 * 60 * 1000;
const BATAS_RIWAYAT = 5;

type CatatanRiwayat = {
    Uuid: string;
    /** Waktu pesanan dikirim dari HP ini (ms sejak epoch). */
    Waktu: number;
    /** Sudah ditampilkan dalam keadaan akhir (dibayar, ditolak, kedaluwarsa), hilang pada pembukaan berikutnya. */
    Selesai?: boolean;
};

function CekCatatan(nilai: unknown): nilai is CatatanRiwayat {
    if (typeof nilai !== 'object' || nilai === null) {
        return false;
    }

    const c = nilai as Record<string, unknown>;

    return (
        typeof c.Uuid === 'string' &&
        typeof c.Waktu === 'number' &&
        Number.isFinite(c.Waktu) &&
        (c.Selesai === undefined || typeof c.Selesai === 'boolean')
    );
}

/**
 * Catatan riwayat yang masih berlaku, terbaru di depan. Format lama (daftar Uuid tanpa waktu) dibaca sebagai dikirim
 * `sekarang`, jadi kartu lama tidak hilang mendadak tetapi juga tidak bertahan selamanya. Yang sudah lewat batas
 * waktu, atau sudah ditandai selesai, dibuang dari penyimpanan.
 */
function BacaCatatan(token: string, sekarang: number): CatatanRiwayat[] {
    const nilai = BacaJson(KunciRiwayat(token));
    const mentah = Array.isArray(nilai) ? (nilai as unknown[]) : [];
    const semua = mentah
        .map((butir): CatatanRiwayat | null =>
            typeof butir === 'string' ? { Uuid: butir, Waktu: sekarang } : CekCatatan(butir) ? butir : null,
        )
        .filter((c): c is CatatanRiwayat => c !== null);
    const berlaku = semua
        .filter((c) => c.Selesai !== true && sekarang - c.Waktu < BATAS_RIWAYAT_MS)
        .slice(0, BATAS_RIWAYAT);

    if (mentah.length > 0 && (berlaku.length !== mentah.length || mentah.some((b) => typeof b === 'string'))) {
        TulisJson(KunciRiwayat(token), berlaku);
    }

    return berlaku;
}

/** Uuid pesanan yang dikirim dari peramban ini untuk meja ini dan masih layak ditampilkan (terbaru di depan, maks. 5). */
export function BacaRiwayatPesanan(token: string, sekarang: number = Date.now()): string[] {
    return BacaCatatan(token, sekarang).map((c) => c.Uuid);
}

export function CatatRiwayatPesanan(token: string, uuid: string, sekarang: number = Date.now()): string[] {
    const daftar = [
        { Uuid: uuid, Waktu: sekarang },
        ...BacaCatatan(token, sekarang).filter((c) => c.Uuid !== uuid),
    ].slice(0, BATAS_RIWAYAT);
    TulisJson(KunciRiwayat(token), daftar);

    return daftar.map((c) => c.Uuid);
}

/** Tandai pesanan sudah ditampilkan dalam keadaan akhir: kartunya hilang pada pembukaan halaman berikutnya. */
export function TandaiRiwayatSelesai(token: string, uuid: string, sekarang: number = Date.now()): void {
    const daftar = BacaCatatan(token, sekarang);

    if (daftar.some((c) => c.Uuid === uuid && c.Selesai !== true)) {
        TulisJson(
            KunciRiwayat(token),
            daftar.map((c) => (c.Uuid === uuid ? { ...c, Selesai: true } : c)),
        );
    }
}

/** Buang satu pesanan dari riwayat (tamu menutup kartunya). Mengembalikan riwayat yang tersisa. */
export function HapusRiwayatPesanan(token: string, uuid: string, sekarang: number = Date.now()): string[] {
    const sisa = BacaCatatan(token, sekarang).filter((c) => c.Uuid !== uuid);
    TulisJson(KunciRiwayat(token), sisa);

    return sisa.map((c) => c.Uuid);
}

function SamaPilihan(a: string[], b: string[]): boolean {
    return a.length === b.length && [...a].sort().join('|') === [...b].sort().join('|');
}

/** Tambah ke keranjang; produk, varian & pilihan sama tanpa catatan digabung (jumlah dibatasi 50). */
export function TambahKeKeranjang(keranjang: BarisKeranjang[], baru: BarisKeranjang): BarisKeranjang[] {
    const indeks =
        baru.Catatan === ''
            ? keranjang.findIndex(
                  (b) =>
                      b.UuidProduk === baru.UuidProduk &&
                      b.UuidVarian === baru.UuidVarian &&
                      b.Catatan === '' &&
                      SamaPilihan(b.Pilihan, baru.Pilihan),
              )
            : -1;

    if (indeks >= 0) {
        return keranjang.map((b, i) =>
            i === indeks ? { ...b, Jumlah: Math.min(BATAS_JUMLAH, b.Jumlah + baru.Jumlah) } : b,
        );
    }

    return keranjang.length >= BATAS_BARIS ? keranjang : [...keranjang, baru];
}

/** Ubah jumlah baris; di bawah 1 = baris dihapus. */
export function UbahJumlah(keranjang: BarisKeranjang[], uuid: string, jumlah: number): BarisKeranjang[] {
    if (jumlah < 1) {
        return keranjang.filter((b) => b.Uuid !== uuid);
    }

    return keranjang.map((b) => (b.Uuid === uuid ? { ...b, Jumlah: Math.min(BATAS_JUMLAH, jumlah) } : b));
}

export function HitungJumlahItem(keranjang: BarisKeranjang[]): number {
    return keranjang.reduce((total, b) => total + b.Jumlah, 0);
}

/** Pesan bila pilihan belum memenuhi aturan kelompok (null = sudah benar). Server tetap memeriksa ulang. */
export function PeriksaPilihan(kelompok: KelompokPilihanMenu[], dipilih: string[]): string | null {
    for (const k of kelompok) {
        const jumlah = k.Pilihan.filter((p) => dipilih.includes(p.Uuid)).length;

        if (jumlah < k.MinimalPilih) {
            return k.MinimalPilih === 1
                ? `Pilih ${k.Nama} dulu.`
                : `Pilih minimal ${String(k.MinimalPilih)} ${k.Nama}.`;
        }

        if (jumlah > k.MaksimalPilih) {
            return `Pilih paling banyak ${String(k.MaksimalPilih)} ${k.Nama}.`;
        }
    }

    return null;
}

/** Ubah pilihan: kelompok maksimal 1 berperilaku seperti radio; lainnya centang dengan batas maksimal. */
export function AlihkanPilihan(kelompok: KelompokPilihanMenu, dipilih: string[], uuid: string): string[] {
    const milikKelompok = new Set(kelompok.Pilihan.map((p) => p.Uuid));

    if (dipilih.includes(uuid)) {
        return dipilih.filter((u) => u !== uuid);
    }

    if (kelompok.MaksimalPilih === 1) {
        return [...dipilih.filter((u) => !milikKelompok.has(u)), uuid];
    }

    const terpilih = dipilih.filter((u) => milikKelompok.has(u)).length;

    return terpilih >= kelompok.MaksimalPilih ? dipilih : [...dipilih, uuid];
}
