import { usePage } from '@inertiajs/react';
import { Newspaper } from 'lucide-react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import KartuArtikel from '@/Komponen/Situs/Blog/KartuArtikel';
import TautanSitus from '@/Komponen/Situs/TautanSitus';
import TataLetakSitus from '@/TataLetak/TataLetakSitus';
import type { PropsBlogSitus } from '@/Tipe/Situs';

function BuatAlamat(halaman: number, kategori: string | null): string {
    const parameter = new URLSearchParams();

    if (kategori) {
        parameter.set('kategori', kategori);
    }

    if (halaman > 1) {
        parameter.set('halaman', String(halaman));
    }

    const kueri = parameter.toString();

    return kueri === '' ? '/blog' : `/blog?${kueri}`;
}

/** Daftar artikel blog situs pemasaran (bagian B2): saring kategori & halaman. */
export default function Blog() {
    const { Halaman, Artikel, Kategori, KategoriAktif, HalamanKe, JumlahHalaman } = usePage<PropsBlogSitus>().props;
    const BuatKelasChip = (aktif: boolean) =>
        `inline-flex min-h-11 items-center rounded-full border px-4 text-label font-semibold ${
            aktif
                ? 'border-brand bg-brand text-permukaan'
                : 'border-garis bg-permukaan text-teks-utama hover:bg-permukaan-sorot'
        }`;

    return (
        <TataLetakSitus judul={Halaman.Seo.Judul}>
            <header className="border-b border-garis bg-brand-lembut">
                <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-12 sm:py-16">
                    <p className="text-label font-semibold tracking-wide text-brand uppercase">Blog Payoung</p>
                    <JudulHalaman skala="situs">Blog</JudulHalaman>
                    <p className="max-w-2xl text-pengantar text-teks-sekunder">{Halaman.Seo.Deskripsi}</p>
                    {Kategori.length > 0 ? (
                        <nav aria-label="Kategori artikel" className="flex flex-wrap gap-2 pt-2">
                            <TautanSitus href="/blog" className={BuatKelasChip(KategoriAktif === null)}>
                                Semua
                            </TautanSitus>
                            {Kategori.map((k) => (
                                <TautanSitus
                                    key={k}
                                    href={BuatAlamat(1, k)}
                                    className={BuatKelasChip(KategoriAktif === k)}
                                >
                                    {k}
                                </TautanSitus>
                            ))}
                        </nav>
                    ) : null}
                </div>
            </header>
            <section className="mx-auto max-w-6xl px-4 py-10 sm:py-14">
                {Artikel.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-panel border border-dashed border-garis-input bg-permukaan px-6 py-14 text-center">
                        <span className="flex size-12 items-center justify-center rounded-full bg-brand-lembut text-brand">
                            <Newspaper aria-hidden className="size-6" strokeWidth={1.75} />
                        </span>
                        <h2 className="text-judul font-bold text-teks-utama">
                            {KategoriAktif ? `Belum ada artikel di ${KategoriAktif}` : 'Belum ada artikel'}
                        </h2>
                        <p className="max-w-md text-isi text-teks-sekunder">
                            Artikel pertama segera hadir: tips kasir, stok, dan keuangan untuk pemilik usaha
                            sehari-hari. Sambil menunggu, lihat fitur Payoung.
                        </p>
                        <div className="flex flex-wrap justify-center gap-2 pt-2">
                            <TautanSitus
                                href="/fitur"
                                className="inline-flex min-h-11 items-center rounded-kontrol border border-garis bg-permukaan px-4 text-isi font-semibold text-teks-utama hover:bg-permukaan-sorot"
                            >
                                Lihat fitur
                            </TautanSitus>
                            {KategoriAktif ? (
                                <TautanSitus
                                    href="/blog"
                                    className="inline-flex min-h-11 items-center rounded-kontrol bg-brand px-4 text-isi font-semibold text-permukaan hover:bg-brand-gelap"
                                >
                                    Semua artikel
                                </TautanSitus>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {Artikel.map((a) => (
                            <li key={a.Slug}>
                                <KartuArtikel artikel={a} />
                            </li>
                        ))}
                    </ul>
                )}
                {JumlahHalaman > 1 ? (
                    <nav aria-label="Halaman artikel" className="mt-10 flex items-center justify-between gap-4">
                        {HalamanKe > 1 ? (
                            <TautanSitus
                                href={BuatAlamat(HalamanKe - 1, KategoriAktif)}
                                className={BuatKelasChip(false)}
                            >
                                Sebelumnya
                            </TautanSitus>
                        ) : (
                            <span />
                        )}
                        <span className="text-label text-teks-sekunder">
                            Halaman {HalamanKe} dari {JumlahHalaman}
                        </span>
                        {HalamanKe < JumlahHalaman ? (
                            <TautanSitus
                                href={BuatAlamat(HalamanKe + 1, KategoriAktif)}
                                className={BuatKelasChip(false)}
                            >
                                Berikutnya
                            </TautanSitus>
                        ) : (
                            <span />
                        )}
                    </nav>
                ) : null}
            </section>
        </TataLetakSitus>
    );
}
