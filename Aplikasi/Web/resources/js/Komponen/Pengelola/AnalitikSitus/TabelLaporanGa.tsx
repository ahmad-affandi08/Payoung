import type { Row } from '@tanstack/react-table';
import { useMemo } from 'react';

import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatNilaiGa } from '@/Pustaka/FormatAnalitikGoogle';
import type { BarisGa, TabelGa } from '@/Tipe/AnalitikGoogle';

type BarisTabel = BarisGa & { _Id: string };

/**
 * Tabel Google Analytics generik: kolom dibangun dari definisi server (`Kolom`), jadi laporan baru tidak perlu
 * komponen baru. Kolom angka diurutkan sebagai angka, bukan teks, dan tampil dengan format Indonesia.
 */
export default function TabelLaporanGa({
    tabel,
    awalan,
    cari = false,
    kosong = 'Belum ada data pada periode ini.',
}: {
    tabel: TabelGa;
    awalan: string;
    cari?: boolean;
    kosong?: string;
}) {
    const kolom = useMemo<KolomTabel<BarisTabel>[]>(
        () =>
            tabel.Kolom.map((definisi, indeks) => {
                const teks = definisi.Jenis === 'Teks';

                return {
                    id: definisi.Kunci,
                    accessorKey: definisi.Kunci,
                    header: definisi.Label,
                    meta: {
                        label: definisi.Label,
                        angka: !teks,
                        prioritas: teks || indeks <= 2 ? 'utama' : indeks <= 4 ? 'penting' : 'rendah',
                        wajib: indeks === 0,
                        kelasSel: teks ? 'max-w-[28rem] break-words' : 'whitespace-nowrap',
                    },
                    sortingFn: teks
                        ? 'alphanumeric'
                        : (a: Row<BarisTabel>, b: Row<BarisTabel>, id: string) =>
                              Number(a.getValue(id)) - Number(b.getValue(id)),
                    cell: ({ row }: { row: Row<BarisTabel> }) =>
                        FormatNilaiGa(definisi.Jenis, row.original[definisi.Kunci] ?? ''),
                } as KolomTabel<BarisTabel>;
            }),
        [tabel.Kolom],
    );
    const data = useMemo<BarisTabel[]>(
        () => tabel.Baris.map((baris, indeks) => ({ ...baris, _Id: String(indeks) })),
        [tabel.Baris],
    );
    const kunciUrut = tabel.Kolom.find((k) => k.Jenis !== 'Teks')?.Kunci;

    return (
        <TabelData
            id={`${awalan}-${tabel.Id}`}
            label={tabel.Judul}
            kolom={kolom}
            sumber={{ mode: 'lokal', data }}
            ambilIdBaris={(baris) => baris._Id}
            {...(kunciUrut !== undefined ? { urutBawaan: `-${kunciUrut}` } : {})}
            cari={cari ? `Cari di ${tabel.Judul.toLowerCase()}` : false}
            kosong={{ judul: kosong }}
        />
    );
}
