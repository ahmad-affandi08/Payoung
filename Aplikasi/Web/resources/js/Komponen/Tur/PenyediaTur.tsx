import { router, usePage } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import BalonTur from '@/Komponen/Tur/BalonTur';
import {
    KodeTurDashboard,
    SusunLangkahAktif,
    TurDashboard,
    type DefinisiTur,
    type LangkahAktif,
} from '@/Pustaka/DefinisiTur';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

type Konteks = {
    /** Memulai tur dari langkah pertama (dipanggil dari tombol Bantuan atau pemicu otomatis). */
    Mulai: (tur: DefinisiTur) => void;
    Berjalan: boolean;
};

const KonteksTur = createContext<Konteks | null>(null);

const TUNDA_MULAI_OTOMATIS_MS = 900;

/** Tanpa penyedia (mis. halaman yang diuji sendiri), tur tidak bisa dimulai tetapi tidak merusak apa pun. */
export function useTur(): Konteks {
    return useContext(KonteksTur) ?? { Mulai: () => undefined, Berjalan: false };
}

type Sesi = { Tur: DefinisiTur; Langkah: LangkahAktif[]; Indeks: number };

/**
 * Penyedia tur produk (PLT-12) di tata letak back-office. Menjalankan satu tur pada satu waktu dan mencatat hasilnya
 * (Selesai/Dilewati) ke server supaya tidak ditawarkan otomatis lagi. Beranda menawarkan tur keliling dashboard sekali
 * untuk pengguna yang belum pernah melihatnya; tur selalu bisa diulang dari tombol Bantuan.
 */
export default function PenyediaTur({ children }: { children: ReactNode }) {
    const { props } = usePage<PropsBersamaAplikasi & { TurDilihat?: string[] }>();
    const [sesi, AturSesi] = useState<Sesi | null>(null);
    const fokusSebelumnya = useRef<HTMLElement | null>(null);
    const sudahDitawarkan = useRef(false);
    const dukungan = props.AksesDukungan != null;
    const turDilihat = props.TurDilihat;

    const Mulai = useCallback((tur: DefinisiTur) => {
        const langkah = SusunLangkahAktif(tur);

        if (langkah.length === 0) {
            return;
        }

        fokusSebelumnya.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        AturSesi({ Tur: tur, Langkah: langkah, Indeks: 0 });
    }, []);

    const Akhiri = useCallback(
        (status: 'Selesai' | 'Dilewati') => {
            if (sesi === null) {
                return;
            }

            const kode = sesi.Tur.Kode;
            AturSesi(null);
            fokusSebelumnya.current?.focus();

            // Petugas dukungan yang menjalankan tur tidak mencatat apa pun atas nama pemilik akun.
            if (dukungan) {
                return;
            }

            router.post(
                `/kelola/tur/${kode}`,
                { Status: status },
                { preserveScroll: true, preserveState: true, only: ['TurDilihat'] },
            );
        },
        [sesi, dukungan],
    );

    // Beranda saja yang membawa `TurDilihat`; tawarkan tur dashboard sekali bagi pengguna yang belum melihatnya.
    useEffect(() => {
        if (
            sudahDitawarkan.current ||
            dukungan ||
            !Array.isArray(turDilihat) ||
            turDilihat.includes(KodeTurDashboard) ||
            props.Kilat
        ) {
            return;
        }

        const pewaktu = window.setTimeout(() => {
            sudahDitawarkan.current = true;
            Mulai(TurDashboard);
        }, TUNDA_MULAI_OTOMATIS_MS);

        return () => window.clearTimeout(pewaktu);
    }, [turDilihat, dukungan, props.Kilat, Mulai]);

    const nilai = useMemo<Konteks>(() => ({ Mulai, Berjalan: sesi !== null }), [Mulai, sesi]);
    const langkah = sesi?.Langkah[sesi.Indeks];

    return (
        <KonteksTur.Provider value={nilai}>
            {children}
            {sesi !== null && langkah !== undefined ? (
                <BalonTur
                    langkah={langkah}
                    indeks={sesi.Indeks}
                    total={sesi.Langkah.length}
                    saatLanjut={() =>
                        sesi.Indeks >= sesi.Langkah.length - 1
                            ? Akhiri('Selesai')
                            : AturSesi({ ...sesi, Indeks: sesi.Indeks + 1 })
                    }
                    saatKembali={() => AturSesi({ ...sesi, Indeks: Math.max(0, sesi.Indeks - 1) })}
                    saatLewati={() => Akhiri('Dilewati')}
                />
            ) : null}
        </KonteksTur.Provider>
    );
}
