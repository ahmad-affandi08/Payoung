import type { ReactNode } from 'react';

import { cn } from '@/Komponen/Ui/utils';

import TautanSitus from './TautanSitus';

type PropsTombolSitus = {
    href: string;
    children: ReactNode;
    varian?: 'utama' | 'kedua' | 'garis-merek' | 'terang' | 'garis-terang' | 'aksen' | 'tinta' | 'garis-tinta';
    ukuran?: 'sedang' | 'besar';
    className?: string;
};

const KELAS_VARIAN = {
    utama: 'bg-brand text-brand-teks hover:bg-brand-gelap',
    kedua: 'border border-garis-input bg-permukaan text-teks-utama hover:bg-permukaan-sorot',
    // Tombol kedua bergaris tipis warna merek: warna dipakai sebagai garis, bukan isian (aturan 90/10 §17.6.3).
    'garis-merek': 'border border-brand text-brand hover:bg-brand-lembut',
    terang: 'bg-permukaan text-brand-gelap hover:bg-brand-lembut',
    'garis-terang': 'border border-brand-gelap-teks text-permukaan hover:bg-brand-gelap-sorot',
    // D-93: tombol Aksen (apricot) = ajakan utama di atas latar gelap/terang; Tinta & garis-tinta di atas pita apricot.
    aksen: 'bg-aksen text-teks-utama hover:bg-aksen/85',
    tinta: 'bg-teks-utama text-permukaan hover:bg-brand-gelap',
    'garis-tinta': 'border-2 border-teks-utama text-teks-utama hover:bg-permukaan/40',
} as const;

/** Tombol ajakan situs pemasaran (D-21); target sentuh ≥ 44px. */
export default function TombolSitus({
    href,
    children,
    varian = 'utama',
    ukuran = 'sedang',
    className,
}: PropsTombolSitus) {
    return (
        <TautanSitus
            href={href}
            className={cn(
                'inline-flex items-center justify-center gap-2 rounded-full font-semibold transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                ukuran === 'besar' ? 'min-h-12 px-6 text-subjudul' : 'min-h-11 px-4 text-isi',
                KELAS_VARIAN[varian],
                className,
            )}
        >
            {children}
        </TautanSitus>
    );
}
