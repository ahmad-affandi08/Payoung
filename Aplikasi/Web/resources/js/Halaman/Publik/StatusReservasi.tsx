import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarClockIcon, CheckIcon, MapPinIcon, UserRoundIcon, XCircleIcon } from 'lucide-react';
import { useState } from 'react';

import { FormatWaktuReservasi } from '@/Halaman/Kelola/Reservasi/Daftar';
import Tombol from '@/Komponen/Formulir/Tombol';
import KerangkaMerek from '@/Komponen/Publik/KerangkaMerek';
import { PanelHasil } from '@/Komponen/Publik/KerangkaPelanggan';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { StatusReservasi } from '@/Tipe/Reservasi';

type PropsStatusReservasi = {
    Ditemukan: boolean;
    Slug: string;
    Kode: string;
    Toko?: { Nama: string; UrlLogo?: string | null };
    Reservasi?: {
        Nomor: string;
        MulaiPada: string;
        SelesaiPada: string;
        Layanan: string;
        Staf: string | null;
        Outlet: string;
        NamaPelanggan: string;
        Status: StatusReservasi;
        LabelStatus: string;
        BolehBatal: boolean;
    };
};

const JenisStatus: Record<StatusReservasi, 'netral' | 'sukses' | 'peringatan' | 'bahaya'> = {
    Menunggu: 'peringatan',
    Dikonfirmasi: 'sukses',
    Hadir: 'sukses',
    Selesai: 'sukses',
    Batal: 'bahaya',
    TidakDatang: 'bahaya',
};

const LANGKAH_RESERVASI = ['Dikirim', 'Dikonfirmasi', 'Hadir', 'Selesai'] as const;
const INDEKS_LANGKAH: Record<StatusReservasi, number> = {
    Menunggu: 0,
    Dikonfirmasi: 1,
    Hadir: 2,
    Selesai: 3,
    Batal: -1,
    TidakDatang: -1,
};

/** F-07 mode service: status reservasi pelanggan (dari tautan setelah memesan) dengan tombol batal. Tampilan D-240. */
export default function HalamanStatusReservasi({ Ditemukan, Slug, Kode, Toko, Reservasi: r }: PropsStatusReservasi) {
    const { props } = usePage<{ Kilat?: string | null; errors: Record<string, string> }>();
    const [memproses, AturMemproses] = useState(false);

    if (!Ditemukan || !r) {
        return (
            <KerangkaMerek toko={Toko} label="Reservasi" judul="Reservasi" skala="situs">
                <Head title="Status reservasi" />
                <PanelHasil jenis="netral" judulBagian judul="Reservasi tidak ditemukan">
                    Periksa kembali tautan dari toko.
                </PanelHasil>
            </KerangkaMerek>
        );
    }

    const gagal = INDEKS_LANGKAH[r.Status] < 0;
    const sekarang = INDEKS_LANGKAH[r.Status];

    return (
        <KerangkaMerek toko={Toko} label="Status reservasi" judul={`Reservasi ${r.Nomor}`} skala="situs">
            <Head title="Status reservasi" />
            {props.Kilat ? <Pemberitahuan jenis="sukses">{props.Kilat}</Pemberitahuan> : null}
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            {gagal ? (
                <div className="flex items-center gap-3 rounded-3xl border border-bahaya bg-bahaya-lembut p-4 text-bahaya">
                    <XCircleIcon aria-hidden="true" className="size-6 shrink-0" />
                    <p className="text-isi font-semibold">{r.LabelStatus}</p>
                </div>
            ) : (
                <ol aria-label="Perkembangan reservasi" className="grid grid-cols-4 gap-1 rounded-3xl bg-permukaan p-4">
                    {LANGKAH_RESERVASI.map((nama, indeks) => {
                        const lewat = indeks < sekarang || r.Status === 'Selesai';
                        const aktif = indeks === sekarang && r.Status !== 'Selesai';

                        return (
                            <li
                                key={nama}
                                aria-current={aktif ? 'step' : undefined}
                                className="flex flex-col items-center gap-1.5 text-center"
                            >
                                <span
                                    className={`grid size-8 place-items-center rounded-full text-keterangan font-semibold ${
                                        lewat
                                            ? 'bg-sukses text-permukaan'
                                            : aktif
                                              ? 'bg-brand-gelap text-permukaan ring-4 ring-brand-lembut'
                                              : 'bg-permukaan-sorot text-teks-sekunder'
                                    }`}
                                >
                                    {lewat ? <CheckIcon aria-hidden="true" className="size-4" /> : indeks + 1}
                                </span>
                                <span
                                    className={`text-keterangan ${aktif ? 'font-bold text-teks-utama' : 'text-teks-sekunder'}`}
                                >
                                    {nama}
                                </span>
                            </li>
                        );
                    })}
                </ol>
            )}
            <section className="flex flex-col gap-4 rounded-3xl bg-permukaan p-5">
                <div className="flex flex-wrap items-center gap-2">
                    <LabelStatus jenis={JenisStatus[r.Status]} teks={r.LabelStatus} />
                </div>
                <div className="flex items-start gap-3">
                    <CalendarClockIcon aria-hidden="true" className="mt-1 size-6 shrink-0 text-brand" />
                    <p className="text-subjudul font-extrabold text-teks-utama tabular-nums">
                        {FormatWaktuReservasi(r.MulaiPada, r.SelesaiPada)}
                    </p>
                </div>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 border-t border-garis pt-4">
                    <dt className="text-teks-sekunder">Layanan</dt>
                    <dd className="font-semibold break-words">{r.Layanan}</dd>
                    <dt className="inline-flex items-center gap-1.5 text-teks-sekunder">
                        <UserRoundIcon aria-hidden="true" className="size-4" />
                        Staf
                    </dt>
                    <dd>{r.Staf ?? 'Ditentukan toko'}</dd>
                    <dt className="inline-flex items-center gap-1.5 text-teks-sekunder">
                        <MapPinIcon aria-hidden="true" className="size-4" />
                        Outlet
                    </dt>
                    <dd className="break-words">{r.Outlet}</dd>
                    <dt className="text-teks-sekunder">Atas nama</dt>
                    <dd className="break-words">{r.NamaPelanggan}</dd>
                </dl>
            </section>
            <p className="text-keterangan text-teks-sekunder">
                Simpan tautan ini untuk melihat atau membatalkan reservasi. Kode:{' '}
                <span className="font-mono">{Kode}</span>
            </p>
            {r.BolehBatal ? (
                <Tombol
                    varian="bahaya"
                    ukuran="besar"
                    bentuk="pil"
                    memproses={memproses}
                    onClick={() =>
                        router.post(
                            `/${Slug}/reservasi/${Kode}/batal`,
                            {},
                            { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
                        )
                    }
                >
                    Batalkan reservasi
                </Tombol>
            ) : null}
            <Link href={`/${Slug}/reservasi`} className="text-label font-semibold text-brand hover:underline">
                Buat reservasi lagi
            </Link>
        </KerangkaMerek>
    );
}
