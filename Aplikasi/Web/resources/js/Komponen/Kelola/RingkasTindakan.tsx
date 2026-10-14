import { Link } from '@inertiajs/react';
import { useId } from 'react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { ButirTindakan, TingkatTindakan } from '@/Tipe/Tindakan';

export const JenisLabelTingkat: Record<TingkatTindakan, 'bahaya' | 'peringatan' | 'netral'> = {
    Penting: 'bahaya',
    Perhatian: 'peringatan',
    Info: 'netral',
};

const batasBeranda = 5;

/** Kartu "Perlu tindakan" di beranda (D-23 C): butir teratas + tautan ke Kotak Tindakan. Kosong = tidak tampil. */
export default function RingkasTindakan({ butir }: { butir: ButirTindakan[] }) {
    const idJudul = useId();

    if (butir.length === 0) {
        return null;
    }

    return (
        <Card
            data-tur="kotak-tindakan"
            role="region"
            aria-labelledby={idJudul}
            className="gap-3 rounded-panel px-4 py-4 shadow-none sm:px-6 sm:py-6"
        >
            <CardHeader className="flex flex-wrap items-baseline justify-between gap-2 px-0 [.border-b]:pb-0">
                <CardTitle className="text-subjudul font-semibold text-teks-utama">
                    <h2 id={idJudul}>Perlu tindakan</h2>
                </CardTitle>
                <CardDescription className="text-label text-teks-sekunder">
                    <Link href="/kelola/tindakan" className="font-semibold text-brand underline">
                        Buka Kotak Tindakan ({String(butir.length)})
                    </Link>
                </CardDescription>
            </CardHeader>
            <CardContent className="px-0">
                <ul className="flex flex-col">
                    {butir.slice(0, batasBeranda).map((b) => (
                        <li
                            key={b.Kunci}
                            className="flex flex-col gap-1 border-b border-garis py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-isi font-semibold break-words text-teks-utama">
                                    {b.Judul} <span className="tabular-nums">({b.Jumlah.toLocaleString('id-ID')})</span>
                                </span>
                                <p className="text-label text-teks-sekunder">{b.Keterangan}</p>
                            </div>
                            <div className="flex shrink-0 items-center gap-3">
                                <LabelStatus jenis={JenisLabelTingkat[b.Tingkat]} teks={b.Tingkat} />
                                <Link href={b.Tautan} className="text-label font-semibold text-brand underline">
                                    {b.LabelTautan}
                                </Link>
                            </div>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
