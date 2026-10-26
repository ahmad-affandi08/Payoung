import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Badge } from '@/Komponen/Ui/badge';
import { Button } from '@/Komponen/Ui/button';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailTransaksiKasBank } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamatDaftar = '/kelola/akuntansi/kas-bank';

function Keterangan({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/** F-13a: detail transaksi kas & bank, jurnalnya, lampiran, dan pembuatan dokumen pembalik (koreksi). */
export default function HalamanDetailTransaksiKasBank({ Transaksi: t, Jurnal, Izin }: PropsDetailTransaksiKasBank) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [balik, AturBalik] = useState<{ Tanggal: string; Alasan: string } | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const bolehDibalik = Izin.Kelola && !t.Pembalik && !t.Dibalik;

    const Balikkan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (balik === null) {
            return;
        }

        router.post(`${alamatDaftar}/${t.Uuid}/pembalik`, balik, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturBalik(null),
        });
    };

    return (
        <TataLetakAplikasi judul={`${t.LabelJenis} ${t.Nomor}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button asChild variant="link" className="h-auto px-0">
                    <Link href={alamatDaftar}>Kembali ke kas & bank</Link>
                </Button>
                {bolehDibalik ? (
                    <Button
                        variant="outline"
                        onClick={() => AturBalik({ Tanggal: TulisTanggal(new Date()), Alasan: '' })}
                    >
                        Buat dokumen pembalik
                    </Button>
                ) : null}
            </div>

            {t.Pembalik && t.UuidDibalik ? (
                <Pemberitahuan jenis="info" judul="Dokumen pembalik">
                    Dokumen ini membatalkan{' '}
                    <Link
                        href={`${alamatDaftar}/${t.UuidDibalik}`}
                        className="font-mono font-semibold text-brand underline"
                    >
                        {t.NomorDibalik}
                    </Link>
                    . Jurnalnya mencerminkan jurnal asal sehingga pengaruhnya menjadi nol.
                </Pemberitahuan>
            ) : null}
            {t.Dibalik && t.UuidPembalik ? (
                <Pemberitahuan jenis="peringatan" judul="Transaksi ini sudah dibalik">
                    Pengaruhnya sudah dinolkan oleh{' '}
                    <Link
                        href={`${alamatDaftar}/${t.UuidPembalik}`}
                        className="font-mono font-semibold text-brand underline"
                    >
                        {t.NomorPembalik}
                    </Link>
                    .
                </Pemberitahuan>
            ) : null}

            <Card className="gap-0 rounded-panel p-4 shadow-none">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Nomor">
                        <span className="font-mono">{t.Nomor}</span>
                    </Keterangan>
                    <Keterangan label="Tanggal">{FormatTanggal(t.Tanggal)}</Keterangan>
                    <Keterangan label="Jenis">
                        <span className="flex flex-wrap items-center gap-1.5">
                            {t.LabelJenis}
                            {t.Pembalik ? <Badge variant="outline">Pembalik</Badge> : null}
                        </span>
                    </Keterangan>
                    <Keterangan label="Akun asal (kredit)">{t.AkunSumber}</Keterangan>
                    <Keterangan label="Akun tujuan (debit)">{t.AkunTujuan}</Keterangan>
                    <Keterangan label="Jumlah">
                        <span className="font-semibold tabular-nums">{FormatRupiah(t.Jumlah)}</span>
                    </Keterangan>
                    <Keterangan label="Outlet">{t.NamaOutlet ?? 'Tingkat usaha'}</Keterangan>
                    <Keterangan label="Dicatat">
                        {FormatTanggalWaktu(t.DibuatPada)}
                        {t.DibuatOleh ? ` oleh ${t.DibuatOleh}` : ''}
                    </Keterangan>
                    <Keterangan label="Lampiran">
                        {t.Lampiran ? (
                            <a href={`${alamatDaftar}/${t.Uuid}/lampiran`} className="break-all text-brand underline">
                                {t.Lampiran.Nama} ({FormatUkuranBerkas(t.Lampiran.Ukuran)})
                            </a>
                        ) : (
                            'Tidak ada'
                        )}
                    </Keterangan>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <Keterangan label="Keterangan">{t.Keterangan}</Keterangan>
                    </div>
                </dl>
            </Card>

            <section aria-labelledby="judul-jurnal" className="flex flex-col gap-2">
                <h2 id="judul-jurnal" className="text-subjudul font-semibold text-teks-utama">
                    Jurnal
                </h2>
                {Jurnal.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada jurnal untuk transaksi ini.</p>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {Jurnal.map((j) => (
                            <li
                                key={j.Uuid}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis bg-permukaan px-3 py-2"
                            >
                                <span className="flex flex-col">
                                    <Link
                                        href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                                        className="font-mono font-semibold text-brand underline"
                                    >
                                        {j.Nomor}
                                    </Link>
                                    <span className="text-label break-words text-teks-sekunder">
                                        {FormatTanggal(j.Tanggal)} | {j.Keterangan}
                                    </span>
                                </span>
                                <span className="tabular-nums">{FormatRupiah(j.TotalDebit)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {balik !== null ? (
                <DialogFormulir
                    judul={`Buat dokumen pembalik ${t.Nomor}`}
                    keterangan="Dokumen pembalik membatalkan pengaruh transaksi ini dengan jurnal kebalikannya. Transaksi asal tetap tersimpan."
                    galatUmum={galat.Umum}
                    saatTutup={() => AturBalik(null)}
                >
                    <form
                        onSubmit={Balikkan}
                        className="flex flex-col gap-4"
                        aria-label="Formulir dokumen pembalik"
                        noValidate
                    >
                        <PemilihTanggal
                            label="Tanggal pembalik"
                            nilai={balik.Tanggal}
                            min={t.Tanggal}
                            saatBerubah={(nilai) => AturBalik({ ...balik, Tanggal: nilai })}
                            galat={galat.Tanggal}
                            required
                        />
                        <BidangTeksPanjang
                            label="Alasan koreksi"
                            nilai={balik.Alasan}
                            saatBerubah={(nilai) => AturBalik({ ...balik, Alasan: nilai })}
                            galat={galat.Alasan}
                            maksimal={200}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturBalik(null)}>
                                Batal
                            </Tombol>
                            <Tombol type="submit" varian="bahaya" memproses={memproses}>
                                Buat pembalik
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
