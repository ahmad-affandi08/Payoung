import { CheckIcon, LockIcon } from 'lucide-react';

import { Progress } from '@/Komponen/Ui/progress';
import { cn } from '@/Komponen/Ui/utils';

import type { KunciLangkah, LangkahProduk } from './LangkahProduk';

type PropsPenunjukLangkah = {
    langkah: LangkahProduk[];
    aktif: KunciLangkah;
    /** Langkah yang sudah dicapai atau sedang aktif boleh diklik; sisanya terkunci. */
    cekTerbuka: (kunci: KunciLangkah) => boolean;
    /** Langkah yang memuat isian bermasalah; ditandai dengan teks, bukan warna saja. */
    bergalat: KunciLangkah[];
    saatPilih: (kunci: KunciLangkah) => void;
};

/**
 * Penunjuk langkah wizard produk. Di HP ringkas ("Langkah 2 dari 5" + bilah kemajuan); dari 640px daftar semua
 * langkah. Langkah di depan yang belum dicapai terkunci: `disabled` + `aria-disabled`, ikon gembok, dan teks
 * tersembunyi "terkunci" untuk pembaca layar.
 */
export default function PenunjukLangkah({ langkah, aktif, cekTerbuka, bergalat, saatPilih }: PropsPenunjukLangkah) {
    const indeksAktif = Math.max(
        0,
        langkah.findIndex((item) => item.Kunci === aktif),
    );

    return (
        <nav aria-label="Langkah tambah produk" className="flex flex-col gap-2">
            <p className="text-label font-semibold text-teks-sekunder">
                Langkah {String(indeksAktif + 1)} dari {String(langkah.length)}
            </p>
            {/* Visual saja; nomor langkah sudah tertulis di atas. */}
            <Progress
                value={Math.round(((indeksAktif + 1) * 100) / Math.max(1, langkah.length))}
                aria-hidden="true"
                className="h-1.5 bg-permukaan-sorot sm:hidden"
            />
            <ol className="hidden gap-2 sm:grid sm:grid-flow-col sm:auto-cols-fr">
                {langkah.map((item, indeks) => {
                    const sedangAktif = item.Kunci === aktif;
                    const terbuka = cekTerbuka(item.Kunci);
                    const adaGalat = bergalat.includes(item.Kunci);
                    const selesai = !sedangAktif && terbuka && indeks < indeksAktif && !adaGalat;

                    return (
                        <li key={item.Kunci} className="min-w-0">
                            <button
                                type="button"
                                disabled={!terbuka}
                                aria-disabled={!terbuka}
                                aria-current={sedangAktif ? 'step' : undefined}
                                onClick={() => saatPilih(item.Kunci)}
                                className={cn(
                                    'flex h-full w-full flex-col items-start gap-1 rounded-kontrol border px-3 py-2 text-left outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 pointer-coarse:min-h-11',
                                    sedangAktif
                                        ? 'border-b-4 border-brand bg-card'
                                        : terbuka
                                          ? 'border-garis bg-card hover:bg-accent'
                                          : 'cursor-not-allowed border-dashed border-garis bg-permukaan-redup text-teks-sekunder',
                                    adaGalat && 'border-bahaya',
                                )}
                            >
                                <span className="flex items-center gap-2">
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'flex size-6 shrink-0 items-center justify-center rounded-full border text-keterangan tabular-nums',
                                            selesai && 'border-brand bg-brand text-brand-teks',
                                            sedangAktif && 'border-brand text-brand',
                                            !terbuka && 'border-garis-input',
                                        )}
                                    >
                                        {selesai ? (
                                            <CheckIcon className="size-3.5" />
                                        ) : !terbuka ? (
                                            <LockIcon className="size-3.5" />
                                        ) : (
                                            String(indeks + 1)
                                        )}
                                    </span>
                                    <span className="text-label font-semibold break-words text-teks-utama">
                                        {item.Label}
                                    </span>
                                </span>
                                {adaGalat ? (
                                    <span className="text-keterangan font-semibold text-bahaya">Perlu diperbaiki</span>
                                ) : null}
                                {!terbuka ? (
                                    <span className="sr-only">(terkunci, selesaikan langkah sebelumnya)</span>
                                ) : null}
                                {selesai ? <span className="sr-only">(selesai)</span> : null}
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
