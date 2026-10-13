import type { ReactNode } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';

import type { LangkahWizard } from './TipeWizard';

/** Satu baris "nama: nilai" di ringkasan langkah Tinjau. */
export function BarisRingkasan({ nama, children }: { nama: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-3">
            <dt className="text-label text-teks-sekunder">{nama}</dt>
            <dd className="min-w-0 text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/** Satu bagian ringkasan per langkah, dengan tombol "Ubah" yang kembali ke langkah itu. */
export function BagianRingkasan<K extends string>({
    langkah,
    saatUbah,
    children,
}: {
    langkah: LangkahWizard<K>;
    saatUbah: (kunci: K) => void;
    children: ReactNode;
}) {
    return (
        <section
            aria-label={`Ringkasan ${langkah.Label}`}
            className="flex flex-col gap-2 border-b border-garis pb-4 last:border-b-0 last:pb-0"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-label font-semibold text-teks-utama">{langkah.Label}</h3>
                <Tombol varian="sekunder" aria-label={`Ubah ${langkah.Label}`} onClick={() => saatUbah(langkah.Kunci)}>
                    Ubah
                </Tombol>
            </div>
            <dl className="flex flex-col gap-1.5">{children}</dl>
        </section>
    );
}

export const TampilYaTidak = (nilai: boolean) => (nilai ? 'Ya' : 'Tidak');
