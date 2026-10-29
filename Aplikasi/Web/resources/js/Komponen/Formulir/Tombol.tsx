import type { ButtonHTMLAttributes, ReactNode } from 'react';

import { Button } from '@/Komponen/Ui/button';
import { Spinner } from '@/Komponen/Ui/spinner';

type PropsTombol = {
    varian?: 'utama' | 'sekunder' | 'bahaya';
    /** `besar`: satu aksi utama selebar layar di halaman HP (misal absensi web), tinggi 56px. */
    ukuran?: 'normal' | 'besar';
    /** `pil`: sudut bulat penuh untuk aksi utama di halaman publik toko (D-238). Bawaan mengikuti kontrol back-office. */
    bentuk?: 'normal' | 'pil';
    memproses?: boolean;
    children: ReactNode;
} & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'>;

/** Varian shadcn/ui per makna: aksi utama = brand (default), sekunder = outline, hapus/void = destructive. */
const varianUi = { utama: 'default', sekunder: 'outline', bahaya: 'destructive' } as const;

const kelasUkuran = {
    normal: 'h-8 pointer-coarse:h-11 px-4 text-label',
    besar: 'h-14 w-full px-6 text-subjudul',
} as const;

/** Tombol dengan label kata kerja spesifik (PRD §17.6.7). Warna brand hanya untuk aksi utama. */
export default function Tombol({
    varian = 'utama',
    ukuran = 'normal',
    bentuk = 'normal',
    memproses = false,
    children,
    disabled,
    type,
    ...atribut
}: PropsTombol) {
    return (
        <Button
            type={type ?? 'button'}
            variant={varianUi[varian]}
            disabled={disabled === true || memproses}
            aria-busy={memproses || undefined}
            className={`${kelasUkuran[ukuran]} ${bentuk === 'pil' ? 'rounded-full' : ''} font-semibold focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60`}
            {...atribut}
        >
            {memproses ? (
                <>
                    <Spinner role={undefined} aria-label={undefined} aria-hidden="true" />
                    Memproses…
                </>
            ) : (
                children
            )}
        </Button>
    );
}
