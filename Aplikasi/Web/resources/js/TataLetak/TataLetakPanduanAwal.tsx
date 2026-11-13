import { Head, Link, router, usePage } from '@inertiajs/react';
import { LogOutIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { LogoMerek } from '@/Komponen/Merek/LogoMerek';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

import { PemberitahuanMelayang } from './BagianTataLetak';

type PropsTataLetakPanduanAwal = {
    judul: string;
    /** D-24: panduan wajib (tenant baru, belum selesai) = tanpa jalan pintas ke Beranda. */
    wajib: boolean;
    children: ReactNode;
};

/**
 * Halaman panduan awal sebagai layar sendiri (D-24): tanpa sidebar & menu, hanya logo, nama usaha, dan Keluar.
 * Tenant lama yang membuka panduan lagi mendapat tautan "Ke Beranda".
 */
export default function TataLetakPanduanAwal({ judul, wajib, children }: PropsTataLetakPanduanAwal) {
    const { props } = usePage<PropsBersamaAplikasi>();

    return (
        <>
            <Head title={judul} />
            <div className="min-h-screen bg-latar">
                <header className="border-b border-garis bg-permukaan">
                    <div className="mx-auto flex w-full max-w-4xl items-center justify-between gap-3 px-4 py-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <LogoMerek nama={props.NamaAplikasi} />
                            {props.TenantAktif ? (
                                <span className="hidden truncate text-label text-teks-sekunder sm:inline">
                                    {props.TenantAktif.Nama}
                                </span>
                            ) : null}
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            {!wajib ? (
                                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                                    <Link href="/kelola">Ke Beranda</Link>
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-8 pointer-coarse:h-11"
                                onClick={() => router.post('/keluar')}
                            >
                                <LogOutIcon aria-hidden="true" />
                                Keluar
                            </Button>
                        </div>
                    </div>
                </header>
                <main className="mx-auto flex w-full max-w-4xl flex-col px-4 py-6 md:py-8">
                    {/* D-282: sama dengan halaman back-office lain, satu kontainer putih dari judul sampai bawah. */}
                    <div data-slot="badan-halaman" className="badan-halaman">
                        <JudulHalaman>{judul}</JudulHalaman>
                        {props.Kilat ? <Pemberitahuan jenis="sukses">{props.Kilat}</Pemberitahuan> : null}
                        {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
                        {children}
                    </div>
                </main>
            </div>
            <PemberitahuanMelayang />
        </>
    );
}
