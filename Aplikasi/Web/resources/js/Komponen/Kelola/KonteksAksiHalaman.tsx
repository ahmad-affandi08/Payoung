import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';

/**
 * Satu aksi sekunder halaman (mis. "Impor dari Excel"). Di layar lebar tampil sebagai tombol di samping aksi utama,
 * di HP masuk ke menu "Lainnya" supaya yang berwarna hanya aksi utama (D-228).
 */
export type ItemAksiHalaman = {
    label: string;
    /** Tautan Inertia (halaman lain di aplikasi). */
    href?: string;
    /** Dengan `href`: tautan biasa (unduhan berkas), bukan navigasi Inertia. */
    unduh?: boolean;
    /** Buka tautan di tab baru (tautan biasa, bukan Inertia). */
    tabBaru?: boolean;
    /** Aksi sedang berjalan atau belum boleh dipakai: butir tidak bisa dipilih. */
    nonaktif?: boolean;
    /** Aksi merusak (batalkan, hentikan): teks merah. */
    bahaya?: boolean;
    saatKlik?: () => void;
};

type NilaiKonteks = {
    /** Wadah di bilah alat tabel tempat aksi utama dipindahkan di HP; `null` bila halaman tidak punya tabel. */
    slot: HTMLElement | null;
    PasangSlot: (elemen: HTMLElement | null) => void;
    lainnya: ItemAksiHalaman[];
    AturLainnya: (butir: ItemAksiHalaman[]) => void;
};

const Konteks = createContext<NilaiKonteks | null>(null);

function SamaIsi(a: ItemAksiHalaman[], b: ItemAksiHalaman[]): boolean {
    return (
        a.length === b.length &&
        a.every((butir, indeks) => {
            const lain = b[indeks];

            return (
                lain !== undefined &&
                butir.label === lain.label &&
                butir.href === lain.href &&
                butir.unduh === lain.unduh &&
                butir.tabBaru === lain.tabBaru &&
                butir.nonaktif === lain.nonaktif &&
                butir.bahaya === lain.bahaya &&
                butir.saatKlik === lain.saatKlik
            );
        })
    );
}

/**
 * Menghubungkan `AksiHalaman` (di atas tabel) dengan bilah alat `TabelData` (D-228). Di HP kedua blok itu memakan dua
 * baris sendiri-sendiri; dengan konteks ini aksi utama pindah ke baris yang sama dengan Filter dan menu "Lainnya".
 * Tanpa penyedia (mis. di test komponen) `AksiHalaman` dan `TabelData` tetap jalan sendiri-sendiri.
 */
export function PenyediaAksiHalaman({ children }: { children: ReactNode }) {
    const [slot, AturSlot] = useState<HTMLElement | null>(null);
    const [lainnya, AturDaftar] = useState<ItemAksiHalaman[]>([]);

    const PasangSlot = useCallback((elemen: HTMLElement | null) => AturSlot(elemen), []);
    const AturLainnya = useCallback(
        (butir: ItemAksiHalaman[]) => AturDaftar((lama) => (SamaIsi(lama, butir) ? lama : butir)),
        [],
    );
    const nilai = useMemo(() => ({ slot, PasangSlot, lainnya, AturLainnya }), [slot, PasangSlot, lainnya, AturLainnya]);

    return <Konteks.Provider value={nilai}>{children}</Konteks.Provider>;
}

export function useAksiHalaman(): NilaiKonteks | null {
    return useContext(Konteks);
}

/**
 * Wadah di bilah alat tabel tempat `AksiHalaman` memindahkan aksi utamanya di HP. Ada di komponen sendiri supaya
 * `ref` callback-nya hanya dipegang di sini, bukan di bilah alat yang merender banyak hal lain.
 */
export function SlotAksiUtama() {
    const konteks = useContext(Konteks);
    const PasangSlot = konteks?.PasangSlot;
    const Referensi = useCallback((elemen: HTMLDivElement | null) => PasangSlot?.(elemen), [PasangSlot]);

    if (konteks === null) {
        return null;
    }

    return (
        <div
            ref={Referensi}
            data-slot="aksi-utama-halaman"
            className="flex min-w-0 flex-1 items-center justify-end gap-2 [&>*]:flex-1"
        />
    );
}
