import { CheckIcon, ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';

import gambarKasirBayar from '@/Aset/Situs/KasirBayar.webp';
import gambarKasirJual from '@/Aset/Situs/KasirJual.webp';
import gambarPemilikBeranda from '@/Aset/Situs/PemilikBeranda.webp';
import gambarPemilikLaporan from '@/Aset/Situs/PemilikLaporan.webp';
import { LogoMerek } from '@/Komponen/Merek/LogoMerek';
import { cn } from '@/Komponen/Ui/utils';
import type { PenawaranAuth } from '@/Tipe/Aplikasi';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';

/** Selang pergantian iklan otomatis. */
export const SELANG_IKLAN_MS = 7000;

const KUERI_KURANGI_GERAK = '(prefers-reduced-motion: reduce)';

function LanggananKurangiGerak(saatUbah: () => void): () => void {
    if (typeof window.matchMedia !== 'function') {
        return () => undefined;
    }

    const kueri = window.matchMedia(KUERI_KURANGI_GERAK);
    kueri.addEventListener('change', saatUbah);

    return () => kueri.removeEventListener('change', saatUbah);
}

function AmbilKurangiGerak(): boolean {
    return typeof window.matchMedia === 'function' && window.matchMedia(KUERI_KURANGI_GERAK).matches;
}

/** "Starter, Pro, dan Bisnis" dari daftar nama paket di katalog. */
function GabungNama(nama: string[]): string {
    if (nama.length < 2) {
        return nama.join('');
    }

    return `${nama.slice(0, -1).join(', ')} dan ${nama[nama.length - 1] ?? ''}`;
}

/** "Pro: Rp 149.000/bulan (normal Rp 249.000) | 3 outlet, 5 perangkat…" seluruhnya dari data katalog. */
function BarisPaket(paket: PenawaranAuth['Paket'][number]): string {
    const harga = `${paket.Nama}: ${FormatRupiah(paket.HargaBulanan)}/bulan`;
    const normal = paket.HargaNormal === null ? '' : ` (normal ${FormatRupiah(paket.HargaNormal)})`;
    const batas = paket.Sorotan.length > 0 ? ` | ${paket.Sorotan.join(', ')}` : '';

    return `${harga}${normal}${batas}`;
}

type Iklan = {
    kunci: string;
    label: string;
    judul: string;
    teks: string;
    /** Tangkapan layar asli aplikasi (`Aset/Situs`, ikut build Vite), bukan ilustrasi buatan. */
    gambar?: { src: string; alt: string };
    sorot?: string[];
};

/**
 * Iklan yang tampil di panel merek halaman masuk & daftar (permintaan pemilik produk: halaman masuk back-office
 * ikut modern dan berisi iklan, seperti halaman masuk kasir). Isi iklan harga diambil dari katalog server
 * (`Penawaran`): diskon peluncuran hanya tampil bila harga normal yang lebih mahal memang sudah terjadwal.
 * Edisi Lisensi (tanpa penjualan langganan) tidak menampilkan iklan harga.
 */
export function SusunIklan(penawaran: PenawaranAuth | null, lisensi: boolean): Iklan[] {
    const daftar: Iklan[] = [];

    if (!lisensi) {
        if (penawaran?.Promo) {
            const promo = penawaran.Promo;
            daftar.push({
                kunci: 'promo',
                label: `Diskon peluncuran ${promo.PersenDiskon}%`,
                judul: `Mulai ${FormatRupiah(penawaran.HargaBulanan)} per bulan`,
                teks: `Harga normal ${FormatRupiah(promo.HargaBulananNormal)}. Promo sampai ${FormatTanggal(promo.BerlakuSampai)}${
                    promo.HargaTerkunci ? '; yang daftar sebelum itu tetap di harga ini selama langganan aktif' : ''
                }.`,
                sorot: [
                    penawaran.MasaTrialHari > 0
                        ? `Coba gratis ${penawaran.MasaTrialHari} hari, tanpa kartu kredit`
                        : 'Tanpa kartu kredit',
                ],
                gambar: { src: gambarKasirBayar, alt: 'Layar pembayaran aplikasi kasir Payoung' },
            });
        } else if (penawaran) {
            daftar.push({
                kunci: 'harga',
                label: 'Harga terjangkau',
                judul: `Mulai ${FormatRupiah(penawaran.HargaBulanan)} per bulan`,
                teks: `Paket ${penawaran.NamaPaket} untuk usaha yang baru mulai, naik paket kapan saja.`,
                sorot:
                    penawaran.MasaTrialHari > 0
                        ? [`Coba gratis ${penawaran.MasaTrialHari} hari, tanpa kartu kredit`]
                        : ['Tanpa kartu kredit'],
            });
        } else {
            daftar.push({
                kunci: 'gratis',
                label: 'Mulai gratis',
                judul: 'Coba tanpa kartu kredit',
                teks: 'Siapkan toko dalam hitungan menit, lalu upgrade saat usaha Anda berkembang.',
            });
        }
    }

    if (!lisensi && penawaran && penawaran.Paket.length > 1) {
        daftar.push({
            kunci: 'paket',
            label: 'Pilih sesuai usaha',
            judul: `Paket ${GabungNama(penawaran.Paket.map((paket) => paket.Nama))}`,
            teks: 'Harga dan batas tiap paket diambil langsung dari daftar paket Payoung.',
            sorot: penawaran.Paket.map((paket) => BarisPaket(paket)),
        });
    }

    daftar.push(
        {
            kunci: 'offline',
            label: 'Tetap jalan tanpa internet',
            judul: 'Kasir tidak berhenti saat sinyal putus',
            teks: 'Transaksi disimpan di perangkat dan tersinkron otomatis begitu internet kembali.',
            gambar: { src: gambarKasirJual, alt: 'Layar penjualan aplikasi kasir Payoung' },
        },
        {
            kunci: 'pantau',
            label: 'Pantau dari HP',
            judul: 'Omzet, stok, dan shift dalam genggaman',
            teks: 'Aplikasi Pemilik menampilkan penjualan hari ini, laporan, dan shift kasir dari mana saja.',
            gambar: { src: gambarPemilikBeranda, alt: 'Beranda aplikasi Pemilik Payoung' },
        },
        {
            kunci: 'lengkap',
            label: 'Lengkap untuk usaha Anda',
            judul: 'Stok, akuntansi, promo, dan toko online',
            teks: 'Laporan laba rugi otomatis, loyalti pelanggan, self-order QR, serta absensi karyawan dalam satu sistem.',
            gambar: { src: gambarPemilikLaporan, alt: 'Laporan di aplikasi Pemilik Payoung' },
        },
    );

    return daftar;
}

/**
 * Panel merek di kiri halaman masuk & daftar: logo, iklan bergeser (7 detik, berhenti saat disorot/difokus atau bila
 * pengguna meminta gerakan dikurangi), dan penanda iklan yang bisa diketuk. Latar `brand-gelap` polos, tanpa gradien.
 */
export default function PanelIklanAuth({
    nama,
    penawaran,
    lisensi,
}: {
    nama: string;
    penawaran: PenawaranAuth | null;
    lisensi: boolean;
}) {
    const iklan = SusunIklan(penawaran, lisensi);
    const [aktif, AturAktif] = useState(0);
    const [ditahan, AturDitahan] = useState(false);
    const kurangiGerak = useSyncExternalStore(LanggananKurangiGerak, AmbilKurangiGerak, () => false);
    const jumlah = iklan.length;
    const pewaktu = useRef<ReturnType<typeof setTimeout> | null>(null);

    const Geser = useCallback((arah: 1 | -1) => AturAktif((sekarang) => (sekarang + arah + jumlah) % jumlah), [jumlah]);

    useEffect(() => {
        if (kurangiGerak || ditahan || jumlah < 2) {
            return;
        }

        pewaktu.current = setTimeout(() => Geser(1), SELANG_IKLAN_MS);

        return () => {
            if (pewaktu.current !== null) {
                clearTimeout(pewaktu.current);
            }
        };
    }, [aktif, kurangiGerak, ditahan, jumlah, Geser]);

    return (
        <div
            className="flex h-full min-h-screen w-full flex-col justify-between gap-10 bg-brand-gelap p-10 text-brand-teks xl:p-14"
            onMouseEnter={() => AturDitahan(true)}
            onMouseLeave={() => AturDitahan(false)}
            onFocusCapture={() => AturDitahan(true)}
            onBlurCapture={() => AturDitahan(false)}
        >
            <LogoMerek nama={nama} varian="putih" className="h-16 self-start xl:h-18" />

            <section aria-roledescription="carousel" aria-label="Keunggulan Payoung" className="flex flex-col gap-8">
                <div className="grid">
                    {iklan.map((butir, indeks) => {
                        const terlihat = indeks === aktif;

                        return (
                            <article
                                key={butir.kunci}
                                role="group"
                                aria-roledescription="slide"
                                aria-label={`${indeks + 1} dari ${jumlah}`}
                                aria-hidden={terlihat ? undefined : true}
                                className={cn(
                                    'col-start-1 row-start-1 flex flex-col gap-5 transition-opacity duration-500 motion-reduce:transition-none',
                                    terlihat ? 'opacity-100' : 'pointer-events-none invisible opacity-0',
                                )}
                            >
                                <p className="text-keterangan w-fit rounded-full bg-aksen px-3 py-1 font-semibold text-teks-utama">
                                    {butir.label}
                                </p>
                                <h2 className="text-judul-bagian font-bold text-balance">{butir.judul}</h2>
                                <p className="text-pengantar max-w-md text-brand-teks/85">{butir.teks}</p>
                                {butir.sorot ? (
                                    <ul className="flex flex-col gap-2 text-isi">
                                        {butir.sorot.map((baris) => (
                                            <li key={baris} className="flex gap-2">
                                                <CheckIcon
                                                    className="mt-0.5 size-4 shrink-0 text-aksen"
                                                    aria-hidden="true"
                                                />
                                                {baris}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                                {butir.gambar ? (
                                    <div className="flex h-56 items-end justify-center overflow-hidden rounded-panel bg-brand p-4 xl:h-64">
                                        {/* Gambar iklan tak aktif tidak dimuat sampai gilirannya hampir tiba. */}
                                        <img
                                            src={butir.gambar.src}
                                            alt={terlihat ? butir.gambar.alt : ''}
                                            loading={indeks <= aktif + 1 ? 'eager' : 'lazy'}
                                            className="max-h-full w-auto rounded-kontrol object-contain object-top"
                                        />
                                    </div>
                                ) : null}
                            </article>
                        );
                    })}
                </div>

                {jumlah > 1 ? (
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            aria-label="Iklan sebelumnya"
                            onClick={() => Geser(-1)}
                            className="grid size-11 place-items-center rounded-full border border-brand-teks/30 hover:bg-brand-teks/10 focus-visible:ring-2 focus-visible:ring-aksen focus-visible:outline-none"
                        >
                            <ChevronLeftIcon className="size-5" aria-hidden="true" />
                        </button>
                        <ul className="flex items-center gap-1" aria-label="Pilih iklan">
                            {iklan.map((butir, indeks) => (
                                <li key={butir.kunci}>
                                    <button
                                        type="button"
                                        aria-label={`Iklan ${indeks + 1}: ${butir.label}`}
                                        aria-current={indeks === aktif ? 'true' : undefined}
                                        onClick={() => AturAktif(indeks)}
                                        className="grid size-11 place-items-center focus-visible:ring-2 focus-visible:ring-aksen focus-visible:outline-none"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'block h-2 rounded-full transition-all duration-300 motion-reduce:transition-none',
                                                indeks === aktif ? 'w-8 bg-aksen' : 'w-2 bg-brand-teks/40',
                                            )}
                                        />
                                    </button>
                                </li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            aria-label="Iklan berikutnya"
                            onClick={() => Geser(1)}
                            className="grid size-11 place-items-center rounded-full border border-brand-teks/30 hover:bg-brand-teks/10 focus-visible:ring-2 focus-visible:ring-aksen focus-visible:outline-none"
                        >
                            <ChevronRightIcon className="size-5" aria-hidden="true" />
                        </button>
                    </div>
                ) : null}
            </section>

            <p className="text-keterangan text-brand-teks/70">Kasir offline-first untuk usaha Indonesia | {nama}</p>
        </div>
    );
}
