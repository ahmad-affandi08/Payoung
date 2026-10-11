import gambarIkon from '@/Aset/Merek/IkonMerek.webp';
import gambarIkonPutih from '@/Aset/Merek/IkonMerekPutih.png';
import gambarLogo from '@/Aset/Merek/LogoHorizontal.webp';
import gambarLogoPutih from '@/Aset/Merek/LogoHorizontalPutih.webp';
import { cn } from '@/Komponen/Ui/utils';

type PropsLogo = { nama: string; className?: string; varian?: 'warna' | 'putih' };

/**
 * Logo horizontal Payoung beserta tagline (sumber: `Spesifikasi/Merek`). Dipakai di layar masuk/daftar.
 * Teks alternatif = nama aplikasi, sehingga pembaca layar tetap membaca nama sistem. `object-contain` + `shrink-0`
 * menjaga perbandingan 701:168: bila wadah menyempit, logo mengecil rata (tidak ditekan jadi gepeng).
 */
export function LogoMerek({ nama, className, varian = 'warna' }: PropsLogo) {
    return (
        <img
            src={varian === 'putih' ? gambarLogoPutih : gambarLogo}
            alt={nama}
            className={cn('h-14 w-auto shrink-0 object-contain object-left', className)}
        />
    );
}

/** Tanda merek (payung) tanpa teks, untuk ruang sempit seperti kepala menu samping. */
export function IkonMerek({ nama, className, varian = 'warna' }: PropsLogo) {
    return (
        <img
            src={varian === 'putih' ? gambarIkonPutih : gambarIkon}
            alt={nama}
            className={cn('size-8 shrink-0 object-contain', className)}
        />
    );
}
