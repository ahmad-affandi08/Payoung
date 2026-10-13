import { usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';

import TautanSitus from '@/Komponen/Situs/TautanSitus';
import TeksKaya from '@/Komponen/Situs/TeksKaya';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakSitus from '@/TataLetak/TataLetakSitus';
import type { PropsArtikelBantuan } from '@/Tipe/Situs';

/** Satu artikel Pusat Bantuan (PLT-12) + artikel terkait sekategori dan jalan ke dukungan. */
export default function ArtikelBantuan() {
    const { Halaman, Artikel: artikel, Terkait } = usePage<PropsArtikelBantuan>().props;

    return (
        <TataLetakSitus judul={Halaman.Seo.Judul}>
            <article className="mx-auto max-w-3xl px-4 py-12 sm:py-16">
                <nav aria-label="Jejak" className="text-label text-teks-sekunder">
                    <TautanSitus href="/pusat-bantuan" className="text-brand underline">
                        Pusat Bantuan
                    </TautanSitus>
                    {artikel.Kategori ? (
                        <>
                            {' / '}
                            <TautanSitus
                                href={`/pusat-bantuan?kategori=${encodeURIComponent(artikel.Kategori)}`}
                                className="text-brand underline"
                            >
                                {artikel.Kategori}
                            </TautanSitus>
                        </>
                    ) : null}
                </nav>
                <JudulHalaman skala="situs" className="mt-4">
                    {artikel.Judul}
                </JudulHalaman>
                {artikel.DiperbaruiPada ? (
                    <p className="mt-2 text-label text-teks-sekunder">
                        Diperbarui {FormatTanggal(artikel.DiperbaruiPada)}
                    </p>
                ) : null}
                {artikel.Ringkasan ? (
                    <p className="mt-6 text-pengantar font-semibold text-teks-utama">{artikel.Ringkasan}</p>
                ) : null}
                {artikel.UrlVideo ? (
                    <p className="mt-4">
                        <a
                            href={artikel.UrlVideo}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex min-h-11 items-center gap-2 rounded-kontrol border border-garis bg-permukaan px-4 text-isi font-semibold text-teks-utama hover:bg-permukaan-sorot"
                        >
                            Tonton video penjelasan <ExternalLink aria-hidden className="size-4" />
                        </a>
                    </p>
                ) : null}
                <TeksKaya teks={artikel.Isi} className="mt-6 text-subjudul text-teks-utama" />
                <p className="mt-10 rounded-panel border border-garis bg-permukaan p-5 text-isi text-teks-sekunder">
                    Belum terjawab? Buka menu Bantuan di dalam aplikasi untuk menghubungi tim dukungan.
                </p>
            </article>
            {Terkait.length > 0 ? (
                <section aria-labelledby="judul-terkait" className="bg-permukaan py-12">
                    <div className="mx-auto max-w-3xl px-4">
                        <h2 id="judul-terkait" className="text-subjudul font-semibold text-teks-utama">
                            Artikel terkait
                        </h2>
                        <ul className="mt-4 flex flex-col gap-3">
                            {Terkait.map((a) => (
                                <li key={a.Slug}>
                                    <TautanSitus
                                        href={`/pusat-bantuan/${a.Slug}`}
                                        className="font-semibold text-brand underline"
                                    >
                                        {a.Judul}
                                    </TautanSitus>
                                    {a.Ringkasan ? (
                                        <span className="block text-isi text-teks-sekunder">{a.Ringkasan}</span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            ) : null}
        </TataLetakSitus>
    );
}
