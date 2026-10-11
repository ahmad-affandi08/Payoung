import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDetailPencairan } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/pencairan';

type BarisDetail = PropsDetailPencairan['Baris'][number];

const kolom: KolomTabel<BarisDetail>[] = [
    {
        id: 'NomorPenjualan',
        accessorKey: 'NomorPenjualan',
        header: 'Penjualan',
        meta: { label: 'Penjualan', prioritas: 'utama', wajib: true },
        cell: ({ row }) => <span className="font-mono break-all">{row.original.NomorPenjualan}</span>,
    },
    {
        id: 'TanggalPenjualan',
        header: 'Tanggal',
        enableSorting: false,
        meta: { label: 'Tanggal penjualan', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalPenjualan),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'utama' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
    {
        id: 'RefEksternal',
        header: 'Referensi',
        enableSorting: false,
        meta: { label: 'Referensi pembayaran', prioritas: 'rendah' },
        cell: ({ row }) => <span className="break-words">{row.original.RefEksternal ?? '—'}</span>,
    },
];

function Keterangan({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/**
 * Detail pencairan (F-08, BR-08.4, J-08.1). Dokumennya tidak bisa diedit: koreksinya lewat pembatalan yang menarik
 * kembali setorannya dari buku dan melepas pembayarannya supaya bisa dicairkan ulang di dokumen yang benar.
 */
export default function HalamanDetailPencairan({ Pencairan, Baris, Jurnal, Riwayat, Tindakan }: PropsDetailPencairan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [batalkan, AturBatalkan] = useState(false);
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const selisih = BandingkanDesimal(Pencairan.SelisihBiaya, '0');
    const kelebihan = BandingkanDesimal(Pencairan.Biaya, '0') < 0;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${alamat}/${Pencairan.Uuid}/batalkan`,
            { Alasan: alasan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturBatalkan(false),
            },
        );
    };

    return (
        <TataLetakAplikasi judul={`Pencairan ${Pencairan.Nomor}`}>
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatus
                    jenis={Pencairan.Status === 'Diposting' ? 'sukses' : 'bahaya'}
                    teks={Pencairan.LabelStatus}
                />
            </div>

            {Pencairan.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Pencairan dibatalkan">
                    {Pencairan.AlasanBatal} — pembayarannya dilepas dan bisa dicairkan ulang.
                </Pemberitahuan>
            ) : null}
            {selisih !== 0 && Pencairan.Status === 'Diposting' ? (
                <Pemberitahuan jenis="peringatan" judul="Potongan menyimpang dari pengaturan metode">
                    Perkiraan potongan {FormatRupiah(Pencairan.BiayaDiharapkan)}, yang benar-benar terjadi{' '}
                    {FormatRupiah(Pencairan.Biaya)}. Selisih {FormatRupiah(Pencairan.SelisihBiaya.replace('-', ''))}{' '}
                    {selisih > 0 ? 'lebih besar' : 'lebih kecil'} daripada perkiraan — cocokkan dengan laporan
                    settlement platform sebelum dianggap beres.
                </Pemberitahuan>
            ) : null}
            {kelebihan ? (
                <Pemberitahuan jenis="info" judul="Setoran lebih besar daripada nilai transaksi">
                    Kelebihannya dibukukan sebagai Pendapatan Lain, bukan sebagai biaya bernilai negatif, supaya
                    terlihat di laba-rugi dan bisa ditanyakan ke platform.
                </Pemberitahuan>
            ) : null}

            {Tindakan.Batalkan ? (
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        onClick={() => AturBatalkan(true)}
                        className="h-8 text-bahaya pointer-coarse:h-11"
                    >
                        Batalkan pencairan
                    </Button>
                </div>
            ) : null}

            <Card className="gap-0 rounded-panel p-4 shadow-none">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Metode pembayaran">{Pencairan.NamaMetode}</Keterangan>
                    <Keterangan label="Outlet">{Pencairan.KodeOutlet}</Keterangan>
                    <Keterangan label="Masuk rekening">{FormatTanggal(Pencairan.Tanggal)}</Keterangan>
                    <Keterangan label="Akun penerima">{Pencairan.AkunTujuan}</Keterangan>
                    <Keterangan label="Akun kliring">
                        {Pencairan.AkunKliring ?? 'Piutang Pencairan (dari pemetaan akun)'}
                    </Keterangan>
                    <Keterangan label="Referensi setoran">{Pencairan.Referensi ?? '—'}</Keterangan>
                    <Keterangan label="Catatan">{Pencairan.Catatan ?? '—'}</Keterangan>
                </dl>
            </Card>

            <Panel judul="Pembayaran yang dicairkan">
                <TabelData
                    id="akuntansi-pencairan-baris"
                    label={`Pembayaran pencairan ${Pencairan.Nomor}`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Urutan)}
                    kosong={{ judul: 'Pencairan ini tidak punya baris.' }}
                />
                <dl className="ml-auto flex w-full max-w-md flex-col gap-1 border-t border-garis pt-3">
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-teks-sekunder">Nilai transaksi</dt>
                        <dd className="text-right tabular-nums">{FormatRupiah(Pencairan.JumlahKotor)}</dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-teks-sekunder">{kelebihan ? 'Kelebihan setor' : 'Potongan platform'}</dt>
                        <dd className="text-right tabular-nums">{FormatRupiah(Pencairan.Biaya.replace('-', ''))}</dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3 border-t border-garis pt-1">
                        <dt className="font-semibold text-teks-utama">Masuk rekening</dt>
                        <dd className="text-subjudul text-right font-semibold tabular-nums">
                            {FormatRupiah(Pencairan.JumlahBersih)}
                        </dd>
                    </div>
                </dl>
            </Panel>

            {Jurnal.length > 0 ? (
                <section aria-label="Jurnal" className="flex flex-col gap-2">
                    <h2 className="text-subjudul font-semibold text-teks-utama">Jurnal</h2>
                    <ul className="flex flex-col gap-1 text-isi">
                        {Jurnal.map((j) => (
                            <li key={j.Uuid} className="break-words text-teks-sekunder">
                                <Link
                                    href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                                    className="font-mono font-semibold text-brand underline"
                                >
                                    {j.Nomor}
                                </Link>{' '}
                                | {FormatTanggal(j.Tanggal)} | {j.KunciSumber} | {FormatRupiah(j.TotalDebit)}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {Riwayat.length > 0 ? (
                <section aria-label="Riwayat status" className="flex flex-col gap-2">
                    <h2 className="text-subjudul font-semibold text-teks-utama">Riwayat status</h2>
                    <ol className="flex flex-col gap-1 text-isi">
                        {Riwayat.map((r, i) => (
                            <li key={`${r.Pada}-${String(i)}`} className="break-words text-teks-sekunder">
                                <span className="font-semibold text-teks-utama">{r.StatusKe}</span> |{' '}
                                {FormatTanggalWaktu(r.Pada)}
                                {r.Oleh ? ` oleh ${r.Oleh}` : ''}
                                {r.Alasan ? ` — ${r.Alasan}` : ''}
                            </li>
                        ))}
                    </ol>
                </section>
            ) : null}

            {batalkan ? (
                <DialogFormulir
                    judul="Batalkan pencairan"
                    keterangan="Setorannya ditarik kembali dari akun kas/bank di buku, potongannya dicabut dari beban, dan pembayarannya dilepas supaya bisa dicairkan ulang di dokumen yang benar."
                    galatUmum={props.errors.Umum}
                    saatTutup={() => AturBatalkan(false)}
                >
                    <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Batalkan pencairan">
                        <BidangTeksPanjang
                            label="Alasan"
                            nilai={alasan}
                            saatBerubah={AturAlasan}
                            galat={props.errors.Alasan}
                            maksimal={255}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturBatalkan(false)}>
                                Batal
                            </Tombol>
                            <Tombol
                                type="submit"
                                varian="bahaya"
                                memproses={memproses}
                                disabled={alasan.trim().length < 5}
                            >
                                Batalkan pencairan
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
