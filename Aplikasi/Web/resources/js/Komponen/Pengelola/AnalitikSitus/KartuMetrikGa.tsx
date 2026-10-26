import { ArrowDown, ArrowUp, Minus } from 'lucide-react';

import { Card } from '@/Komponen/Ui/card';
import { FormatNilaiGa, HitungPerubahanGa } from '@/Pustaka/FormatAnalitikGoogle';
import type { KartuGa } from '@/Tipe/AnalitikGoogle';

const ikonArah = { naik: ArrowUp, turun: ArrowDown, tetap: Minus, baru: ArrowUp } as const;
const kataArah = { naik: 'naik', turun: 'turun', tetap: 'tidak berubah', baru: 'baru' } as const;

/**
 * Satu angka Google Analytics dengan pembanding periode sebelumnya. Arah ditulis dengan teks dan ikon, tidak dengan
 * warna baik/buruk: pada tingkat pentalan, turun justru bagus, jadi warna akan menyesatkan.
 */
export default function KartuMetrikGa({ kartu }: { kartu: KartuGa }) {
    const perubahan = kartu.Pembanding === null ? null : HitungPerubahanGa(kartu.Nilai, kartu.Pembanding);
    const Ikon = perubahan === null ? null : ikonArah[perubahan.Arah];

    return (
        <Card className="flex flex-col gap-1 rounded-panel p-4 shadow-none">
            <span className="text-keterangan text-teks-sekunder">{kartu.Label}</span>
            <span className="text-judul font-semibold text-teks-utama tabular-nums">
                {FormatNilaiGa(kartu.Jenis, kartu.Nilai)}
            </span>
            {perubahan !== null && Ikon !== null && kartu.Pembanding !== null ? (
                <span className="flex items-center gap-1 text-keterangan text-teks-sekunder">
                    <Ikon className="size-3.5" aria-hidden="true" />
                    <span className="font-semibold tabular-nums">{perubahan.Teks}</span>
                    <span className="sr-only">{kataArah[perubahan.Arah]}</span>
                    <span>dari {FormatNilaiGa(kartu.Jenis, kartu.Pembanding)} sebelumnya</span>
                </span>
            ) : null}
        </Card>
    );
}
