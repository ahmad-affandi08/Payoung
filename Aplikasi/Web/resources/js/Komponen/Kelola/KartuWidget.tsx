import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import { Button } from '@/Komponen/Ui/button';
import { cn } from '@/Komponen/Ui/utils';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import { HitungLebarBatang } from '@/Pustaka/WidgetBeranda';

export type BarisPeringkat = { kunci: string; nama: string; kanan: string; nilai: string };

/** Judul widget lewat `Panel` (satu-satunya permukaan panel, D-28); isi dibuat memanjang supaya tautan sejajar di dasar kartu. */
export function KartuWidget({
    judul,
    angka,
    tautan,
    teksTautan,
    children,
}: {
    judul: string;
    angka: ReactNode;
    tautan: string;
    teksTautan: string;
    children: ReactNode;
}) {
    return (
        <Panel
            tingkat="h3"
            judul={judul}
            className="h-full [&_[data-slot=card-content]]:flex-1 [&_[data-slot=card-header]]:px-4"
        >
            <p className="text-judul font-semibold text-teks-utama tabular-nums">{angka}</p>
            <div className="flex flex-1 flex-col gap-3">{children}</div>
            <div className="border-t border-garis pt-3">
                <Button asChild variant="link" className="h-auto p-0 text-label font-semibold">
                    <Link href={tautan}>{teksTautan}</Link>
                </Button>
            </div>
        </Panel>
    );
}

/** Daftar peringkat: nama + nilai sebaris, batang tipis di bawahnya; peringkat pertama berwarna merek, lainnya redup. */
export function DaftarPeringkat({ baris, kosong }: { baris: BarisPeringkat[]; kosong: string }) {
    if (baris.length === 0) {
        return <p className="text-isi text-teks-sekunder">{kosong}</p>;
    }

    const terbesar = baris.reduce((maks, b) => (BandingkanDesimal(b.nilai, maks) > 0 ? b.nilai : maks), '0');

    return (
        <ol className="flex flex-col gap-3">
            {baris.map((b, urutan) => (
                <li key={b.kunci}>
                    <div className="flex items-baseline justify-between gap-3 text-isi text-teks-utama">
                        <span className="min-w-0 truncate" title={b.nama}>
                            {b.nama}
                        </span>
                        <span className="shrink-0 font-semibold tabular-nums">{b.kanan}</span>
                    </div>
                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-brand-tint" aria-hidden="true">
                        <div
                            className={cn('h-full rounded-full', urutan === 0 ? 'bg-brand' : 'bg-garis-input')}
                            style={{ width: `${String(HitungLebarBatang(b.nilai, terbesar))}%` }}
                        />
                    </div>
                </li>
            ))}
        </ol>
    );
}
