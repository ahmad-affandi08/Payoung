import { Link } from '@inertiajs/react';
import { useState } from 'react';

import PanelPengirimanGrosir from '@/Komponen/Grosir/PanelPengirimanGrosir';
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
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDetailSuratJalan, PropsDetailSuratJalan } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/surat-jalan`;

const kolom: KolomTabel<BarisDetailSuratJalan>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{b.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{b.Sku ?? 'Tanpa SKU'}</span>
                {b.Rincian !== null ? (
                    <span className="text-keterangan break-words text-teks-sekunder">{b.Rincian}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Diserahkan',
        enableSorting: false,
        meta: { label: 'Jumlah diserahkan', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Jumlah, b.SimbolSatuan),
    },
    {
        id: 'Diretur',
        header: 'Diretur',
        enableSorting: false,
        meta: { label: 'Jumlah diretur', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) =>
            BandingkanDesimal(b.JumlahDiretur, '0') === 0 ? '—' : FormatJumlahStok(b.JumlahDiretur, b.SimbolSatuan),
    },
    {
        id: 'Harga',
        header: 'Harga',
        enableSorting: false,
        meta: { label: 'Harga per satuan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Harga),
    },
    {
        id: 'Subtotal',
        header: 'Subtotal',
        enableSorting: false,
        meta: { label: 'Subtotal', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Subtotal),
    },
    {
        id: 'TotalHpp',
        header: 'HPP',
        enableSorting: false,
        meta: { label: 'HPP', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.TotalHpp),
    },
];

/**
 * Detail surat jalan grosir (F-12, §9.7, BR-12.2, J-12.1). Dokumen ini tidak bisa diedit: di sinilah stok keluar dan
 * HPP, pendapatan, serta PPN keluaran diakui, jadi koreksinya lewat pembatalan yang membalik semuanya (J-12.3).
 */
export default function HalamanDetailSuratJalan({
    SuratJalan,
    Baris,
    Retur,
    Jurnal,
    Riwayat,
    RiwayatPengiriman,
    OpsiArmada,
    Izin,
    Tindakan,
}: PropsDetailSuratJalan) {
    const [batalkan, AturBatalkan] = useState(false);
    const bolehRetur =
        Izin.Kelola && SuratJalan.Status === 'Diposting' && Baris.some((b) => BandingkanDesimal(b.SisaRetur, '0') > 0);

    return (
        <TataLetakAplikasi judul={`Surat jalan ${SuratJalan.Nomor}`}>
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatusGrosir status={SuratJalan.Status} label={SuratJalan.LabelStatus} />
            </div>

            {SuratJalan.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Surat jalan dibatalkan">
                    {SuratJalan.AlasanBatal}
                </Pemberitahuan>
            ) : null}
            {SuratJalan.NomorFaktur === null && SuratJalan.Status === 'Diposting' ? (
                <Pemberitahuan jenis="peringatan" judul="Belum difakturkan">
                    Barangnya sudah diserahkan tetapi belum ditagihkan lewat faktur. Faktur gabungan dibuat paling lama
                    akhir bulan penyerahan.
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <a href={`${alamat}/${SuratJalan.Uuid}/cetak`} target="_blank" rel="noreferrer">
                        Cetak surat jalan
                    </a>
                </Button>
                {Izin.Kelola && SuratJalan.NomorFaktur === null && SuratJalan.Status === 'Diposting' ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatGrosir}/faktur/buat?surat-jalan=${SuratJalan.Uuid}`}>Buat faktur</Link>
                    </Button>
                ) : null}
                {bolehRetur ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatGrosir}/retur/buat/${SuratJalan.Uuid}`}>Buat retur</Link>
                    </Button>
                ) : null}
                {Tindakan.Batalkan ? (
                    <Button
                        variant="outline"
                        onClick={() => AturBatalkan(true)}
                        className="h-8 text-bahaya pointer-coarse:h-11"
                    >
                        Batalkan surat jalan
                    </Button>
                ) : null}
            </AksiHalaman>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{SuratJalan.NamaPelanggan}</KeteranganGrosir>
                <KeteranganGrosir label="Outlet penjual">{SuratJalan.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Diserahkan">{FormatTanggal(SuratJalan.Tanggal)}</KeteranganGrosir>
                <KeteranganGrosir label="Pesanan">
                    {SuratJalan.UuidPesanan === null ? (
                        '—'
                    ) : (
                        <Link
                            href={`${AlamatGrosir}/pesanan/${SuratJalan.UuidPesanan}`}
                            className="font-mono text-brand underline"
                        >
                            {SuratJalan.NomorPesanan}
                        </Link>
                    )}
                </KeteranganGrosir>
                <KeteranganGrosir label="Faktur">
                    {SuratJalan.UuidFaktur === null ? (
                        'Belum difakturkan'
                    ) : (
                        <Link
                            href={`${AlamatGrosir}/faktur/${SuratJalan.UuidFaktur}`}
                            className="font-mono text-brand underline"
                        >
                            {SuratJalan.NomorFaktur}
                        </Link>
                    )}
                </KeteranganGrosir>
                <KeteranganGrosir label="Tarif PPN">
                    {SuratJalan.TarifPpn === null ? 'Tanpa PPN' : FormatPersen(SuratJalan.TarifPpn)}
                </KeteranganGrosir>
                <KeteranganGrosir label="Pengirim">{SuratJalan.NamaPengirim ?? '—'}</KeteranganGrosir>
                <KeteranganGrosir label="Kendaraan">{SuratJalan.NomorKendaraan ?? '—'}</KeteranganGrosir>
                <KeteranganGrosir label="Penerima">{SuratJalan.NamaPenerima ?? '—'}</KeteranganGrosir>
            </KartuKeteranganGrosir>

            {SuratJalan.Status === 'Diposting' ? (
                <PanelPengirimanGrosir
                    suratJalan={SuratJalan}
                    opsiArmada={OpsiArmada}
                    riwayat={RiwayatPengiriman}
                    boleh={Tindakan.Pengiriman}
                />
            ) : null}

            <Panel judul="Barang yang diserahkan">
                <TabelData
                    id="grosir-surat-jalan-baris"
                    label={`Barang surat jalan ${SuratJalan.Nomor}`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Urutan)}
                    kosong={{ judul: 'Surat jalan ini tidak punya baris.' }}
                />
                <RingkasanNilaiGrosir
                    baris={[
                        { label: 'Subtotal', nilai: SuratJalan.Subtotal },
                        { label: 'Diskon', nilai: SuratJalan.Diskon },
                        { label: 'Dasar pengenaan pajak', nilai: SuratJalan.DasarPengenaanPajak },
                        { label: 'Pajak', nilai: SuratJalan.Pajak },
                        { label: 'Total', nilai: SuratJalan.Total, tebal: true },
                        { label: 'HPP diakui', nilai: SuratJalan.TotalHpp },
                    ]}
                />
            </Panel>

            {Retur.length > 0 ? (
                <section aria-label="Retur" className="flex flex-col gap-2">
                    <h2 className="text-subjudul font-semibold text-teks-utama">Retur atas surat jalan ini</h2>
                    <ul className="flex flex-col gap-1 text-isi">
                        {Retur.map((r) => (
                            <li key={r.Uuid} className="break-words text-teks-sekunder">
                                <Link
                                    href={`${AlamatGrosir}/retur/${r.Uuid}`}
                                    className="font-mono font-semibold text-brand underline"
                                >
                                    {r.Nomor}
                                </Link>{' '}
                                | {FormatTanggal(r.Tanggal)} | {r.LabelStatus} | {FormatRupiah(r.Total)}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            <JurnalDokumenGrosir jurnal={Jurnal} izin={Izin} />
            <RiwayatGrosirDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasanGrosir
                    judul="Batalkan surat jalan"
                    keterangan="Stok dikembalikan dan jurnal pengakuannya dibalik. Surat jalan yang sudah difakturkan tidak bisa dibatalkan."
                    labelAksi="Batalkan surat jalan"
                    alamat={`${alamat}/${SuratJalan.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
