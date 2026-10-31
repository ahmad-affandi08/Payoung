/**
 * Draf isian form di peramban (localStorage). Dipakai form dokumen panjang supaya isian tidak hilang saat tab tertutup,
 * sesi habis, atau halaman ter-refresh. Semua akses dibungkus try/catch karena penyimpanan bisa kosong atau ditolak
 * (jendela pribadi, data situs diblokir): form tetap bekerja, hanya tanpa draf.
 */

const AwalanKunci = 'payoung:draf:';
export const MasaBerlakuDrafMs = 7 * 24 * 60 * 60 * 1000;

export type IsiDraf<T> = { Versi: 1; Disimpan: string; Data: T };

export function BuatKunciDraf(uuidTenant: string, uuidPengguna: string, nama: string): string {
    return `${AwalanKunci}${uuidTenant}:${uuidPengguna}:${nama}`;
}

export function BacaDraf<T>(kunci: string, sekarang: Date = new Date()): IsiDraf<T> | null {
    try {
        const mentah = window.localStorage.getItem(kunci);

        if (mentah === null) {
            return null;
        }

        const isi = JSON.parse(mentah) as Partial<IsiDraf<T>>;
        const disimpan = typeof isi.Disimpan === 'string' ? new Date(isi.Disimpan) : null;

        if (isi.Versi !== 1 || isi.Data === undefined || disimpan === null || Number.isNaN(disimpan.getTime())) {
            window.localStorage.removeItem(kunci);

            return null;
        }

        if (sekarang.getTime() - disimpan.getTime() > MasaBerlakuDrafMs) {
            window.localStorage.removeItem(kunci);

            return null;
        }

        return { Versi: 1, Disimpan: isi.Disimpan as string, Data: isi.Data };
    } catch {
        return null;
    }
}

export function TulisDraf<T>(kunci: string, data: T, sekarang: Date = new Date()): void {
    try {
        const isi: IsiDraf<T> = { Versi: 1, Disimpan: sekarang.toISOString(), Data: data };

        window.localStorage.setItem(kunci, JSON.stringify(isi));
    } catch {
        // Penyimpanan penuh atau ditolak: lewati, form tetap bisa dipakai.
    }
}

export function HapusDraf(kunci: string): void {
    try {
        window.localStorage.removeItem(kunci);
    } catch {
        // Tidak ada yang perlu dihapus.
    }
}
