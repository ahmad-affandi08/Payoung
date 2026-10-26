import { usePage } from '@inertiajs/react';
import { BadgeCheck, MessageCircle, Printer, Search, Smartphone, TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';

import TautanSitus from '@/Komponen/Situs/TautanSitus';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakSitus from '@/TataLetak/TataLetakSitus';
import {
    AmbilJenisStatusKompatibilitas,
    LabelSambunganPrinter,
    type BarisKompatibilitas,
    type StatusKompatibilitas,
} from '@/Tipe/Kompatibilitas';
import type { DataSitus } from '@/Tipe/Situs';

type PilihanStatus = 'Semua' | StatusKompatibilitas;

/** Arti tiap status untuk calon pengguna (PRD §17.2.5a). Status selalu bertulisan, warna hanya penguat. */
const ARTI_STATUS: { Status: StatusKompatibilitas; Judul: string; Teks: string }[] = [
    { Status: 'Tersertifikasi', Judul: 'Tersertifikasi', Teks: 'Lolos uji tim kami dengan perangkat nyata.' },
    { Status: 'Kompatibel', Judul: 'Kompatibel', Teks: 'Lolos Uji Perangkat di usaha pengguna.' },
    { Status: 'Terbatas', Judul: 'Terbatas', Teks: 'Bisa dipakai dengan kendala. Baca catatannya.' },
];

function KartuPerangkat({ baris }: { baris: BarisKompatibilitas }) {
    const Ikon = baris.Jenis === 'Printer' ? Printer : Smartphone;
    const sambungan = baris.Sambungan ? (LabelSambunganPrinter[baris.Sambungan] ?? baris.Sambungan) : null;

    return (
        <li className="flex flex-col gap-3 rounded-panel border border-garis bg-permukaan p-4">
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-kontrol bg-brand-lembut text-brand">
                    <Ikon aria-hidden className="size-5" strokeWidth={1.75} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h3 className="text-isi font-semibold break-words text-teks-utama">{baris.Nama}</h3>
                    <p className="text-keterangan text-teks-sekunder">
                        {[sambungan, baris.JumlahTenant > 0 ? `${String(baris.JumlahTenant)} usaha` : null]
                            .filter((b) => b !== null)
                            .join(' | ') || 'Belum dipakai di usaha mana pun'}
                    </p>
                </div>
            </div>
            <div>
                <LabelStatus jenis={AmbilJenisStatusKompatibilitas(baris.Status)} teks={baris.LabelStatus} />
            </div>
            {baris.Catatan ? <p className="text-keterangan text-teks-sekunder">{baris.Catatan}</p> : null}
        </li>
    );
}

function Kelompok({
    judul,
    ringkas,
    baris,
    tersaring,
}: {
    judul: string;
    ringkas: string;
    baris: BarisKompatibilitas[];
    tersaring: boolean;
}) {
    return (
        <section aria-label={judul} className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-judul font-bold text-teks-utama">{judul}</h2>
                <p className="text-isi text-teks-sekunder">{ringkas}</p>
            </div>
            {baris.length === 0 ? (
                <p className="rounded-panel border border-dashed border-garis-input bg-permukaan px-4 py-8 text-center text-isi text-teks-sekunder">
                    {tersaring
                        ? 'Tidak ada yang cocok dengan pencarian ini.'
                        : 'Belum ada yang teruji di kelompok ini.'}
                </p>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {baris.map((b) => (
                        <KartuPerangkat key={b.Uuid} baris={b} />
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Daftar kompatibilitas perangkat & printer Payoung (PRD §17.2.5a HCL, v1.98) untuk calon pengguna: Tersertifikasi (lolos
 * uji tim), Kompatibel (lolos Uji Perangkat di usaha pengguna), Terbatas (ada kendala; lihat catatan).
 *
 * D-92: ikut shell situs pemasaran (kepala, menu, kaki) dan tampil sebagai kartu yang bisa dicari & disaring status.
 * Bukan tabel data back-office; bundle situs sengaja tanpa TanStack, jadi penyaringan dilakukan di peramban.
 */
export default function HalamanKompatibilitasPerangkatPublik({ Baris }: { Baris: BarisKompatibilitas[] }) {
    const { Situs } = usePage<{ Situs: DataSitus }>().props;
    const [kata, AturKata] = useState('');
    const [status, AturStatus] = useState<PilihanStatus>('Semua');

    const HitungStatus = (s: StatusKompatibilitas) => Baris.filter((b) => b.Status === s).length;
    const tersaring = kata.trim() !== '' || status !== 'Semua';

    const lolos = useMemo(() => {
        const cari = kata.trim().toLowerCase();

        return Baris.filter(
            (b) =>
                (status === 'Semua' || b.Status === status) &&
                (cari === '' || `${b.Nama} ${b.Catatan ?? ''}`.toLowerCase().includes(cari)),
        );
    }, [Baris, kata, status]);

    const perangkat = lolos.filter((b) => b.Jenis === 'Perangkat');
    const printer = lolos.filter((b) => b.Jenis === 'Printer');
    const kosongSemua = Baris.length === 0;

    const BuatKelasChip = (aktif: boolean) =>
        `inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-label font-semibold focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none ${
            aktif
                ? 'border-brand bg-brand text-permukaan'
                : 'border-garis bg-permukaan text-teks-utama hover:bg-permukaan-sorot'
        }`;

    return (
        <TataLetakSitus judul="Kompatibilitas perangkat">
            <header className="border-b border-garis bg-brand-lembut">
                <div className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-12 sm:py-16">
                    <div className="flex max-w-3xl flex-col gap-3">
                        <p className="text-label font-semibold tracking-wide text-brand uppercase">
                            Perangkat kompatibel
                        </p>
                        <JudulHalaman skala="situs">Perangkat &amp; printer yang didukung</JudulHalaman>
                        <p className="text-pengantar text-teks-sekunder">
                            Payoung berjalan di Android, iPad/iPhone, dan Windows, dengan printer thermal LAN/Wi-Fi,
                            Bluetooth, USB, atau printer bawaan mesin kasir. Daftar ini disusun dari hasil uji perangkat
                            di usaha pengguna dan uji tim kami.
                        </p>
                    </div>
                    <ul className="grid gap-3 sm:grid-cols-3">
                        {ARTI_STATUS.map((a) => (
                            <li key={a.Status} className="flex items-start gap-3 rounded-panel bg-permukaan p-4">
                                {a.Status === 'Terbatas' ? (
                                    <TriangleAlert aria-hidden className="mt-0.5 size-5 shrink-0 text-peringatan" />
                                ) : (
                                    <BadgeCheck aria-hidden className="mt-0.5 size-5 shrink-0 text-sukses" />
                                )}
                                <div className="flex flex-col">
                                    <span className="text-isi font-semibold text-teks-utama">
                                        {a.Judul} ({String(HitungStatus(a.Status))})
                                    </span>
                                    <span className="text-keterangan text-teks-sekunder">{a.Teks}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </header>

            <div className="mx-auto flex max-w-6xl flex-col gap-10 px-4 py-10 sm:py-14">
                {kosongSemua ? (
                    <section
                        aria-label="Daftar sedang disusun"
                        className="flex flex-col items-center gap-3 rounded-panel border border-dashed border-garis-input bg-permukaan px-6 py-14 text-center"
                    >
                        <span className="flex size-12 items-center justify-center rounded-full bg-brand-lembut text-brand">
                            <Printer aria-hidden className="size-6" strokeWidth={1.75} />
                        </span>
                        <h2 className="text-judul font-bold text-teks-utama">Daftar sedang disusun</h2>
                        <p className="max-w-xl text-isi text-teks-sekunder">
                            Perangkat dan printer muncul di sini setelah lolos uji. Punya perangkat yang ingin
                            dipastikan cocok? Tanyakan ke kami, atau coba langsung lewat menu Uji perangkat di aplikasi
                            kasir.
                        </p>
                    </section>
                ) : (
                    <>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <label className="relative block sm:w-96">
                                <span className="sr-only">Cari merek atau model</span>
                                <Search
                                    aria-hidden
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-teks-sekunder"
                                />
                                <input
                                    type="search"
                                    value={kata}
                                    onChange={(e) => AturKata(e.target.value)}
                                    placeholder="Cari merek atau model"
                                    className="min-h-11 w-full rounded-kontrol border border-garis-input bg-permukaan pr-3 pl-9 text-isi text-teks-utama focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                />
                            </label>
                            <div role="group" aria-label="Filter status" className="flex flex-wrap gap-2">
                                {(['Semua', 'Tersertifikasi', 'Kompatibel', 'Terbatas'] as const).map((p) => (
                                    <button
                                        key={p}
                                        type="button"
                                        aria-pressed={status === p}
                                        onClick={() => AturStatus(p)}
                                        className={BuatKelasChip(status === p)}
                                    >
                                        {p}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <Kelompok
                            judul="Perangkat"
                            ringkas="HP, tablet, dan mesin kasir yang menjalankan aplikasi kasir."
                            baris={perangkat}
                            tersaring={tersaring}
                        />
                        <Kelompok
                            judul="Printer struk"
                            ringkas="Printer thermal untuk struk, tiket dapur, dan laporan shift."
                            baris={printer}
                            tersaring={tersaring}
                        />
                    </>
                )}

                <section
                    aria-label="Perangkat Anda belum ada di daftar"
                    className="flex flex-col gap-4 rounded-panel bg-brand-gelap p-6 text-brand-gelap-teks sm:flex-row sm:items-center sm:justify-between sm:p-8"
                >
                    <div className="flex max-w-2xl flex-col gap-1">
                        <h2 className="text-judul font-bold">Perangkat Anda belum ada di daftar?</h2>
                        <p className="text-isi opacity-90">
                            Belum tercantum bukan berarti tidak cocok. Coba lewat menu Uji perangkat di aplikasi kasir:
                            hasilnya langsung menambah daftar ini, dan tim kami membantu bila ada kendala.
                        </p>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        {Situs.Kontak.TautanWhatsApp ? (
                            <a
                                href={Situs.Kontak.TautanWhatsApp}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-kontrol bg-permukaan px-4 text-isi font-semibold text-teks-utama hover:bg-permukaan-sorot focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <MessageCircle aria-hidden className="size-5" />
                                Tanya lewat WhatsApp
                            </a>
                        ) : (
                            <TautanSitus
                                href="/kontak"
                                className="inline-flex min-h-11 items-center justify-center rounded-kontrol bg-permukaan px-4 text-isi font-semibold text-teks-utama hover:bg-permukaan-sorot"
                            >
                                Hubungi kami
                            </TautanSitus>
                        )}
                        <TombolSitus href={Situs.TombolDaftar.Tautan} varian="kedua">
                            {Situs.TombolDaftar.Label}
                        </TombolSitus>
                    </div>
                </section>
            </div>
        </TataLetakSitus>
    );
}
