import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

import TabelBarisJurnal from '@/Komponen/Akuntansi/TabelBarisJurnal';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Badge } from '@/Komponen/Ui/badge';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailJurnalUmum } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/jurnal-umum';

function Keterangan({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/** FIN-05: detail jurnal umum manual, barisnya, lampiran, dan pembuatan dokumen pembalik (koreksi). */
export default function HalamanDetailJurnalUmum({ JurnalUmum: j, Jurnal, Baris, Total, Izin }: PropsDetailJurnalUmum) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [balik, AturBalik] = useState<{ Tanggal: string; Alasan: string } | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const bolehDibalik = Izin.Kelola && !j.Pembalik && !j.Dibalik;

    const Balikkan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (balik === null) {
            return;
        }

        router.post(`${alamat}/${j.Uuid}/pembalik`, balik, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturBalik(null),
        });
    };

    return (
        <TataLetakAplikasi
            judul={`${j.LabelJenis} ${j.Nomor}`}
            jejak={[{ label: 'Jurnal', href: '/kelola/akuntansi/jurnal' }]}
        >
            {bolehDibalik ? (
                <div className="flex flex-wrap items-center">
                    <Tombol
                        varian="sekunder"
                        onClick={() => AturBalik({ Tanggal: TulisTanggal(new Date()), Alasan: '' })}
                    >
                        Buat dokumen pembalik
                    </Tombol>
                </div>
            ) : null}

            {j.Pembalik && j.UuidDibalik ? (
                <Pemberitahuan jenis="info" judul="Dokumen pembalik">
                    Dokumen ini membatalkan{' '}
                    <Link href={`${alamat}/${j.UuidDibalik}`} className="font-mono font-semibold text-brand underline">
                        {j.NomorDibalik}
                    </Link>
                    ; jurnalnya mencerminkan jurnal asal sehingga pengaruhnya menjadi nol.
                </Pemberitahuan>
            ) : null}
            {j.Dibalik && j.UuidPembalik ? (
                <Pemberitahuan jenis="peringatan" judul="Jurnal umum ini sudah dibalik">
                    Pengaruhnya sudah dinolkan oleh{' '}
                    <Link href={`${alamat}/${j.UuidPembalik}`} className="font-mono font-semibold text-brand underline">
                        {j.NomorPembalik}
                    </Link>
                    .
                </Pemberitahuan>
            ) : null}

            <Card className="gap-0 rounded-panel p-4 shadow-none">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Nomor">
                        <span className="font-mono">{j.Nomor}</span>
                    </Keterangan>
                    <Keterangan label="Tanggal">{FormatTanggal(j.Tanggal)}</Keterangan>
                    <Keterangan label="Jenis">
                        <span className="flex flex-wrap items-center gap-1.5">
                            {j.LabelJenis}
                            {j.Pembalik ? <Badge variant="outline">Pembalik</Badge> : null}
                        </span>
                    </Keterangan>
                    <Keterangan label="Nilai">
                        <span className="font-semibold tabular-nums">{FormatRupiah(j.TotalNilai)}</span>
                    </Keterangan>
                    <Keterangan label="Referensi">{j.Referensi ?? '-'}</Keterangan>
                    <Keterangan label="Jurnal">
                        <Link
                            href={`/kelola/akuntansi/jurnal/${Jurnal.Uuid}`}
                            className="font-mono text-brand underline"
                        >
                            {Jurnal.Nomor}
                        </Link>
                    </Keterangan>
                    <Keterangan label="Dicatat">
                        {FormatTanggalWaktu(j.DibuatPada)}
                        {j.DibuatOleh ? ` oleh ${j.DibuatOleh}` : ''}
                    </Keterangan>
                    <Keterangan label="Lampiran">
                        {j.Lampiran ? (
                            <a href={`${alamat}/${j.Uuid}/lampiran`} className="break-all text-brand underline">
                                {j.Lampiran.Nama} ({FormatUkuranBerkas(j.Lampiran.Ukuran)})
                            </a>
                        ) : (
                            'Tidak ada'
                        )}
                    </Keterangan>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <Keterangan label="Keterangan">{j.Keterangan}</Keterangan>
                    </div>
                </dl>
            </Card>

            <section aria-labelledby="judul-baris-jurnal-umum" className="flex flex-col gap-2">
                <h2 id="judul-baris-jurnal-umum" className="text-subjudul font-semibold text-teks-utama">
                    Baris jurnal
                </h2>
                <TabelBarisJurnal baris={Baris} total={Total} nomor={Jurnal.Nomor} />
            </section>

            {balik !== null ? (
                <DialogFormulir
                    judul={`Buat dokumen pembalik ${j.Nomor}`}
                    keterangan="Dokumen pembalik membatalkan pengaruh jurnal ini dengan jurnal kebalikannya. Jurnal asal tetap tersimpan. Pembalik akrual biasanya diberi tanggal awal bulan berikutnya."
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
                            min={j.Tanggal}
                            max={TulisTanggal(new Date())}
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
