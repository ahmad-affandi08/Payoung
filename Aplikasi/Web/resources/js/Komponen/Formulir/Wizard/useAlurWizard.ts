import { useState } from 'react';

import { CariLangkahTerdekat, type LangkahWizard } from './TipeWizard';

export type KontrolWizard<K extends string = string> = {
    langkah: LangkahWizard<K>[];
    aktif: K;
    indeksAktif: number;
    /** Bertambah setiap kali isian bermasalah perlu difokuskan (lanjut ditolak, server menolak). */
    nomorFokus: number;
    /** Langkah ini boleh dibuka: sudah dicapai sebelumnya (atau sedang aktif). Langkah di depannya terkunci. */
    CekTerbuka: (kunci: K) => boolean;
    /** Sudah pernah dicapai (dipakai tombol "Kembali ke tinjauan"). */
    CekTerjangkau: (kunci: K) => boolean;
    /** Pindah ke langkah berikutnya bila langkah ini valid. */
    Maju: () => boolean;
    Mundur: () => void;
    /**
     * Pindah ke langkah yang boleh dibuka. Maju ke langkah yang sudah dicapai memeriksa semua langkah di antaranya;
     * berhenti di langkah pertama yang tidak valid.
     */
    Lompat: (kunci: K) => boolean;
    /** Paksa membuka satu langkah (server menolak isian di dalamnya) dan fokuskan isian bermasalah. */
    Paksa: (kunci: K) => void;
};

type OpsiAlur<K extends string> = {
    /** Langkah pertama. */
    awal: K;
    /** Urutan baku semua langkah yang mungkin; daftar langkah aktif selalu subhimpunan berurutan darinya. */
    urutan: readonly K[];
};

/**
 * Keadaan wizard: langkah aktif dan langkah yang sudah dicapai. `periksa(kunci)` memeriksa satu langkah,
 * menampilkan galatnya, dan mengembalikan true bila valid. Bila daftar langkah berubah dan langkah aktif hilang,
 * langkah aktif mundur ke langkah relevan terdekat.
 */
export function useAlurWizard<K extends string>(
    langkah: LangkahWizard<K>[],
    periksa: (kunci: K) => boolean,
    { awal, urutan }: OpsiAlur<K>,
): KontrolWizard<K> {
    const [aktifMentah, AturAktif] = useState<K>(awal);
    const [terjangkau, AturTerjangkau] = useState<K[]>([awal]);
    const [nomorFokus, AturNomorFokus] = useState(0);
    const aktif = CariLangkahTerdekat(langkah, aktifMentah, urutan);
    const indeksAktif = Math.max(
        0,
        langkah.findIndex((item) => item.Kunci === aktif),
    );
    const indeksTerjauh = langkah.reduce(
        (terjauh, item, indeks) => (terjangkau.includes(item.Kunci) ? Math.max(terjauh, indeks) : terjauh),
        indeksAktif,
    );

    const Buka = (kunci: K) => {
        AturAktif(kunci);
        AturTerjangkau((lama) => (lama.includes(kunci) ? lama : [...lama, kunci]));
    };
    const Gagal = (kunci: K) => {
        AturAktif(kunci);
        AturNomorFokus((lama) => lama + 1);

        return false;
    };

    const Pindah = (indeksTujuan: number): boolean => {
        const tujuan = langkah[indeksTujuan];

        if (!tujuan || indeksTujuan === indeksAktif) {
            return indeksTujuan === indeksAktif;
        }

        if (indeksTujuan < indeksAktif) {
            Buka(tujuan.Kunci);

            return true;
        }

        for (let i = indeksAktif; i < indeksTujuan; i += 1) {
            const antara = langkah[i];

            if (antara && !periksa(antara.Kunci)) {
                return Gagal(antara.Kunci);
            }
        }

        Buka(tujuan.Kunci);

        return true;
    };

    return {
        langkah,
        aktif,
        indeksAktif,
        nomorFokus,
        CekTerbuka: (kunci) => {
            const indeks = langkah.findIndex((item) => item.Kunci === kunci);

            return indeks >= 0 && indeks <= indeksTerjauh;
        },
        CekTerjangkau: (kunci) => terjangkau.includes(kunci),
        Maju: () => Pindah(indeksAktif + 1),
        Mundur: () => {
            if (indeksAktif > 0) {
                Pindah(indeksAktif - 1);
            }
        },
        Lompat: (kunci) => {
            const indeks = langkah.findIndex((item) => item.Kunci === kunci);

            return indeks >= 0 && indeks <= indeksTerjauh && Pindah(indeks);
        },
        Paksa: (kunci) => {
            // Server menolak setelah pengguna sampai di Tinjau, jadi semua langkah sudah pernah dilewati.
            AturTerjangkau([...langkah.map((item) => item.Kunci), kunci]);
            Gagal(kunci);
        },
    };
}
