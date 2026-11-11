import { router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';

export type TombolAksiMassal<T = unknown> = {
    /** Nilai `Aksi` yang dikirim ke server. */
    aksi: string;
    label: string;
    varian?: 'sekunder' | 'bahaya';
    /**
     * Tombol hanya tampil bila ada baris terpilih yang bisa dikenai aksi ini (misal "Pulihkan" hanya bila ada yang
     * diarsipkan). Tanpa ini tombol selalu tampil.
     */
    berlaku?: (baris: T) => boolean;
};

type PropsAksiMassalSederhana<T> = {
    konteks: KonteksAksiMassal<T>;
    /** Alamat POST yang menerima `{Aksi, Uuid[]}`. */
    alamat: string;
    ambilUuid: (baris: T) => string;
    tombol: TombolAksiMassal<T>[];
    /** Batas server; pilihan lebih banyak ditolak dengan penjelasan. */
    maksimal: number;
    /** Kata benda untuk pesan batas, misal "voucher". */
    objek: string;
};

/**
 * Deretan tombol aksi untuk baris terpilih yang hanya butuh `{Aksi, Uuid[]}` (arsipkan/pulihkan, aktifkan/nonaktifkan).
 * Aksi dengan masukan tambahan (harga, tier, tambahan gaji) punya komponen sendiri.
 */
export default function AksiMassalSederhana<T>({
    konteks,
    alamat,
    ambilUuid,
    tombol,
    maksimal,
    objek,
}: PropsAksiMassalSederhana<T>) {
    const [memproses, AturMemproses] = useState<string | null>(null);
    const uuid = konteks.terpilih.map(ambilUuid);
    const terlaluBanyak = uuid.length > maksimal;
    const tampil = tombol.filter((t) => t.berlaku === undefined || konteks.terpilih.some(t.berlaku));

    return (
        <div className="flex flex-wrap items-center gap-2">
            {terlaluBanyak ? (
                <p className="w-full text-keterangan text-bahaya">
                    Maksimal {maksimal.toLocaleString('id-ID')} {objek} sekali proses. Kurangi pilihan.
                </p>
            ) : null}
            {tampil.map((t) => (
                <Tombol
                    key={t.aksi}
                    varian={t.varian ?? 'sekunder'}
                    disabled={memproses !== null || terlaluBanyak}
                    memproses={memproses === t.aksi}
                    onClick={() =>
                        router.post(
                            alamat,
                            { Aksi: t.aksi, Uuid: uuid },
                            {
                                preserveScroll: true,
                                onStart: () => AturMemproses(t.aksi),
                                onFinish: () => AturMemproses(null),
                                onSuccess: () => konteks.bersihkan(),
                            },
                        )
                    }
                >
                    {t.label}
                </Tombol>
            ))}
        </div>
    );
}
