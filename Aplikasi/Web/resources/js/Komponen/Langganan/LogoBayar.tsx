import { AmbilKodeLogo, AmbilSingkatan } from '@/Pustaka/LogoBayar';
import type { MetodeBayarSnap } from '@/Tipe/TagihanLangganan';
import { cn } from '@/Komponen/Ui/utils';

/** Semua berkas di `Aset/Bayar` dipetakan lewat nama berkas tanpa ekstensi, huruf kecil (`Bni.svg` -> `bni`; nama berkas PascalCase sesuai konvensi). */
const berkas = import.meta.glob<string>('../../Aset/Bayar/*.{svg,png,webp}', {
    eager: true,
    query: '?url',
    import: 'default',
});

const logoPerKode: Record<string, string> = Object.fromEntries(
    Object.entries(berkas).map(([jalur, url]) => [
        (jalur.split('/').pop() ?? '').replace(/\.\w+$/, '').toLowerCase(),
        url,
    ]),
);

type PropsLogoBayar = {
    metode: MetodeBayarSnap;
    /** Label atau kode channel; dipakai mencari logo bank dan sebagai lencana cadangan. */
    teks: string;
    className?: string;
};

/**
 * Logo cara bayar (QRIS, bank, e-wallet). Teks nama selalu ada di sebelahnya, jadi gambarnya dekoratif. Logo yang
 * berkasnya belum dipasang di `Aset/Bayar` tampil sebagai lencana teks netral, bukan gambar tiruan.
 */
export default function LogoBayar({ metode, teks, className }: PropsLogoBayar) {
    const kode = AmbilKodeLogo(metode, teks);
    const sumber = logoPerKode[kode];

    return (
        <span
            className={cn('flex h-10 w-16 shrink-0 items-center justify-center', className)}
            data-logo={sumber === undefined ? 'cadangan' : kode}
        >
            {sumber !== undefined ? (
                <img src={sumber} alt="" className="size-full object-contain" />
            ) : (
                <span
                    aria-hidden="true"
                    className="flex h-8 min-w-12 items-center justify-center rounded-panel border border-garis bg-permukaan-redup px-2 text-keterangan font-semibold tracking-wide text-teks-sekunder"
                >
                    {AmbilSingkatan(teks)}
                </span>
            )}
        </span>
    );
}
