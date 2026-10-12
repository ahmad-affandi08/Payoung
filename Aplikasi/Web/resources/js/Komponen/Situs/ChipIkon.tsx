import IkonSitus from '@/Komponen/Situs/IkonSitus';
import { cn } from '@/Komponen/Ui/utils';

/**
 * Empat nada ikon situs pemasaran (D-93), semuanya dari token palet Payoung: apricot, teal, biru info, dan hijau
 * sukses, sebagai ubin berisian padat. Dipakai bergilir per butir supaya deretan kartu tidak seragam abu-abu; ikon tetap
 * garis tajam (lucide).
 */
const NADA = [
    { terang: 'bg-aksen text-teks-utama', gelap: 'bg-aksen text-teks-utama' },
    { terang: 'bg-brand text-brand-teks', gelap: 'bg-permukaan text-brand-gelap' },
    {
        terang: 'bg-info text-permukaan',
        gelap: 'bg-aksen text-teks-utama',
    },
    { terang: 'bg-sukses text-permukaan', gelap: 'bg-permukaan text-brand-gelap' },
] as const;

type PropsChipIkon = {
    nama: string | null;
    /** Urutan butir; menentukan nada. */
    indeks?: number;
    /** Di atas latar gelap nada dibalik menjadi isian padat. */
    gelap?: boolean;
    ukuran?: 'sedang' | 'besar';
};

export default function ChipIkon({ nama, indeks = 0, gelap = false, ukuran = 'sedang' }: PropsChipIkon) {
    if (!nama) {
        return null;
    }

    const nada = NADA[indeks % NADA.length] ?? NADA[0];

    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center rounded-2xl',
                ukuran === 'besar' ? 'size-16' : 'size-12',
                gelap ? nada.gelap : nada.terang,
            )}
        >
            <IkonSitus nama={nama} className={ukuran === 'besar' ? 'size-8' : 'size-6'} />
        </span>
    );
}
