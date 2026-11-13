import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import PenandaJurnal from '@/Komponen/Akuntansi/PenandaJurnal';
import TabelBarisJurnal from '@/Komponen/Akuntansi/TabelBarisJurnal';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailJurnal } from '@/Tipe/Akuntansi';

const alamatDaftar = '/kelola/akuntansi/jurnal';

function Keterangan({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/** F-05a: detail satu jurnal (baca saja) beserta tautan dokumen sumber dan jurnal pembalik/yang dibalik. */
export default function HalamanDetailJurnal({ Jurnal, Baris, Total }: PropsDetailJurnal) {
    return (
        <TataLetakAplikasi judul={`Jurnal ${Jurnal.Nomor}`}>
            <AksiHalaman keterangan={<PenandaJurnal jurnal={Jurnal} />} langkah={[]} />

            {Jurnal.Pembalik && Jurnal.UuidJurnalDibalik ? (
                <Pemberitahuan jenis="info" judul="Jurnal pembalik">
                    Jurnal ini membalik jurnal{' '}
                    <Link
                        href={`${alamatDaftar}/${Jurnal.UuidJurnalDibalik}`}
                        className="font-mono font-semibold text-brand underline"
                    >
                        {Jurnal.NomorJurnalDibalik}
                    </Link>
                    . Debit dan kreditnya dicerminkan sehingga pengaruh jurnal asal menjadi nol.
                </Pemberitahuan>
            ) : null}
            {Jurnal.Dibalik && Jurnal.UuidPembalik ? (
                <Pemberitahuan jenis="peringatan" judul="Jurnal ini sudah dibalik">
                    Pengaruhnya sudah dinolkan oleh jurnal pembalik{' '}
                    <Link
                        href={`${alamatDaftar}/${Jurnal.UuidPembalik}`}
                        className="font-mono font-semibold text-brand underline"
                    >
                        {Jurnal.NomorPembalik}
                    </Link>
                    .
                </Pemberitahuan>
            ) : null}

            <Card className="gap-0 rounded-panel p-4 shadow-none">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Nomor">
                        <span className="font-mono">{Jurnal.Nomor}</span>
                    </Keterangan>
                    <Keterangan label="Tanggal">{FormatTanggal(Jurnal.Tanggal)}</Keterangan>
                    <Keterangan label="Periode">
                        <span className="font-mono">{Jurnal.Periode}</span>
                    </Keterangan>
                    <Keterangan label="Sumber">
                        {Jurnal.LabelJenisSumber}
                        {Jurnal.NomorSumber ? (
                            <>
                                {' | '}
                                {Jurnal.TautanSumber ? (
                                    <Link
                                        href={Jurnal.TautanSumber}
                                        className="font-mono break-all text-brand underline"
                                    >
                                        {Jurnal.NomorSumber}
                                    </Link>
                                ) : (
                                    <span className="font-mono break-all">{Jurnal.NomorSumber}</span>
                                )}
                            </>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Nilai">
                        <span className="tabular-nums">{FormatRupiah(Jurnal.TotalDebit)}</span>
                    </Keterangan>
                    <Keterangan label="Dibuat">
                        {Jurnal.Otomatis ? 'Otomatis' : 'Manual'}
                        {Jurnal.DibuatOleh ? ` oleh ${Jurnal.DibuatOleh}` : ''} |{' '}
                        {FormatTanggalWaktu(Jurnal.DibuatPada === '' ? null : Jurnal.DibuatPada)}
                    </Keterangan>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <Keterangan label="Keterangan">{Jurnal.Keterangan}</Keterangan>
                    </div>
                </dl>
            </Card>

            <TabelBarisJurnal baris={Baris} total={Total} nomor={Jurnal.Nomor} />
        </TataLetakAplikasi>
    );
}
