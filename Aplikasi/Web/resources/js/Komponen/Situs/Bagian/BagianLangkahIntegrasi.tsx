import ChipIkon from '@/Komponen/Situs/ChipIkon';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import { cn } from '@/Komponen/Ui/utils';
import type { BagianSitus } from '@/Tipe/Situs';

import { CekGelap, KelasKartu, KepalaBagian, type LatarBagian, WadahBagian } from './KepalaBagian';

type PropsBagian<J extends BagianSitus['Jenis']> = {
    bagian: Extract<BagianSitus, { Jenis: J }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
};

/**
 * Langkah bernomor ("mulai dalam 5 menit"): daftar berurutan `<ol>` dengan nomor besar berwarna merek. Nomornya
 * bagian dari urutan daftar, bukan dekorasi, jadi pembaca layar membacanya sebagai langkah 1, 2, 3.
 */
export function BagianLangkah({ bagian, latar, garisAtas }: PropsBagian<'Langkah'>) {
    const gelap = CekGelap(latar);
    const kolom =
        bagian.Item.length >= 4 ? 'lg:grid-cols-4' : bagian.Item.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2';

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas} id="mulai">
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            <ol className={cn('grid gap-4 sm:grid-cols-2', kolom)}>
                {bagian.Item.map((item, i) => (
                    <li key={`${item.Judul}-${i}`} className={cn('flex flex-col gap-3', KelasKartu(latar))}>
                        <div className="flex items-center gap-3">
                            <span
                                aria-hidden
                                className="inline-flex size-12 shrink-0 items-center justify-center rounded-full bg-aksen text-judul font-bold text-teks-utama"
                            >
                                {i + 1}
                            </span>
                            <ChipIkon nama={item.Ikon} indeks={i + 1} gelap={gelap} />
                        </div>
                        <h3 className={cn('text-subjudul font-semibold', gelap ? 'text-permukaan' : 'text-teks-utama')}>
                            <span className="sr-only">Langkah {i + 1}: </span>
                            {item.Judul}
                        </h3>
                        {item.Teks ? (
                            <p
                                className={cn(
                                    'text-isi whitespace-pre-line',
                                    gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                                )}
                            >
                                {item.Teks}
                            </p>
                        ) : null}
                    </li>
                ))}
            </ol>
            {bagian.Tombol ? (
                <div className="mt-8">
                    <TombolSitus href={bagian.Tombol.Tautan} ukuran="besar" varian="aksen">
                        {bagian.Tombol.Label}
                    </TombolSitus>
                </div>
            ) : null}
        </WadahBagian>
    );
}

/**
 * Integrasi & perangkat yang didukung, dikelompokkan (pembayaran, pesan, printer, pesan-antar). Nama pihak ketiga
 * hanya ditulis sebagai teks (tanpa logo) dan hanya untuk yang benar-benar terintegrasi di produk.
 */
export function BagianIntegrasi({ bagian, latar, garisAtas }: PropsBagian<'Integrasi'>) {
    const gelap = CekGelap(latar);

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas} id="integrasi">
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            <ul className="grid gap-4 sm:grid-cols-2">
                {bagian.Kelompok.map((k, i) => (
                    <li key={`${k.Judul}-${i}`} className={cn('flex flex-col gap-3', KelasKartu(latar))}>
                        <div className="flex items-center gap-3">
                            <ChipIkon nama={k.Ikon} indeks={i} gelap={gelap} />
                            <h3
                                className={cn(
                                    'text-subjudul font-semibold',
                                    gelap ? 'text-permukaan' : 'text-teks-utama',
                                )}
                            >
                                {k.Judul}
                            </h3>
                        </div>
                        {k.Teks ? (
                            <p
                                className={cn(
                                    'text-isi whitespace-pre-line',
                                    gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                                )}
                            >
                                {k.Teks}
                            </p>
                        ) : null}
                        <ul className="mt-auto flex flex-wrap gap-2">
                            {k.Item.map((n) => (
                                <li
                                    key={n.Nama}
                                    className={cn(
                                        'rounded-full px-3 py-1 text-label font-semibold',
                                        gelap
                                            ? 'bg-brand-gelap-sorot text-permukaan'
                                            : 'bg-brand-lembut text-brand-gelap',
                                    )}
                                >
                                    {n.Nama}
                                </li>
                            ))}
                        </ul>
                    </li>
                ))}
            </ul>
            {bagian.Catatan ? (
                <p className={cn('mt-6 text-label', gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder')}>
                    {bagian.Catatan}
                </p>
            ) : null}
        </WadahBagian>
    );
}
