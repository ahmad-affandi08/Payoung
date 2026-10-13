import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { JurnalDokumen, RiwayatDokumen, StatusPembelian } from '@/Tipe/Pembelian';

/** Akar rute back-office pembelian (F-04 fase 1). */
export const AlamatPembelian = '/kelola/pembelian';

/** Jenis label status dokumen pembelian: selesai = sukses, menunggu/sebagian = peringatan, batal = bahaya. */
export function AmbilJenisStatusPembelian(status: StatusPembelian): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    switch (status) {
        case 'Disetujui':
        case 'DijadikanPo':
        case 'Diterima':
        case 'Diposting':
        case 'Lunas':
            return 'sukses';
        case 'MenungguPersetujuan':
        case 'Diajukan':
        case 'DiterimaSebagian':
        case 'DibayarSebagian':
        case 'BelumDibayar':
            return 'peringatan';
        case 'Dibatalkan':
            return 'bahaya';
        default:
            return 'netral';
    }
}

export function LabelStatusPembelian({ status, label }: { status: StatusPembelian; label: string }) {
    return <LabelStatus jenis={AmbilJenisStatusPembelian(status)} teks={label} />;
}

/** Satu pasangan keterangan dokumen (dt/dd). */
export function Keterangan({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/** Kartu keterangan dokumen (grid responsif 1/2/3 kolom). */
export function KartuKeterangan({ children }: { children: ReactNode }) {
    return (
        <Card className="gap-0 rounded-panel p-4 shadow-none">
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{children}</dl>
        </Card>
    );
}

/** Ringkasan nilai dokumen (label kiri, Rupiah tabular rata kanan). */
export function RingkasanNilai({ baris }: { baris: { label: string; nilai: string; tebal?: boolean }[] }) {
    return (
        <dl className="ml-auto flex w-full max-w-md flex-col gap-1 border-t border-garis pt-3">
            {baris.map((b) => (
                <div key={b.label} className="flex items-baseline justify-between gap-3">
                    <dt className={b.tebal ? 'font-semibold text-teks-utama' : 'text-teks-sekunder'}>{b.label}</dt>
                    <dd
                        className={`text-right tabular-nums ${b.tebal ? 'text-subjudul font-semibold text-teks-utama' : 'text-teks-utama'}`}
                    >
                        {FormatRupiah(b.nilai)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** Daftar dokumen terkait (penerimaan, pembayaran, retur) dengan tautan & nilai. */
export function DaftarDokumenTerkait({
    judul,
    dokumen,
    alamat,
    kosong,
}: {
    judul: string;
    dokumen: { Uuid: string; Nomor: string; Tanggal: string; Status: string; LabelStatus: string; Nilai: string }[];
    alamat: string;
    kosong: string;
}) {
    return (
        <section aria-label={judul} className="flex flex-col gap-2">
            <h2 className="text-subjudul font-semibold text-teks-utama">{judul}</h2>
            {dokumen.length === 0 ? (
                <p className="text-isi text-teks-sekunder">{kosong}</p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {dokumen.map((d) => (
                        <li
                            key={d.Uuid}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis bg-permukaan px-3 py-2"
                        >
                            <span className="flex flex-wrap items-center gap-2">
                                <Link
                                    href={`${alamat}/${d.Uuid}`}
                                    className="font-mono font-semibold break-all text-brand underline"
                                >
                                    {d.Nomor}
                                </Link>
                                <span className="text-label text-teks-sekunder">{FormatTanggal(d.Tanggal)}</span>
                                <LabelStatusPembelian status={d.Status as StatusPembelian} label={d.LabelStatus} />
                            </span>
                            <span className="tabular-nums">{FormatRupiah(d.Nilai)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Jurnal otomatis dokumen (tautan ke detail jurnal bila berizin melihat laporan keuangan). */
export function DaftarJurnalDokumen({ jurnal, bolehLihat }: { jurnal: JurnalDokumen[]; bolehLihat: boolean }) {
    return (
        <section aria-label="Jurnal" className="flex flex-col gap-2">
            <h2 className="text-subjudul font-semibold text-teks-utama">Jurnal</h2>
            {jurnal.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Tidak ada jurnal untuk dokumen ini.</p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {jurnal.map((j) => (
                        <li
                            key={j.Uuid}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis bg-permukaan px-3 py-2"
                        >
                            <span className="flex flex-col">
                                {bolehLihat ? (
                                    <Link
                                        href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                                        className="font-mono font-semibold text-brand underline"
                                    >
                                        {j.Nomor}
                                    </Link>
                                ) : (
                                    <span className="font-mono font-semibold">{j.Nomor}</span>
                                )}
                                <span className="text-label break-words text-teks-sekunder">
                                    {FormatTanggal(j.Tanggal)} | {j.Pembalik ? 'Pembalik | ' : ''}
                                    {j.Keterangan}
                                </span>
                            </span>
                            <span className="tabular-nums">{FormatRupiah(j.TotalDebit)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Riwayat status dokumen (siapa, kapan, alasan). */
export function DaftarRiwayatDokumen({ riwayat }: { riwayat: RiwayatDokumen[] }) {
    if (riwayat.length === 0) {
        return null;
    }

    return (
        <section aria-label="Riwayat status" className="flex flex-col gap-2">
            <h2 className="text-subjudul font-semibold text-teks-utama">Riwayat status</h2>
            <ol className="flex flex-col gap-1 text-isi">
                {riwayat.map((r, i) => (
                    <li key={`${r.Pada}-${String(i)}`} className="break-words text-teks-sekunder">
                        <span className="font-semibold text-teks-utama">{r.StatusKe}</span> |{' '}
                        {FormatTanggalWaktu(r.Pada)}
                        {r.Oleh ? ` oleh ${r.Oleh}` : ''}
                        {r.Alasan ? ` — ${r.Alasan}` : ''}
                    </li>
                ))}
            </ol>
        </section>
    );
}

/**
 * Dialog alasan untuk tindakan berisiko (batalkan, tolak): POST `{Alasan}` ke `alamat`, tetap terbuka bila galat.
 */
export function DialogAlasan({
    judul,
    keterangan,
    labelAksi,
    alamat,
    saatTutup,
}: {
    judul: string;
    keterangan: ReactNode;
    labelAksi: string;
    alamat: string;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            alamat,
            { Alasan: alasan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );
    };

    return (
        <DialogFormulir judul={judul} keterangan={keterangan} galatUmum={props.errors.Umum} saatTutup={saatTutup}>
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label={judul}>
                <BidangTeksPanjang
                    label="Alasan"
                    nilai={alasan}
                    saatBerubah={AturAlasan}
                    galat={props.errors.Alasan}
                    maksimal={255}
                    required
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" varian="bahaya" memproses={memproses} disabled={alasan.trim().length < 5}>
                        {labelAksi}
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
