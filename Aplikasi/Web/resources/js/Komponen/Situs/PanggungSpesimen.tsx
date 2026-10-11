import type { ReactNode } from 'react';

import { cn } from '@/Komponen/Ui/utils';

type PropsPanggung = {
    /** Warna dasar panggung: `aksen` (apricot) untuk latar terang, `teal` untuk latar gelap, `lembut` untuk netral. */
    warna?: 'aksen' | 'teal' | 'lembut';
    className?: string;
    children: ReactNode;
    /** Ikut ukuran induk (`container-type: size`) seperti panggung hero, bukan mengikuti isi. */
    tetap?: boolean;
};

const WARNA = {
    aksen: { dasar: 'bg-aksen', bulat1: 'bg-brand', bulat2: 'bg-permukaan/25' },
    teal: { dasar: 'bg-brand', bulat1: 'bg-aksen', bulat2: 'bg-brand-gelap' },
    lembut: { dasar: 'bg-brand-lembut', bulat1: 'bg-aksen-lembut', bulat2: 'bg-brand-lembut-sorot' },
} as const;

/**
 * Panggung berwarna tempat tangkapan layar aplikasi dipajang (D-93). Menggantikan kotak abu-abu bergaris: bidang warna
 * penuh dengan dua lingkaran padat sebagai dekorasi (tanpa gradien), terpotong rapi di tepi, di belakang gambar.
 * Dekorasinya `aria-hidden`; isinya tetap gambar/spesimen asli.
 */
export default function PanggungSpesimen({ warna = 'aksen', className, children, tetap = false }: PropsPanggung) {
    const w = WARNA[warna];

    return (
        <div
            className={cn(
                'relative isolate flex w-full items-center justify-center overflow-hidden rounded-[2rem] p-4 sm:p-8',
                tetap && 'aspect-[4/3] [container-type:size] sm:aspect-[16/11]',
                w.dasar,
                className,
            )}
        >
            <span aria-hidden className={cn('absolute -top-16 -right-16 -z-10 size-56 rounded-full', w.bulat1)} />
            <span aria-hidden className={cn('absolute -bottom-20 -left-12 -z-10 size-52 rounded-full', w.bulat2)} />
            {children}
        </div>
    );
}
