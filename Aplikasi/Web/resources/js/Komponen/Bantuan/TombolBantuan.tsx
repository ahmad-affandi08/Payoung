import { Link, usePage } from '@inertiajs/react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { CircleHelpIcon, ExternalLink, PlayCircle } from 'lucide-react';
import { useState } from 'react';

import PanelObrolan from '@/Komponen/Bantuan/PanelObrolan';
import { AmbilHasilCari, useNilaiTertunda } from '@/Komponen/Katalog/PemilihProduk';
import Tombol from '@/Komponen/Formulir/Tombol';
import { useTur } from '@/Komponen/Tur/PenyediaTur';
import { Button } from '@/Komponen/Ui/button';
import { Input } from '@/Komponen/Ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/Komponen/Ui/sheet';
import { TurDashboard } from '@/Pustaka/DefinisiTur';
import { KunciKueri } from '@/Pustaka/KunciKueri';

export type ArtikelBantuanKontekstual = {
    Slug: string;
    Judul: string;
    Ringkasan: string | null;
    Kategori: string | null;
    UrlVideo: string | null;
    Url: string;
};

export type HasilBantuanKontekstual = {
    Halaman: ArtikelBantuanKontekstual[];
    Lainnya: ArtikelBantuanKontekstual[];
    UrlPusatBantuan: string;
};

/** Jalur halaman tanpa query/fragmen (yang dipakai server mencocokkan konteks artikel). */
export function AmbilJalurHalaman(url: string): string {
    return url.split(/[?#]/)[0] ?? '/kelola';
}

function DaftarArtikel({ judul, artikel }: { judul: string; artikel: ArtikelBantuanKontekstual[] }) {
    if (artikel.length === 0) {
        return null;
    }

    return (
        <section aria-label={judul} className="flex flex-col gap-2">
            <h3 className="text-label font-semibold text-teks-sekunder">{judul}</h3>
            <ul className="flex flex-col divide-y divide-garis rounded-panel border border-garis bg-permukaan">
                {artikel.map((a) => (
                    <li key={a.Slug} className="flex flex-col gap-1 p-3">
                        <a
                            href={a.Url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-start gap-1.5 text-isi font-semibold text-brand underline"
                        >
                            <span className="break-words">{a.Judul}</span>
                            <ExternalLink aria-hidden className="mt-1 size-3.5 shrink-0" />
                            <span className="sr-only">(buka di tab baru)</span>
                        </a>
                        {a.Ringkasan ? <p className="text-keterangan text-teks-sekunder">{a.Ringkasan}</p> : null}
                        {a.UrlVideo ? (
                            <a
                                href={a.UrlVideo}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 text-keterangan font-semibold text-brand underline"
                            >
                                <PlayCircle aria-hidden className="size-3.5" /> Tonton video
                            </a>
                        ) : null}
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Isi lembar Bantuan. Komponen ini baru dipasang saat lembar dibuka (Radix tidak merender isi `SheetContent` yang
 * tertutup), jadi kueri artikel hanya berjalan sesudah pengguna menekan tombol Bantuan.
 */
function IsiBantuan({
    bolehHubungiDukungan,
    saatMulaiTur,
}: {
    bolehHubungiDukungan: boolean;
    saatMulaiTur: () => void;
}) {
    const { url } = usePage();
    const [kata, AturKata] = useState('');
    const jalur = AmbilJalurHalaman(url);
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const kueri = useQuery({
        queryKey: KunciKueri.BantuanKontekstual(jalur, kataCari),
        queryFn: ({ signal }) =>
            AmbilHasilCari<HasilBantuanKontekstual>(
                `/kelola/pusat-bantuan?jalur=${encodeURIComponent(jalur)}&cari=${encodeURIComponent(kataCari)}`,
                signal,
            ),
        staleTime: 60_000,
        placeholderData: keepPreviousData,
    });
    const data = kueri.data;
    const kosong = data !== undefined && data.Halaman.length === 0 && data.Lainnya.length === 0;

    return (
        <div className="flex flex-col gap-4 px-4 pb-4">
            <div className="flex flex-col gap-1">
                <label htmlFor="cari-bantuan-kontekstual" className="text-label font-semibold text-teks-utama">
                    Cari bantuan
                </label>
                <Input
                    id="cari-bantuan-kontekstual"
                    type="search"
                    value={kata}
                    onChange={(e) => AturKata(e.target.value)}
                    maxLength={80}
                    placeholder="Misalnya: buka shift, tambah produk"
                />
            </div>
            {kueri.isPending ? <p className="text-isi text-teks-sekunder">Memuat bantuan...</p> : null}
            {kueri.isError ? (
                <p role="alert" className="text-isi text-bahaya">
                    Bantuan belum bisa dimuat. Periksa koneksi lalu buka lagi.
                </p>
            ) : null}
            {data !== undefined ? (
                <>
                    <DaftarArtikel judul="Untuk halaman ini" artikel={data.Halaman} />
                    <DaftarArtikel
                        judul={kataCari === '' ? 'Artikel lain' : 'Hasil pencarian'}
                        artikel={data.Lainnya}
                    />
                    {kosong ? (
                        <p className="text-isi text-teks-sekunder">
                            {kataCari === ''
                                ? 'Belum ada artikel bantuan. Hubungi tim dukungan bila butuh bantuan.'
                                : 'Tidak ada artikel yang cocok. Coba kata lain atau hubungi tim dukungan.'}
                        </p>
                    ) : null}
                    <section
                        aria-label="Tur singkat"
                        className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan p-3"
                    >
                        <h3 className="text-label font-semibold text-teks-sekunder">Tur singkat</h3>
                        <p className="text-keterangan text-teks-sekunder">
                            Lihat lagi bagian-bagian utama dashboard, mulai dari menu sampai Kotak Tindakan.
                        </p>
                        <div>
                            <Tombol varian="sekunder" onClick={saatMulaiTur}>
                                Mulai tur keliling dashboard
                            </Tombol>
                        </div>
                    </section>
                    <div className="flex flex-col gap-2 border-t border-garis pt-4">
                        <a
                            href={data.UrlPusatBantuan}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-isi font-semibold text-brand underline"
                        >
                            Buka Pusat Bantuan lengkap
                        </a>
                        {bolehHubungiDukungan ? (
                            <Link href="/kelola/bantuan/buat" className="text-isi font-semibold text-brand underline">
                                Hubungi tim dukungan
                            </Link>
                        ) : null}
                    </div>
                </>
            ) : null}
            {bolehHubungiDukungan ? <PanelObrolan halamanAsal={jalur} /> : null}
        </div>
    );
}

/**
 * Tombol Bantuan di kepala back-office (PLT-12): membuka lembar berisi artikel Pusat Bantuan yang relevan dengan halaman
 * yang sedang dibuka, kotak cari untuk artikel lain, tautan ke Pusat Bantuan, dan jalan ke tiket dukungan.
 */
export default function TombolBantuan({ bolehHubungiDukungan }: { bolehHubungiDukungan: boolean }) {
    const [terbuka, AturTerbuka] = useState(false);
    const { Mulai } = useTur();
    // Lembar ditutup dulu supaya sorotan tur tidak tertutup; jeda singkat menunggu animasi tutup selesai.
    const MulaiTur = () => {
        AturTerbuka(false);
        window.setTimeout(() => Mulai(TurDashboard), 300);
    };

    return (
        <Sheet open={terbuka} onOpenChange={AturTerbuka}>
            <SheetTrigger asChild>
                <Button
                    variant="outline"
                    aria-label="Bantuan"
                    data-tur="bantuan"
                    className="relative size-10 min-w-0 rounded-lg border-garis bg-permukaan px-0 text-teks-sekunder after:absolute after:-inset-0.5 has-[>svg]:px-0 pointer-coarse:size-10 lg:mr-1 lg:size-8 lg:after:hidden lg:pointer-coarse:size-11"
                >
                    <CircleHelpIcon aria-hidden />
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle className="text-subjudul text-teks-utama">Bantuan</SheetTitle>
                    <SheetDescription>Panduan untuk halaman ini, atau cari topik lain.</SheetDescription>
                </SheetHeader>
                <IsiBantuan bolehHubungiDukungan={bolehHubungiDukungan} saatMulaiTur={MulaiTur} />
            </SheetContent>
        </Sheet>
    );
}
