import { ChevronDown } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

import IkonSitus from '@/Komponen/Situs/IkonSitus';
import TautanSitus from '@/Komponen/Situs/TautanSitus';
import type { ButirMenuSitus, KelompokMenuSitus } from '@/Tipe/Situs';

/** Jeda (ms) sebelum panel menutup setelah kursor keluar, supaya tidak berkedip saat kursor melintasi celah. */
const JEDA_TUTUP_MS = 250;

/** Kolom dengan banyak sektor dibagi dua lajur supaya panel tidak menjulang. */
const AMBANG_DUA_LAJUR = 7;

function KolomKelompok({ kelompok, saatPilih }: { kelompok: KelompokMenuSitus; saatPilih: () => void }) {
    const dua = kelompok.Sektor.length > AMBANG_DUA_LAJUR;

    return (
        <section aria-label={kelompok.Judul} className={dua ? 'lg:col-span-2' : undefined}>
            <TautanSitus
                href={kelompok.Tautan}
                className="mb-2 inline-flex items-center gap-2 rounded-kontrol px-2 py-1 text-isi font-semibold text-teks-utama hover:text-brand"
            >
                <IkonSitus nama={kelompok.Ikon} className="size-5 text-brand" />
                {kelompok.Judul}
            </TautanSitus>
            <ul className={dua ? 'grid gap-x-4 sm:grid-cols-2' : 'flex flex-col'} onClick={saatPilih}>
                {kelompok.Sektor.map((sektor) => (
                    <li key={sektor.Tautan}>
                        <TautanSitus
                            href={sektor.Tautan}
                            className="group flex items-start gap-3 rounded-kontrol px-2 py-1.5 hover:bg-permukaan-sorot focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-kontrol bg-brand-lembut text-brand">
                                <IkonSitus nama={sektor.Ikon} className="size-5" />
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span className="text-isi font-medium text-teks-utama group-hover:text-brand">
                                    {sektor.Nama}
                                </span>
                                <span className="text-label text-teks-sekunder">{sektor.Ringkas}</span>
                            </span>
                        </TautanSitus>
                    </li>
                ))}
            </ul>
        </section>
    );
}

type PropsDesktop = { butir: ButirMenuSitus; aktif: boolean; jalurKini: string };

/**
 * Menu "Solusi" di kepala situs (layar lebar): tombol yang membuka panel selebar kepala berisi sektor per kelompok.
 * Terbuka lewat klik, Enter/Spasi, atau kursor; menutup dengan Esc, klik di luar, berpindah halaman, atau kursor keluar.
 * Panel menempel ke kepala situs (`header` sticky = acuan posisi), jadi `li` ini sengaja tidak `relative`.
 */
export function MenuSolusiDesktop({ butir, aktif, jalurKini }: PropsDesktop) {
    // Terbuka hanya selama halaman tempat ia dibuka masih sama: berpindah halaman menutupnya tanpa effect.
    const [jalurBuka, AturJalurBuka] = useState<string | null>(null);
    const terbuka = jalurBuka === jalurKini;
    const AturTerbuka = (nilai: boolean) => AturJalurBuka(nilai ? jalurKini : null);
    const akar = useRef<HTMLLIElement>(null);
    const tombol = useRef<HTMLButtonElement>(null);
    const penutup = useRef<ReturnType<typeof setTimeout> | null>(null);
    const idPanel = useId();

    const BatalkanTutup = () => {
        if (penutup.current !== null) {
            clearTimeout(penutup.current);
            penutup.current = null;
        }
    };

    useEffect(() => BatalkanTutup, []);

    useEffect(() => {
        if (!terbuka) {
            return;
        }

        const SaatKlikLuar = (p: PointerEvent) => {
            if (akar.current && !akar.current.contains(p.target as Node)) {
                AturJalurBuka(null);
            }
        };
        const SaatTombol = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                AturJalurBuka(null);
                tombol.current?.focus();
            }
        };

        document.addEventListener('pointerdown', SaatKlikLuar);
        document.addEventListener('keydown', SaatTombol);

        return () => {
            document.removeEventListener('pointerdown', SaatKlikLuar);
            document.removeEventListener('keydown', SaatTombol);
        };
    }, [terbuka]);

    return (
        <li
            ref={akar}
            onPointerEnter={(e) => {
                if (e.pointerType === 'mouse') {
                    BatalkanTutup();
                    AturTerbuka(true);
                }
            }}
            onPointerLeave={(e) => {
                if (e.pointerType === 'mouse') {
                    BatalkanTutup();
                    penutup.current = setTimeout(() => AturTerbuka(false), JEDA_TUTUP_MS);
                }
            }}
            onBlur={(e) => {
                if (!e.currentTarget.contains(e.relatedTarget as Node | null)) {
                    AturTerbuka(false);
                }
            }}
        >
            <button
                ref={tombol}
                type="button"
                aria-expanded={terbuka}
                aria-controls={idPanel}
                onClick={() => AturTerbuka(!terbuka)}
                className={`inline-flex min-h-11 items-center gap-1 rounded-kontrol px-3 text-isi font-medium hover:bg-permukaan-sorot focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none ${
                    aktif || terbuka ? 'text-brand' : 'text-teks-utama'
                }`}
            >
                {butir.Label}
                <ChevronDown
                    aria-hidden
                    className={`size-4 transition-transform motion-reduce:transition-none ${terbuka ? 'rotate-180' : ''}`}
                />
            </button>
            <div
                id={idPanel}
                hidden={!terbuka}
                className="absolute inset-x-0 top-full max-h-[calc(100dvh-5rem)] overflow-y-auto overscroll-contain border-y border-garis bg-permukaan"
            >
                <div className="mx-auto grid max-w-6xl gap-6 px-4 py-6 lg:grid-cols-4">
                    {(butir.Solusi ?? []).map((kelompok) => (
                        <KolomKelompok key={kelompok.Tautan} kelompok={kelompok} saatPilih={() => AturTerbuka(false)} />
                    ))}
                </div>
                <div className="border-t border-garis bg-latar">
                    <p className="mx-auto max-w-6xl px-4 py-3 text-label text-teks-sekunder">
                        Usaha Anda belum tercantum? Payoung bisa disesuaikan.{' '}
                        <TautanSitus href="/kontak" className="font-semibold text-brand underline underline-offset-2">
                            Tanyakan ke kami
                        </TautanSitus>
                    </p>
                </div>
            </div>
        </li>
    );
}

type PropsSeluler = { butir: ButirMenuSitus; aktif: boolean };

/** Menu "Solusi" di lembar menu HP: daftar lipat, kelompok lalu sektornya. */
export function MenuSolusiSeluler({ butir, aktif }: PropsSeluler) {
    const [terbuka, AturTerbuka] = useState(aktif);
    const idPanel = useId();

    return (
        <div className="flex flex-col">
            <button
                type="button"
                aria-expanded={terbuka}
                aria-controls={idPanel}
                onClick={() => AturTerbuka((v) => !v)}
                className={`flex min-h-12 items-center justify-between rounded-kontrol px-3 text-subjudul font-medium hover:bg-permukaan-sorot ${
                    aktif ? 'text-brand' : 'text-teks-utama'
                }`}
            >
                {butir.Label}
                <ChevronDown
                    aria-hidden
                    className={`size-5 transition-transform motion-reduce:transition-none ${terbuka ? 'rotate-180' : ''}`}
                />
            </button>
            <div id={idPanel} hidden={!terbuka} className="flex flex-col gap-3 pb-2 pl-3">
                {(butir.Solusi ?? []).map((kelompok) => (
                    <section key={kelompok.Tautan} aria-label={kelompok.Judul} className="flex flex-col">
                        <TautanSitus
                            href={kelompok.Tautan}
                            className="flex min-h-11 items-center gap-2 rounded-kontrol px-3 text-isi font-semibold text-teks-utama hover:bg-permukaan-sorot"
                        >
                            <IkonSitus nama={kelompok.Ikon} className="size-5 text-brand" />
                            {kelompok.Judul}
                        </TautanSitus>
                        <ul className="flex flex-col">
                            {kelompok.Sektor.map((sektor) => (
                                <li key={sektor.Tautan}>
                                    <TautanSitus
                                        href={sektor.Tautan}
                                        className="flex min-h-11 items-center rounded-kontrol px-3 pl-10 text-isi text-teks-sekunder hover:bg-permukaan-sorot hover:text-brand"
                                    >
                                        {sektor.Nama}
                                    </TautanSitus>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </div>
    );
}
