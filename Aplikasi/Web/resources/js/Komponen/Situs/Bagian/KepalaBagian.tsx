import type { ReactNode } from 'react';

import { cn } from '@/Komponen/Ui/utils';

/** Latar satu bagian. `navy`/`merek` adalah jeda gelap yang memutus deretan bagian terang (D-25). */
export type LatarBagian = 'permukaan' | 'latar' | 'navy' | 'merek' | 'teal' | 'aksen' | 'brand';

/** Bagian berlatar gelap: teks dibalik menjadi terang. */
export function CekGelap(latar: LatarBagian): boolean {
    return latar === 'navy' || latar === 'merek' || latar === 'brand';
}

type PropsKepalaBagian = {
    label?: string | null;
    judul?: string | null;
    subjudul?: string | null;
    rata?: 'tengah' | 'kiri';
    gelap?: boolean;
};

/**
 * Label kecil, judul bagian (h2), dan pengantar. Tidak merender apa pun bila ketiganya kosong.
 *
 * D-93: bawaan **rata tengah** dengan label berbentuk pil berwarna (apricot lembut di latar terang, apricot padat di
 * latar gelap). Bagian yang kepalanya berdampingan dengan visual (gambar-teks, teks panjang, formulir) meminta `rata="kiri"`.
 * Sebelumnya (D-25) bawaannya rata kiri dengan garis aksen tipis di atas judul, yang terbaca seragam dan generik.
 */
export function KepalaBagian({ label, judul, subjudul, rata = 'tengah', gelap = false }: PropsKepalaBagian) {
    if (!label && !judul && !subjudul) {
        return null;
    }

    return (
        <div
            className={cn(
                'mb-10 flex max-w-3xl flex-col gap-3',
                rata === 'tengah' ? 'mx-auto items-center text-center' : '',
            )}
        >
            {label ? (
                <p
                    className={cn(
                        'rounded-full px-3 py-1 text-label font-semibold tracking-wide uppercase',
                        gelap ? 'bg-aksen text-teks-utama' : 'bg-aksen-lembut text-peringatan',
                    )}
                >
                    {label}
                </p>
            ) : null}
            {judul ? (
                <h2
                    className={cn(
                        'text-judul-bagian-hp font-bold sm:text-judul-bagian',
                        gelap ? 'text-permukaan' : 'text-teks-utama',
                    )}
                >
                    {judul}
                </h2>
            ) : null}
            {subjudul ? (
                <p
                    className={cn(
                        'text-pengantar whitespace-pre-line',
                        gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                    )}
                >
                    {subjudul}
                </p>
            ) : null}
        </div>
    );
}

type PropsWadahBagian = {
    children: ReactNode;
    latar?: LatarBagian;
    id?: string;
    sempit?: boolean;
    /** Garis 1px pemisah, dipakai bila bagian sebelumnya juga terang (beda `latar`/`permukaan` hampir tak terlihat). */
    garisAtas?: boolean | undefined;
};

const KELAS_LATAR: Record<LatarBagian, string> = {
    permukaan: 'bg-permukaan',
    latar: 'bg-latar',
    navy: 'bg-teks-utama',
    merek: 'bg-brand-gelap',
    // D-93: pita warna. `teal`/`aksen` = nuansa lembut berselang-seling; `brand` = teal pekat untuk angka & sorotan.
    teal: 'bg-brand-lembut',
    aksen: 'bg-aksen-lembut',
    brand: 'bg-brand',
};

/** Pembungkus satu bagian: latar, jarak vertikal, lebar isi seragam, dan animasi masuk (D-25). */
export function WadahBagian({
    children,
    latar = 'permukaan',
    id,
    sempit = false,
    garisAtas = false,
}: PropsWadahBagian) {
    return (
        <section id={id} className={cn('py-16 sm:py-24', KELAS_LATAR[latar], garisAtas && 'border-t border-garis')}>
            <div className={cn('muncul-saat-gulir mx-auto px-4', sempit ? 'max-w-3xl' : 'max-w-6xl')}>{children}</div>
        </section>
    );
}

/**
 * Kelas kartu di dalam bagian. Bingkai hanya dipakai bila kartu benar-benar bisa diklik atau perlu
 * dipisahkan dari latar; di bagian gelap kartu memakai garis merek agar tetap terbaca.
 */
export function KelasKartu(latar: LatarBagian, dapatDiklik = false): string {
    if (CekGelap(latar)) {
        return cn('rounded-panel border border-brand-gelap-garis p-6', dapatDiklik && 'hover:border-brand-gelap-teks');
    }

    return cn(
        'rounded-3xl border border-garis p-6 transition-[border-color,transform] motion-reduce:transition-none',
        // Kartu putih di atas pita berwarna; di atas putih memakai latar krem supaya tetap terbaca sebagai kartu.
        latar === 'permukaan' ? 'bg-latar' : 'bg-permukaan',
        dapatDiklik && 'hover:-translate-y-0.5 hover:border-brand',
    );
}

/** Gambar situs dengan dimensi asli (mengurangi pergeseran tata letak) dan muat malas. */
export function GambarBagian({
    gambar,
    className,
    prioritas = false,
}: {
    gambar: { Url: string; Alt: string; Lebar: number | null; Tinggi: number | null };
    className?: string;
    prioritas?: boolean;
}) {
    return (
        <img
            src={gambar.Url}
            alt={gambar.Alt}
            width={gambar.Lebar ?? undefined}
            height={gambar.Tinggi ?? undefined}
            loading={prioritas ? 'eager' : 'lazy'}
            decoding="async"
            className={className}
        />
    );
}
