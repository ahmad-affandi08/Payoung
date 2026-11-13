import type { ColumnDef } from '@tanstack/react-table';

/**
 * Tipe bersama `TabelData` (PRD §17.4.3, D-16). Kontrak server: `{ Data, Meta }` dari `ResponsTabel` (PHP).
 */
export type MetaTabel = { Halaman: number; PerHalaman: number; Total: number; JumlahHalaman: number };
/** `Ringkasan` opsional: angka ringkasan yang dihitung server untuk saring yang sama (mis. total nilai). */
export type HasilTabel<T, R = unknown> = { Data: T[]; Meta: MetaTabel; Ringkasan?: R };

export type UrutKolom = { id: string; desc: boolean };

/** Keadaan tabel yang disimpan di URL. `saring` = nilai mentah per kunci (`A,B` untuk pilihan banyak). */
export type KeadaanTabel = {
    cari: string;
    urut: UrutKolom[];
    halaman: number;
    perHalaman: number;
    saring: Record<string, string>;
};

export type OpsiSaring = { nilai: string; label: string };

/**
 * Definisi saring:
 * - `pilihan`: satu nilai; `pilihanBanyak`: beberapa nilai dipisah koma;
 * - `rentangTanggal`: `YYYY-MM-DD..YYYY-MM-DD`; `ya`: sakelar ya/tidak (`1`).
 */
export type DefinisiSaring = {
    id: string;
    label: string;
    jenis: 'pilihan' | 'pilihanBanyak' | 'rentangTanggal' | 'ya';
    opsi?: OpsiSaring[];
    /** Label chip untuk saring `ya` (mis. "Perlu ditinjau"). */
    labelAktif?: string;
    /**
     * Nilai yang berlaku server saat saring kosong (mis. status `Aktif`). Ditampilkan sebagai pilihan terpilih dan
     * tidak ditulis ke URL; memilih nilai ini kembali mengosongkan saring.
     */
    nilaiBawaan?: string;
};

/** Prioritas tampil kolom di layar sempit (§17.4.4). */
export type PrioritasKolom = 'utama' | 'penting' | 'rendah';

/** Metadata kolom `TabelData` (di `meta` definisi kolom TanStack). */
export type MetaKolom = {
    /** Nama kolom untuk Atur kolom, daftar bertumpuk, dan pembaca layar. */
    label: string;
    /** `utama` = judul baris di HP; `penting` = tampil di HP & tablet; `rendah` = disembunyikan di bawah 1024px. */
    prioritas?: PrioritasKolom;
    /** Angka/uang: rata kanan + tabular. */
    angka?: boolean;
    /** Kolom yang tidak boleh disembunyikan (identitas, aksi). */
    wajib?: boolean;
    /** Kelas tambahan untuk sel. */
    kelasSel?: string;
    /** Daftar bertumpuk HP: lewati pasangan label–nilai bila nilai kolom (accessor) null, misal baris judul laporan. */
    sembunyiBilaKosong?: boolean;
    /** Daftar bertumpuk HP: sel selebar kartu dengan label di atasnya, untuk isi lebar seperti tabel isian. */
    penuhDiHp?: boolean;
};

/** Definisi kolom halaman: kolom TanStack dengan `meta` bertipe `MetaKolom`. */
export type KolomTabel<T> = ColumnDef<T, unknown> & { meta?: MetaKolom };

/** Membaca `meta` kolom sebagai `MetaKolom` (TanStack menyimpannya tanpa tipe). */
export function AmbilMeta(meta: unknown): MetaKolom | undefined {
    return meta as MetaKolom | undefined;
}

export const UKURAN_HALAMAN = [25, 50, 100] as const;
