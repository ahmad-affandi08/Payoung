import { CheckIcon } from 'lucide-react';

import PanggungSpesimen from '@/Komponen/Situs/PanggungSpesimen';
import { SPESIMEN } from '@/Komponen/Situs/SpesimenSitus';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import { cn } from '@/Komponen/Ui/utils';
import type { BagianSitus } from '@/Tipe/Situs';

import { GambarBagian } from './KepalaBagian';

type Props = { bagian: Extract<BagianSitus, { Jenis: 'Hero' }>; utama: boolean };

const KELAS_LATAR = {
    // D-93: hero berlatar teal gelap seperti beranda; `Terang` dipertahankan sebagai nama pilihan di konsol saja.
    Terang: 'bg-brand-gelap',
    Merek: 'bg-brand-gelap',
    Navy: 'bg-teks-utama',
} as const;

/**
 * Pembuka halaman (D-25, D-39): judul besar **rata kiri**, pengantar, satu tombol utama berisi + satu tombol
 * bergaris tipis, baris centang alasan untuk percaya, dan visual produk asli di kanan. Latar bawaan terang
 * (D-39 "bersih & meyakinkan"); merek/Navy tetap tersedia untuk halaman lain.
 *
 * Label di latar terang memakai `BrandLembut` + teks `Brand` (bukan kuning lagi, D-39): satu warna merek saja
 * di atas lipatan. Di latar gelap label tetap `Aksen` berteks `TeksUtama` (6,4:1).
 */
export default function BagianHero({ bagian, utama }: Props) {
    const Judul = utama ? 'h1' : 'h2';
    const latar = bagian.Latar ?? 'Terang';
    const gelap = true;
    // Spesimen keluaran produk dipakai sebagai jangkar visual selama belum ada gambar (D-25).
    const Spesimen = !bagian.Gambar && bagian.Spesimen ? SPESIMEN[bagian.Spesimen] : null;
    const adaGambar = Boolean(bagian.Gambar) || Spesimen !== null;

    return (
        <section className={KELAS_LATAR[latar]}>
            <div
                className={cn(
                    'muncul-saat-gulir mx-auto grid max-w-6xl items-center gap-12 px-4 py-14 sm:py-20 lg:gap-10',
                    adaGambar && 'lg:grid-cols-[1fr_1.15fr]',
                )}
            >
                <div className={cn('flex min-w-0 flex-col items-start gap-6', adaGambar ? '' : 'max-w-3xl')}>
                    {bagian.Label ? (
                        <p
                            className={cn(
                                'rounded-full px-3 py-1 text-label font-semibold',
                                gelap ? 'bg-aksen text-teks-utama' : 'bg-aksen text-teks-utama',
                            )}
                        >
                            {bagian.Label}
                        </p>
                    ) : null}
                    <Judul
                        className={cn(
                            'text-sorotan-besar-hp font-bold sm:text-sorotan-besar',
                            gelap ? 'text-permukaan' : 'text-teks-utama',
                        )}
                    >
                        {bagian.Judul}
                    </Judul>
                    {bagian.Subjudul ? (
                        <p
                            className={cn(
                                'text-pengantar max-w-xl whitespace-pre-line',
                                gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                            )}
                        >
                            {bagian.Subjudul}
                        </p>
                    ) : null}
                    {bagian.TombolUtama || bagian.TombolKedua ? (
                        <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                            {bagian.TombolUtama ? (
                                <TombolSitus
                                    href={bagian.TombolUtama.Tautan}
                                    ukuran="besar"
                                    varian={gelap ? 'aksen' : 'utama'}
                                >
                                    {bagian.TombolUtama.Label}
                                </TombolSitus>
                            ) : null}
                            {bagian.TombolKedua ? (
                                <TombolSitus
                                    href={bagian.TombolKedua.Tautan}
                                    ukuran="besar"
                                    varian={gelap ? 'garis-terang' : 'garis-merek'}
                                >
                                    {bagian.TombolKedua.Label}
                                </TombolSitus>
                            ) : null}
                        </div>
                    ) : null}
                    {(bagian.Poin ?? []).length > 0 ? (
                        <ul className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:gap-x-5">
                            {(bagian.Poin ?? []).map((p) => (
                                <li
                                    key={p.Teks}
                                    className={cn(
                                        'flex items-center gap-2 text-label font-semibold',
                                        gelap ? 'text-brand-gelap-teks' : 'text-teks-utama',
                                    )}
                                >
                                    <CheckIcon
                                        aria-hidden="true"
                                        strokeWidth={2.5}
                                        className={cn('size-4 shrink-0', gelap ? 'text-aksen' : 'text-brand')}
                                    />
                                    {p.Teks}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {bagian.Catatan ? (
                        <p className={cn('text-label', gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder')}>
                            {bagian.Catatan}
                        </p>
                    ) : null}
                </div>
                {bagian.Gambar ? (
                    <GambarBagian gambar={bagian.Gambar} prioritas={utama} className="h-auto w-full rounded-3xl" />
                ) : Spesimen ? (
                    <PanggungSpesimen warna={gelap ? 'teal' : 'aksen'} className="min-w-0 sm:p-10">
                        <Spesimen prioritas={utama} className="min-w-0" />
                    </PanggungSpesimen>
                ) : null}
            </div>
        </section>
    );
}
