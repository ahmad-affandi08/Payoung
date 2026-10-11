import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import PanelIklanAuth from '@/Komponen/Autentikasi/PanelIklanAuth';
import { LogoMerek } from '@/Komponen/Merek/LogoMerek';
import { Card, CardContent } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { FormatRupiah } from '@/Pustaka/Format';
import type { PenawaranAuth, PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

import { PemberitahuanMelayang } from './BagianTataLetak';

type PropsTataLetak = { judul: string; keterangan?: string; children: ReactNode; lebar?: 'sempit' | 'sedang' };

/*
 * Kelas ditulis utuh (bukan dirangkai) supaya terbaca pemindai Tailwind. Halaman `sedang` (formulir dua kolom) baru
 * menampilkan panel iklan di layar xl: pada lg kolom formulirnya belum cukup lebar untuk dua kolom isian.
 */
const KELAS_KISI = {
    sempit: 'lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]',
    sedang: 'xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]',
} as const;
const KELAS_PANEL = { sempit: 'hidden lg:block', sedang: 'hidden xl:block' } as const;
const KELAS_HANYA_KECIL = { sempit: 'lg:hidden', sedang: 'xl:hidden' } as const;

/**
 * Tata letak layar daftar, masuk, dan pemilih tenant (F-00): dua kolom modern dengan panel iklan merek di kiri
 * (seperti halaman masuk kasir, D-60) dan formulir di kanan. Di layar kecil panel iklan diganti pita penawaran
 * ringkas di atas formulir.
 */
export default function TataLetakAutentikasi({ judul, keterangan, children, lebar = 'sempit' }: PropsTataLetak) {
    const { props } = usePage<PropsBersamaAplikasi & { Penawaran?: PenawaranAuth | null }>();
    const penawaran = props.Penawaran ?? null;
    const lisensi = props.Edisi === 'Lisensi';
    const promo = penawaran?.Promo ?? null;

    return (
        <>
            <Head title={judul} />
            <div className={`grid min-h-screen bg-latar ${KELAS_KISI[lebar]}`}>
                <aside className={KELAS_PANEL[lebar]} aria-label="Keunggulan Payoung">
                    <div className="sticky top-0">
                        <PanelIklanAuth
                            nama={props.NamaAplikasi}
                            penawaran={lisensi ? null : penawaran}
                            lisensi={lisensi}
                        />
                    </div>
                </aside>
                <main
                    className={`mx-auto flex min-h-screen w-full flex-col justify-center gap-6 px-4 py-10 sm:px-8 ${
                        lebar === 'sempit' ? 'max-w-lg' : 'max-w-2xl'
                    }`}
                >
                    <header className="flex flex-col gap-3">
                        {/* D-20: situs pemasaran bisa di domain lain, jadi tautan biasa (bukan kunjungan Inertia). */}
                        <a href={props.UrlPemasaran ?? '/'} className="mb-2 self-start">
                            <LogoMerek nama={props.NamaAplikasi} className={`h-14 ${KELAS_HANYA_KECIL[lebar]}`} />
                        </a>
                        {!lisensi && penawaran && promo ? (
                            <p
                                className={`text-label w-fit rounded-full bg-aksen px-3 py-1 font-semibold text-teks-utama ${KELAS_HANYA_KECIL[lebar]}`}
                            >
                                Diskon peluncuran {promo.PersenDiskon}% | mulai {FormatRupiah(penawaran.HargaBulanan)}
                                /bulan
                            </p>
                        ) : null}
                        <JudulHalaman>{judul}</JudulHalaman>
                        {keterangan ? <p className="text-isi text-teks-sekunder">{keterangan}</p> : null}
                    </header>
                    {props.Kilat ? <Pemberitahuan jenis="info">{props.Kilat}</Pemberitahuan> : null}
                    {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
                    <Card className="rounded-panel py-6 shadow-none">
                        <CardContent className="px-6">{children}</CardContent>
                    </Card>
                    <footer className="text-keterangan flex flex-wrap gap-x-4 gap-y-1 text-teks-sekunder">
                        <a href="/legal/syarat-ketentuan" className="underline">
                            Syarat & Ketentuan
                        </a>
                        <a href="/legal/kebijakan-privasi" className="underline">
                            Kebijakan Privasi
                        </a>
                    </footer>
                </main>
            </div>
            <PemberitahuanMelayang />
        </>
    );
}
