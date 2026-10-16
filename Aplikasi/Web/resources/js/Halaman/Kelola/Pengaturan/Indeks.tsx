import { Link, usePage } from '@inertiajs/react';
import { ChevronRightIcon, LockIcon } from 'lucide-react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import DialogNaikPaket from '@/Komponen/Langganan/DialogNaikPaket';
import { CekSesuaiSektor } from '@/Pustaka/Sektor';
import {
    CekButirSesuaiEdisi,
    daftarPengaturan,
    pintasanPengaturan,
    SaringPengaturan,
    type ButirPengaturan,
} from '@/Pustaka/DaftarPengaturan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { IzinTenant, PunyaIzinTenant } from '@/Tipe/Organisasi';

/**
 * Halaman Pengaturan: satu tempat untuk menemukan semua pengaturan usaha. Butirnya tetap tinggal di modul
 * masing-masing (lihat `Pustaka/DaftarPengaturan`), halaman ini hanya menautkannya.
 *
 * Penyaringan izin & gembok fitur paket memakai `Akses` dan `FiturPaket` dari props bersama, sama seperti menu
 * samping, supaya tidak ada butir yang tampil di satu tempat tapi hilang di tempat lain.
 */
export default function HalamanPengaturan() {
    const { props } = usePage<PropsBersamaAplikasi>();
    const terkunci = props.FiturPaket?.Terkunci ?? {};
    const [kunciPenawaran, AturKunciPenawaran] = useState<string | null>(null);
    const penawaran = kunciPenawaran === null ? undefined : terkunci[kunciPenawaran];

    const CekBoleh = (butir: ButirPengaturan) =>
        CekButirSesuaiEdisi(butir, props.Edisi) &&
        CekSesuaiSektor(butir, props.SektorOutlet ?? []) &&
        (butir.izin === null ||
            PunyaIzinTenant(props.Akses, butir.izin) ||
            (butir.atau !== undefined && PunyaIzinTenant(props.Akses, butir.atau)));
    const CekTerkunci = (butir: ButirPengaturan) => butir.fitur !== undefined && terkunci[butir.fitur] !== undefined;

    // Kotak cari: 25 butir di 8 grup terlalu banyak untuk dipindai mata. Penyaringannya di `SaringPengaturan`
    // supaya bisa diuji tanpa merender halaman.
    const [kata, AturKata] = useState('');
    const cari = kata.trim();
    const grup = SaringPengaturan(kata, CekBoleh);
    const jumlah = grup.reduce((total, baris) => total + baris.butir.length, 0);
    // "Paling sering dipakai": pintasan ke butir yang boleh dibuka pengguna ini; hilang saat mencari.
    const semuaButir = daftarPengaturan.flatMap(({ butir }) => butir);
    const pintasan = pintasanPengaturan
        .map((href) => semuaButir.find((butir) => butir.href === href))
        .filter((butir): butir is ButirPengaturan => butir !== undefined && CekBoleh(butir) && !CekTerkunci(butir));

    return (
        <TataLetakAplikasi judul="Pengaturan">
            {kunciPenawaran !== null && penawaran ? (
                <DialogNaikPaket
                    kunci={kunciPenawaran}
                    penawaran={penawaran}
                    namaPaket={props.FiturPaket?.NamaPaket ?? null}
                    bolehKelola={PunyaIzinTenant(props.Akses, IzinTenant.LanggananKelola)}
                    saatTutup={() => AturKunciPenawaran(null)}
                />
            ) : null}

            <p className="text-isi text-teks-sekunder">
                Semua yang diatur sekali lalu jarang disentuh ada di sini, dikelompokkan per bagian usaha.
            </p>

            <div className="max-w-md">
                <BidangTeks
                    label="Cari pengaturan"
                    nilai={kata}
                    saatBerubah={AturKata}
                    keterangan="Ketik nama pengaturan, misal struk, pajak, atau perangkat."
                />
            </div>

            {cari === '' && pintasan.length > 0 ? (
                <section aria-labelledby="judul-pintasan" className="flex flex-col gap-2">
                    <h2 id="judul-pintasan" className="text-label font-semibold text-teks-utama">
                        Paling sering dipakai
                    </h2>
                    <ul className="flex flex-wrap gap-2">
                        {pintasan.map((butir) => (
                            <li key={butir.href}>
                                <Link
                                    href={butir.href}
                                    className="inline-flex min-h-11 items-center rounded-panel border border-garis bg-permukaan px-4 text-label font-semibold text-teks-utama hover:bg-permukaan-redup focus-visible:bg-permukaan-redup"
                                >
                                    {butir.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {/* Keadaan hasil cari kosong (§17.6.6): tanpa ilustrasi, karena ini hasil saring, bukan data kosong. */}
            <p aria-live="polite" className="sr-only">
                {cari === '' ? '' : `${String(jumlah)} pengaturan cocok dengan "${kata.trim()}".`}
            </p>

            {jumlah === 0 ? (
                <p className="rounded-panel border border-garis bg-permukaan px-4 py-6 text-isi text-teks-sekunder">
                    Tidak ada pengaturan yang cocok dengan &quot;{kata.trim()}&quot;. Coba kata lain, atau cari halaman
                    lewat pencarian cepat di kanan atas (Ctrl K).
                </p>
            ) : null}

            {grup.map((baris) => (
                <section key={baris.judul} className="flex flex-col gap-2">
                    <h2 className="text-label font-semibold text-teks-utama">{baris.judul}</h2>
                    <ul className="divide-y divide-garis overflow-hidden rounded-panel border border-garis bg-permukaan">
                        {baris.butir.map((butir) => (
                            <li key={butir.href}>
                                {CekTerkunci(butir) ? (
                                    <button
                                        type="button"
                                        onClick={() => AturKunciPenawaran(butir.fitur ?? null)}
                                        className="flex w-full min-h-11 items-start gap-3 px-4 py-3 text-left hover:bg-permukaan-redup focus-visible:bg-permukaan-redup"
                                    >
                                        <BarisPengaturan butir={butir} terkunci />
                                    </button>
                                ) : (
                                    <Link
                                        href={butir.href}
                                        className="flex min-h-11 items-start gap-3 px-4 py-3 hover:bg-permukaan-redup focus-visible:bg-permukaan-redup"
                                    >
                                        <BarisPengaturan butir={butir} />
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </TataLetakAplikasi>
    );
}

/** Isi satu baris: label (+ penanda gembok berteks) dan keterangannya. */
function BarisPengaturan({ butir, terkunci = false }: { butir: ButirPengaturan; terkunci?: boolean }) {
    return (
        <>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex flex-wrap items-center gap-2 text-label font-semibold text-teks-utama">
                    {butir.label}
                    {terkunci ? (
                        <span className="inline-flex items-center gap-1 text-keterangan font-semibold text-teks-sekunder">
                            <LockIcon aria-hidden="true" className="size-3.5 shrink-0" />
                            Di luar paket
                        </span>
                    ) : null}
                </span>
                <span className="text-keterangan text-teks-sekunder">{butir.keterangan}</span>
            </span>
            <ChevronRightIcon aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-teks-sekunder" />
        </>
    );
}
