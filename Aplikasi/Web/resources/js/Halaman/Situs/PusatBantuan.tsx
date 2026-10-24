import { usePage } from '@inertiajs/react';
import { CircleHelp, Search } from 'lucide-react';

import Tombol from '@/Komponen/Formulir/Tombol';
import TautanSitus from '@/Komponen/Situs/TautanSitus';
import { Input } from '@/Komponen/Ui/input';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import TataLetakSitus from '@/TataLetak/TataLetakSitus';
import type { PropsPusatBantuan } from '@/Tipe/Situs';

const KelasChip = (aktif: boolean) =>
    `inline-flex min-h-11 items-center rounded-full border px-4 text-label font-semibold ${
        aktif
            ? 'border-brand bg-brand text-permukaan'
            : 'border-garis bg-permukaan text-teks-utama hover:bg-permukaan-sorot'
    }`;

/**
 * Pusat Bantuan publik (PLT-12): artikel terbit per kategori dengan cari. Pencarian memakai formulir GET biasa supaya
 * hasilnya punya alamat sendiri yang bisa dibagikan.
 */
export default function PusatBantuan() {
    const { Halaman, Kelompok, Kategori, KategoriAktif, Cari, Total } = usePage<PropsPusatBantuan>().props;
    const adaSaringan = Cari !== '' || KategoriAktif !== null;

    return (
        <TataLetakSitus judul={Halaman.Seo.Judul}>
            <header className="border-b border-garis bg-brand-lembut">
                <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-12 sm:py-16">
                    <p className="text-label font-semibold tracking-wide text-brand uppercase">Pusat Bantuan</p>
                    <JudulHalaman skala="situs">Ada yang bisa kami bantu?</JudulHalaman>
                    <p className="max-w-2xl text-pengantar text-teks-sekunder">{Halaman.Seo.Deskripsi}</p>
                    <form action="/pusat-bantuan" method="get" role="search" className="flex max-w-xl gap-2 pt-2">
                        {KategoriAktif ? <input type="hidden" name="kategori" value={KategoriAktif} /> : null}
                        <label htmlFor="cari-bantuan" className="sr-only">
                            Cari artikel bantuan
                        </label>
                        <Input
                            id="cari-bantuan"
                            name="cari"
                            type="search"
                            defaultValue={Cari}
                            maxLength={80}
                            placeholder="Cari, misalnya: tambah produk, buka shift"
                            className="h-11 flex-1"
                        />
                        <Tombol type="submit">
                            <Search aria-hidden className="size-4" /> Cari
                        </Tombol>
                    </form>
                    {Kategori.length > 0 ? (
                        <nav aria-label="Kategori bantuan" className="flex flex-wrap gap-2 pt-2">
                            <TautanSitus
                                href={
                                    Cari !== '' ? `/pusat-bantuan?cari=${encodeURIComponent(Cari)}` : '/pusat-bantuan'
                                }
                                className={KelasChip(KategoriAktif === null)}
                            >
                                Semua
                            </TautanSitus>
                            {Kategori.map((k) => (
                                <TautanSitus
                                    key={k}
                                    href={`/pusat-bantuan?kategori=${encodeURIComponent(k)}${Cari !== '' ? `&cari=${encodeURIComponent(Cari)}` : ''}`}
                                    className={KelasChip(KategoriAktif === k)}
                                >
                                    {k}
                                </TautanSitus>
                            ))}
                        </nav>
                    ) : null}
                </div>
            </header>
            <section className="mx-auto max-w-6xl px-4 py-10 sm:py-14">
                {adaSaringan ? (
                    <p className="mb-6 text-isi text-teks-sekunder" role="status">
                        {Total === 0
                            ? 'Tidak ada artikel yang cocok.'
                            : `${String(Total)} artikel ditemukan${Cari !== '' ? ` untuk "${Cari}"` : ''}.`}{' '}
                        <TautanSitus href="/pusat-bantuan" className="text-brand underline">
                            Hapus filter
                        </TautanSitus>
                    </p>
                ) : null}
                {Kelompok.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-panel border border-dashed border-garis-input bg-permukaan px-6 py-14 text-center">
                        <span className="flex size-12 items-center justify-center rounded-full bg-brand-lembut text-brand">
                            <CircleHelp aria-hidden className="size-6" strokeWidth={1.75} />
                        </span>
                        <h2 className="text-judul font-bold text-teks-utama">
                            {adaSaringan ? 'Artikel tidak ditemukan' : 'Artikel bantuan segera hadir'}
                        </h2>
                        <p className="max-w-md text-isi text-teks-sekunder">
                            {adaSaringan
                                ? 'Coba kata lain, atau hubungi tim dukungan dari menu Bantuan di dalam aplikasi.'
                                : 'Panduan langkah demi langkah sedang disiapkan. Sementara itu, hubungi tim dukungan dari menu Bantuan di dalam aplikasi.'}
                        </p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-10">
                        {Kelompok.map((k) => (
                            <section key={k.Kategori} aria-labelledby={`kategori-${k.Kategori}`}>
                                <h2 id={`kategori-${k.Kategori}`} className="text-judul font-bold text-teks-utama">
                                    {k.Kategori}
                                </h2>
                                <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {k.Artikel.map((a) => (
                                        <li key={a.Slug}>
                                            <article className="group relative flex h-full flex-col gap-2 rounded-panel border border-garis bg-permukaan p-5 transition-colors focus-within:border-brand hover:border-brand motion-reduce:transition-none">
                                                <h3 className="text-subjudul font-semibold text-teks-utama">
                                                    <TautanSitus
                                                        href={`/pusat-bantuan/${a.Slug}`}
                                                        className="after:absolute after:inset-0 group-hover:text-brand focus-visible:outline-none"
                                                    >
                                                        {a.Judul}
                                                    </TautanSitus>
                                                </h3>
                                                {a.Ringkasan ? (
                                                    <p className="text-isi text-teks-sekunder">{a.Ringkasan}</p>
                                                ) : null}
                                                {a.UrlVideo ? (
                                                    <p className="text-keterangan text-teks-sekunder">
                                                        Ada video penjelasan
                                                    </p>
                                                ) : null}
                                            </article>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </section>
        </TataLetakSitus>
    );
}
