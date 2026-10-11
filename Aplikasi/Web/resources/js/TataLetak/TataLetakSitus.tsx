import { Head, usePage } from '@inertiajs/react';
import { Menu, MessageCircle } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import { LogoMerek } from '@/Komponen/Merek/LogoMerek';
import { MenuSolusiDesktop, MenuSolusiSeluler } from '@/Komponen/Situs/MenuSolusi';
import PersetujuanCookie from '@/Komponen/Situs/PersetujuanCookie';
import { AdaAnalitik, BukaPengaturanCookie } from '@/Pustaka/AnalitikSitus';
import TautanSitus from '@/Komponen/Situs/TautanSitus';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/Komponen/Ui/sheet';
import type { DataSitus } from '@/Tipe/Situs';

const LABEL_MEDIA_SOSIAL: Record<string, string> = {
    Instagram: 'Instagram',
    Facebook: 'Facebook',
    Tiktok: 'TikTok',
    Youtube: 'YouTube',
    Linkedin: 'LinkedIn',
    X: 'X',
};

const LABEL_UNDUH: Record<string, string> = { Android: 'Google Play', Ios: 'App Store', Windows: 'Windows' };

function LogoSitus({ situs }: { situs: DataSitus }) {
    if (situs.Logo) {
        return <img src={situs.Logo.Url} alt={situs.NamaSitus} className="h-10 w-auto sm:h-12" />;
    }

    return <LogoMerek nama={situs.NamaSitus} className="h-9 sm:h-12" />;
}

function CekAktif(tautan: string, jalurKini: string): boolean {
    const jalur = tautan.split(/[?#]/)[0] ?? '';

    return jalur !== '' && jalur !== '/' && (jalurKini === jalur || jalurKini.startsWith(`${jalur}/`));
}

/** Seberapa jauh halaman digulir (px) sebelum bilah ajakan HP muncul: sesudah tombol hero lewat. */
const AMBANG_BILAH_AJAKAN_PX = 480;

/**
 * Bilah ajakan menempel di bawah layar HP (< 640px): tombol Coba gratis dan WhatsApp tetap terjangkau ibu jari
 * sepanjang halaman. Muncul setelah hero digulir lewat supaya tidak menutupi tombol hero; dicegah di pratinjau editor.
 */
function BilahAjakanHp({ situs }: { situs: DataSitus }) {
    const [terlihat, AturTerlihat] = useState(false);

    useEffect(() => {
        const Periksa = () => AturTerlihat(window.scrollY > AMBANG_BILAH_AJAKAN_PX);
        Periksa();
        window.addEventListener('scroll', Periksa, { passive: true });

        return () => window.removeEventListener('scroll', Periksa);
    }, []);

    if (!terlihat) {
        return null;
    }

    return (
        <div className="fixed inset-x-0 bottom-0 z-40 flex gap-2 border-t border-garis bg-permukaan px-4 pt-3 tepi-bawah-aman sm:hidden">
            <TombolSitus href={situs.TombolDaftar.Tautan} className="flex-1">
                {situs.TombolDaftar.Label}
            </TombolSitus>
            {situs.Kontak.TautanWhatsApp ? (
                <a
                    href={situs.Kontak.TautanWhatsApp}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-kontrol border border-sukses px-4 text-isi font-semibold text-sukses hover:bg-sukses-lembut focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <MessageCircle className="size-5" aria-hidden />
                    WhatsApp
                </a>
            ) : (
                <TombolSitus href={situs.TombolMasuk.Tautan} varian="kedua" className="flex-1">
                    {situs.TombolMasuk.Label}
                </TombolSitus>
            )}
        </div>
    );
}

type PropsTataLetakSitus = { judul: string; children: ReactNode; pratinjau?: boolean };

/**
 * Tata letak situs pemasaran (D-21, payoung.id): pengumuman, kepala (logo, menu, Masuk & Coba gratis), menu lipat di
 * layar sempit, kaki (kolom tautan, kontak, media sosial, unduhan), dan tombol WhatsApp melayang. Semua isi dari konsol.
 */
export default function TataLetakSitus({ judul, children, pratinjau = false }: PropsTataLetakSitus) {
    const { props, url } = usePage<{ Situs: DataSitus }>();
    const situs = props.Situs;
    const [menuTerbuka, AturMenuTerbuka] = useState(false);
    const jalurKini = url.split(/[?#]/)[0] ?? '/';

    return (
        <>
            <Head title={judul} />
            <a
                href="#isi"
                className="sr-only z-50 rounded-kontrol bg-permukaan px-4 py-2 text-isi text-teks-utama focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
            >
                Lewati ke isi
            </a>
            {pratinjau ? (
                <div
                    role="status"
                    className="bg-peringatan px-4 py-2 text-center text-label font-semibold text-permukaan"
                >
                    Pratinjau draf, belum terbit. Pengunjung belum melihat perubahan ini.
                </div>
            ) : null}
            {situs.Pengumuman ? (
                <div className="bg-aksen px-4 py-2 text-center text-label font-semibold text-teks-utama">
                    {situs.Pengumuman.Tautan ? (
                        <TautanSitus href={situs.Pengumuman.Tautan} className="underline underline-offset-2">
                            {situs.Pengumuman.Teks}
                        </TautanSitus>
                    ) : (
                        situs.Pengumuman.Teks
                    )}
                </div>
            ) : null}
            <header className="sticky top-0 z-40 border-b border-garis bg-permukaan">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:h-20">
                    <TautanSitus href="/" className="shrink-0" aria-label={`${situs.NamaSitus}, beranda`}>
                        <LogoSitus situs={situs} />
                    </TautanSitus>
                    <nav aria-label="Menu utama" className="hidden lg:block">
                        <ul className="flex items-center gap-1">
                            {situs.Menu.map((m) =>
                                m.Solusi ? (
                                    <MenuSolusiDesktop
                                        key={`${m.Label}-${m.Tautan}`}
                                        butir={m}
                                        aktif={jalurKini.startsWith('/solusi')}
                                        jalurKini={jalurKini}
                                    />
                                ) : (
                                    <li key={`${m.Label}-${m.Tautan}`}>
                                        <TautanSitus
                                            href={m.Tautan}
                                            className={`inline-flex min-h-11 items-center rounded-kontrol px-3 text-isi font-medium hover:bg-permukaan-sorot ${
                                                CekAktif(m.Tautan, jalurKini) ? 'text-brand' : 'text-teks-utama'
                                            }`}
                                        >
                                            {m.Label}
                                        </TautanSitus>
                                    </li>
                                ),
                            )}
                        </ul>
                    </nav>
                    <div className="flex items-center gap-2">
                        <TombolSitus
                            href={situs.TombolMasuk.Tautan}
                            varian="garis-merek"
                            className="hidden sm:inline-flex"
                        >
                            {situs.TombolMasuk.Label}
                        </TombolSitus>
                        <TombolSitus href={situs.TombolDaftar.Tautan} varian="aksen" className="whitespace-nowrap">
                            {situs.TombolDaftar.Label}
                        </TombolSitus>
                        <Sheet open={menuTerbuka} onOpenChange={AturMenuTerbuka}>
                            <SheetTrigger
                                className="inline-flex size-11 items-center justify-center rounded-kontrol text-teks-utama hover:bg-permukaan-sorot lg:hidden"
                                aria-label="Buka menu"
                            >
                                <Menu className="size-6" aria-hidden />
                            </SheetTrigger>
                            <SheetContent side="right" className="w-full max-w-xs gap-0 bg-permukaan">
                                <SheetHeader>
                                    <SheetTitle>Menu</SheetTitle>
                                    <SheetDescription className="sr-only">
                                        Navigasi situs {situs.NamaSitus}
                                    </SheetDescription>
                                </SheetHeader>
                                <nav
                                    aria-label="Menu utama"
                                    className="flex flex-col gap-1 px-4"
                                    onClick={(p) => {
                                        // Tutup menu setelah memilih tautan (navigasi Inertia tidak memuat ulang halaman).
                                        if ((p.target as HTMLElement).closest('a')) {
                                            AturMenuTerbuka(false);
                                        }
                                    }}
                                >
                                    {situs.Menu.map((m) =>
                                        m.Solusi ? (
                                            <MenuSolusiSeluler
                                                key={`${m.Label}-${m.Tautan}`}
                                                butir={m}
                                                aktif={jalurKini.startsWith('/solusi')}
                                            />
                                        ) : (
                                            <TautanSitus
                                                key={`${m.Label}-${m.Tautan}`}
                                                href={m.Tautan}
                                                className={`flex min-h-12 items-center rounded-kontrol px-3 text-subjudul font-medium hover:bg-permukaan-sorot ${
                                                    CekAktif(m.Tautan, jalurKini) ? 'text-brand' : 'text-teks-utama'
                                                }`}
                                            >
                                                {m.Label}
                                            </TautanSitus>
                                        ),
                                    )}
                                </nav>
                                <div className="mt-4 flex flex-col gap-2 border-t border-garis p-4">
                                    <TombolSitus href={situs.TombolMasuk.Tautan} varian="kedua">
                                        {situs.TombolMasuk.Label}
                                    </TombolSitus>
                                    <TombolSitus href={situs.TombolDaftar.Tautan}>
                                        {situs.TombolDaftar.Label}
                                    </TombolSitus>
                                </div>
                            </SheetContent>
                        </Sheet>
                    </div>
                </div>
            </header>
            <main id="isi" className="bg-latar">
                {children}
            </main>
            <KakiSitus situs={situs} />
            {/*
             * Satu tumpukan di tepi bawah (D-28). Sebelumnya banner cookie `fixed bottom-0 z-50` dan tombol
             * WhatsApp `fixed bottom-4 z-30` memakai area yang sama, jadi banner menutupi tombolnya sampai
             * pengunjung memilih. Sekarang keduanya bertumpuk, jadi tombolnya naik sendiri saat banner tampil —
             * tanpa menebak tinggi banner, yang berubah mengikuti panjang teks & lebar layar.
             *
             * Wadahnya `pointer-events-none` supaya jalur kosong di kiri tombol tidak menelan klik ke isi halaman.
             */}
            {pratinjau ? null : <BilahAjakanHp situs={situs} />}
            <div className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col gap-3 pb-4 tepi-bawah-aman">
                {situs.WhatsAppMelayang && situs.Kontak.TautanWhatsApp ? (
                    <div className="flex justify-end px-4 max-sm:hidden">
                        <a
                            href={situs.Kontak.TautanWhatsApp}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="pointer-events-auto inline-flex min-h-12 items-center gap-2 rounded-full bg-sukses px-4 text-isi font-semibold text-permukaan hover:bg-sukses/90"
                        >
                            <MessageCircle className="size-5" aria-hidden />
                            <span>Chat WhatsApp</span>
                        </a>
                    </div>
                ) : null}
                {pratinjau ? null : <PersetujuanCookie analitik={situs.Analitik} />}
            </div>
        </>
    );
}

/**
 * Kaki situs terang (D-39): sebelumnya biru merek penuh menempel di bawah blok CTA biru dan FAQ Navy, sehingga
 * sepertiga bawah halaman menjadi tiga balok warna gelap. Kini netral; satu-satunya bidang warna di bawah adalah CTA.
 */
function KakiSitus({ situs }: { situs: DataSitus }) {
    const mediaSosial = Object.entries(situs.MediaSosial).filter(
        (e): e is [string, string] => typeof e[1] === 'string',
    );
    const unduhan = Object.entries(situs.TautanUnduh).filter((e): e is [string, string] => typeof e[1] === 'string');
    const kontak = situs.Kontak;

    return (
        <footer className="bg-brand-gelap text-brand-gelap-teks">
            <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:grid-cols-2 lg:grid-cols-[1.4fr_repeat(3,1fr)]">
                <div className="flex flex-col gap-4">
                    <LogoMerek nama={situs.NamaSitus} varian="putih" className="h-12 self-start" />
                    {situs.TeksKaki ? <p className="text-isi">{situs.TeksKaki}</p> : null}
                    <address className="flex flex-col gap-1 text-isi not-italic">
                        {kontak.TautanWhatsApp && kontak.WhatsApp ? (
                            <a
                                href={kontak.TautanWhatsApp}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="hover:text-aksen"
                            >
                                WhatsApp {kontak.WhatsApp}
                            </a>
                        ) : null}
                        {kontak.Email ? (
                            <a href={`mailto:${kontak.Email}`} className="hover:text-aksen">
                                {kontak.Email}
                            </a>
                        ) : null}
                        {kontak.Telepon ? (
                            <a href={`tel:${kontak.Telepon.replace(/[^\d+]/g, '')}`} className="hover:text-aksen">
                                {kontak.Telepon}
                            </a>
                        ) : null}
                        {kontak.Alamat ? <span className="whitespace-pre-line">{kontak.Alamat}</span> : null}
                        {kontak.JamLayanan ? <span>{kontak.JamLayanan}</span> : null}
                    </address>
                </div>
                {situs.MenuKaki.map((kolom) => (
                    <nav key={kolom.Judul} aria-label={kolom.Judul} className="flex flex-col gap-3">
                        <h2 className="text-isi font-semibold text-permukaan">{kolom.Judul}</h2>
                        <ul className="flex flex-col gap-2">
                            {kolom.Tautan.map((t) => (
                                <li key={`${t.Label}-${t.Tautan}`}>
                                    <TautanSitus href={t.Tautan} className="text-isi hover:text-aksen">
                                        {t.Label}
                                    </TautanSitus>
                                </li>
                            ))}
                        </ul>
                    </nav>
                ))}
            </div>
            <div className="border-t border-brand-gelap-garis max-sm:pb-20">
                <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6 text-label sm:flex-row sm:items-center sm:justify-between">
                    <p>
                        © {situs.Tahun} {situs.NamaSitus}
                        {situs.Slogan ? ` | ${situs.Slogan}` : ''}
                    </p>
                    <div className="flex flex-wrap gap-x-4 gap-y-2">
                        {AdaAnalitik(situs.Analitik) ? (
                            <button type="button" onClick={BukaPengaturanCookie} className="hover:text-aksen">
                                Pengaturan cookie
                            </button>
                        ) : null}
                        {unduhan.map(([kunci, tautan]) => (
                            <a
                                key={kunci}
                                href={tautan}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="hover:text-aksen"
                            >
                                {LABEL_UNDUH[kunci] ?? kunci}
                            </a>
                        ))}
                        {mediaSosial.map(([kunci, tautan]) => (
                            <a
                                key={kunci}
                                href={tautan}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="hover:text-aksen"
                            >
                                {LABEL_MEDIA_SOSIAL[kunci] ?? kunci}
                            </a>
                        ))}
                    </div>
                </div>
            </div>
        </footer>
    );
}
