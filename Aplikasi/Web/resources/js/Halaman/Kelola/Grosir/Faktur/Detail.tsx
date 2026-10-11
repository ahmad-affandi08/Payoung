import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import {
    AlamatGrosir,
    DialogAlasanGrosir,
    JurnalDokumenGrosir,
    KartuKeteranganGrosir,
    KeteranganGrosir,
    LabelStatusGrosir,
    RingkasanNilaiGrosir,
    RiwayatGrosirDokumen,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDetailFaktur } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/faktur`;

/**
 * Detail faktur penjualan grosir (F-12, §9.7, BR-12.4, J-12.2). Fakturnya hanya menagih: pendapatan, HPP, dan PPN sudah
 * diakui di surat jalannya, jadi di sini yang bergerak hanya piutang. Sisa tagihan dibaca dari piutang (BR-12.5).
 */
export default function HalamanDetailFakturGrosir({
    Faktur,
    SuratJalan,
    Jurnal,
    Riwayat,
    Izin,
    Tindakan,
}: PropsDetailFaktur) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [batalkan, AturBatalkan] = useState(false);
    const [nomorPajak, AturNomorPajak] = useState(Faktur.NomorFakturPajak ?? '');
    const [memproses, AturMemproses] = useState(false);

    const SimpanNomorPajak = () =>
        router.put(
            `${alamat}/${Faktur.Uuid}/nomor-pajak`,
            { NomorFakturPajak: nomorPajak },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );

    return (
        <TataLetakAplikasi judul={`Faktur ${Faktur.Nomor}`}>
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatusGrosir status={Faktur.Status} label={Faktur.LabelStatus} />
            </div>

            {Faktur.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Faktur dibatalkan">
                    {Faktur.AlasanBatal} — surat jalannya dilepas dan bisa difakturkan ulang.
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <a href={`${alamat}/${Faktur.Uuid}/cetak`} target="_blank" rel="noreferrer">
                        Cetak faktur
                    </a>
                </Button>
                {Tindakan.Batalkan ? (
                    <Button
                        variant="outline"
                        onClick={() => AturBatalkan(true)}
                        className="h-8 text-bahaya pointer-coarse:h-11"
                    >
                        Batalkan faktur
                    </Button>
                ) : null}
            </AksiHalaman>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{Faktur.NamaPelanggan}</KeteranganGrosir>
                <KeteranganGrosir label="Outlet penjual">{Faktur.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Tanggal faktur">{FormatTanggal(Faktur.Tanggal)}</KeteranganGrosir>
                <KeteranganGrosir label="Jatuh tempo">
                    {FormatTanggal(Faktur.JatuhTempo)} ({Faktur.TerminHari} hari)
                </KeteranganGrosir>
                <KeteranganGrosir label="Masa penyerahan">{Faktur.PeriodePenyerahan}</KeteranganGrosir>
                <KeteranganGrosir label="Tarif PPN">
                    {Faktur.TarifPpn === null ? 'Tanpa PPN' : FormatPersen(Faktur.TarifPpn)}
                </KeteranganGrosir>
                <KeteranganGrosir label="Sisa tagihan">
                    {Faktur.SisaPiutang === null
                        ? '—'
                        : `${FormatRupiah(Faktur.SisaPiutang)}${Faktur.LabelStatusPiutang ? ` | ${Faktur.LabelStatusPiutang}` : ''}`}
                </KeteranganGrosir>
                {Faktur.Catatan !== null ? <KeteranganGrosir label="Catatan">{Faktur.Catatan}</KeteranganGrosir> : null}
            </KartuKeteranganGrosir>

            <Panel
                judul="Nomor Faktur Pajak"
                keterangan="Nomor dari e-Faktur/Coretax. Boleh diisi setelah faktur diterbitkan."
            >
                {Tindakan.UbahNomorPajak ? (
                    <div className="flex flex-col gap-3 sm:max-w-md">
                        <BidangTeks
                            label="Nomor Faktur Pajak"
                            nilai={nomorPajak}
                            saatBerubah={AturNomorPajak}
                            galat={props.errors.NomorFakturPajak}
                            maxLength={30}
                            kode
                        />
                        <Tombol onClick={SimpanNomorPajak} memproses={memproses} varian="sekunder">
                            Simpan nomor
                        </Tombol>
                    </div>
                ) : (
                    <p className="font-mono break-all text-isi text-teks-utama">{Faktur.NomorFakturPajak ?? '—'}</p>
                )}
            </Panel>

            <section aria-label="Surat jalan yang ditagihkan" className="flex flex-col gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Surat jalan yang ditagihkan</h2>
                <ul className="flex flex-col gap-1 text-isi">
                    {SuratJalan.map((sj) => (
                        <li key={sj.Uuid} className="break-words text-teks-sekunder">
                            <Link
                                href={`${AlamatGrosir}/surat-jalan/${sj.Uuid}`}
                                className="font-mono font-semibold text-brand underline"
                            >
                                {sj.Nomor}
                            </Link>{' '}
                            | {FormatTanggal(sj.Tanggal)} | {FormatRupiah(sj.Total)}
                        </li>
                    ))}
                </ul>
                <RingkasanNilaiGrosir
                    baris={[
                        { label: 'Subtotal', nilai: Faktur.Subtotal },
                        { label: 'Diskon', nilai: Faktur.Diskon },
                        { label: 'Dasar pengenaan pajak', nilai: Faktur.DasarPengenaanPajak },
                        { label: 'Pajak', nilai: Faktur.Pajak },
                        { label: 'Total tagihan', nilai: Faktur.Total, tebal: true },
                    ]}
                />
            </section>

            <JurnalDokumenGrosir jurnal={Jurnal} izin={Izin} />
            <RiwayatGrosirDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasanGrosir
                    judul="Batalkan faktur penjualan"
                    keterangan="Piutangnya dibatalkan dan surat jalannya dilepas untuk difakturkan ulang. Faktur yang piutangnya sudah dibayar sebagian tidak bisa dibatalkan."
                    labelAksi="Batalkan faktur"
                    alamat={`${alamat}/${Faktur.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
