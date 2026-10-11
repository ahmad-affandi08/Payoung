import { CheckIcon } from 'lucide-react';
import { useId, useRef, useState, type KeyboardEvent } from 'react';

import IkonSitus from '@/Komponen/Situs/IkonSitus';
import PanggungSpesimen from '@/Komponen/Situs/PanggungSpesimen';
import { SPESIMEN } from '@/Komponen/Situs/SpesimenSitus';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import { cn } from '@/Komponen/Ui/utils';
import type { BagianSitus } from '@/Tipe/Situs';

import { GambarBagian, KepalaBagian, type LatarBagian, WadahBagian } from './KepalaBagian';

type Props = {
    bagian: Extract<BagianSitus, { Jenis: 'TabUsaha' }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
};

/**
 * Tab per jenis usaha (kafe & resto, retail, salon, laundry, apotek, bengkel): pilih usaha Anda, lihat fitur yang
 * dipakai usaha itu beserta tangkapan layar asli aplikasi. Pola tab ARIA (`tablist`, panah kiri/kanan, Home/End);
 * semua panel tetap ada di HTML (`hidden`) sehingga teksnya terbaca mesin pencari.
 */
export function BagianTabUsaha({ bagian, latar, garisAtas }: Props) {
    const idDasar = useId();
    const [aktif, AturAktif] = useState(0);
    const tombol = useRef<(HTMLButtonElement | null)[]>([]);
    const jumlah = bagian.Item.length;

    const Pindah = (indeks: number) => {
        const tujuan = ((indeks % jumlah) + jumlah) % jumlah;
        AturAktif(tujuan);
        tombol.current[tujuan]?.focus();
    };

    const SaatTekan = (p: KeyboardEvent<HTMLDivElement>) => {
        if (p.key === 'ArrowRight') {
            p.preventDefault();
            Pindah(aktif + 1);
        } else if (p.key === 'ArrowLeft') {
            p.preventDefault();
            Pindah(aktif - 1);
        } else if (p.key === 'Home') {
            p.preventDefault();
            Pindah(0);
        } else if (p.key === 'End') {
            p.preventDefault();
            Pindah(jumlah - 1);
        }
    };

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas} id="jenis-usaha">
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} />
            <div
                role="tablist"
                aria-label="Jenis usaha"
                onKeyDown={SaatTekan}
                className="mb-10 flex flex-wrap justify-center gap-2"
            >
                {bagian.Item.map((item, i) => (
                    <button
                        key={`${item.Label}-${i}`}
                        ref={(el) => {
                            tombol.current[i] = el;
                        }}
                        type="button"
                        role="tab"
                        id={`${idDasar}-tab-${i}`}
                        aria-selected={i === aktif}
                        aria-controls={`${idDasar}-panel-${i}`}
                        tabIndex={i === aktif ? 0 : -1}
                        onClick={() => AturAktif(i)}
                        className={cn(
                            'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-isi font-semibold transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                            i === aktif
                                ? 'border-aksen bg-aksen text-teks-utama'
                                : 'border-garis-input bg-permukaan text-teks-utama hover:bg-permukaan-sorot',
                        )}
                    >
                        {item.Ikon ? <IkonSitus nama={item.Ikon} className="size-4" /> : null}
                        {item.Label}
                    </button>
                ))}
            </div>
            {bagian.Item.map((item, i) => {
                const Spesimen = !item.Gambar && item.Spesimen ? SPESIMEN[item.Spesimen] : null;

                return (
                    <div
                        key={`${item.Label}-panel-${i}`}
                        role="tabpanel"
                        id={`${idDasar}-panel-${i}`}
                        aria-labelledby={`${idDasar}-tab-${i}`}
                        hidden={i !== aktif}
                        className="grid items-center gap-8 lg:grid-cols-[1fr_1.1fr] lg:gap-12"
                    >
                        <div className="flex min-w-0 flex-col items-start gap-4">
                            <h3 className="text-judul font-bold text-teks-utama sm:text-judul-bagian">{item.Judul}</h3>
                            {item.Teks ? (
                                <p className="text-pengantar whitespace-pre-line text-teks-sekunder">{item.Teks}</p>
                            ) : null}
                            {item.Poin.length > 0 ? (
                                <ul className="flex flex-col gap-2">
                                    {item.Poin.map((p) => (
                                        <li
                                            key={p.Teks}
                                            className="flex items-start gap-2 text-subjudul text-teks-utama"
                                        >
                                            <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-sukses-lembut text-sukses">
                                                <CheckIcon aria-hidden strokeWidth={3} className="size-4" />
                                            </span>
                                            {p.Teks}
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                            {item.Tombol ? (
                                <TombolSitus href={item.Tombol.Tautan} varian="utama">
                                    {item.Tombol.Label}
                                </TombolSitus>
                            ) : null}
                        </div>
                        {item.Gambar || Spesimen ? (
                            <PanggungSpesimen warna={latar === 'aksen' ? 'teal' : 'aksen'} tetap className="p-3 sm:p-6">
                                {item.Gambar ? (
                                    <GambarBagian
                                        gambar={item.Gambar}
                                        className="max-h-full w-auto max-w-full rounded-kontrol object-contain"
                                    />
                                ) : Spesimen ? (
                                    <Spesimen penuhTinggi className="max-w-none" />
                                ) : null}
                            </PanggungSpesimen>
                        ) : null}
                    </div>
                );
            })}
        </WadahBagian>
    );
}
