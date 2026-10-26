import { Link } from '@inertiajs/react';
import { useState } from 'react';

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
import type { BarisDetailRetur, PropsDetailRetur } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/retur`;

const kolom: KolomTabel<BarisDetailRetur>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{b.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{b.Sku ?? 'Tanpa SKU'}</span>
            </span>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Diretur',
        enableSorting: false,
        meta: { label: 'Jumlah diretur', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Jumlah, b.SimbolSatuan),
    },
    {
        id: 'Kondisi',
        header: 'Kondisi',
        enableSorting: false,
        meta: { label: 'Kondisi barang', prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span>{b.LabelKondisi}</span>
                <span className="text-keterangan text-teks-sekunder break-words">{b.NamaGudang}</span>
            </span>
        ),
    },
    {
        id: 'Harga',
        header: 'Harga',
        enableSorting: false,
        meta: { label: 'Harga per satuan', angka: true, prioritas: 'rendah' },
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
 * Detail retur grosir (F-12, §9.7, BR-12.7, J-12.4). Seperti dokumen tercatat lainnya, retur tidak bisa diedit:
 * koreksinya lewat pembatalan yang mengeluarkan barangnya kembali dan mencabut nota kreditnya.
 */
export default function HalamanDetailReturGrosir({ Retur, Baris, Jurnal, Riwayat, Izin, Tindakan }: PropsDetailRetur) {
    const [batalkan, AturBatalkan] = useState(false);

    return (
        <TataLetakAplikasi judul={`Retur ${Retur.Nomor}`}>
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatusGrosir status={Retur.Status} label={Retur.LabelStatus} />
            </div>

            {Retur.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Retur dibatalkan">
                    {Retur.AlasanBatal}
                </Pemberitahuan>
            ) : null}
            {Retur.PerluTinjauan && Retur.Status === 'Diposting' ? (
                <Pemberitahuan jenis="peringatan" judul="Perlu ditinjau">
                    {Retur.AlasanTinjauan ?? 'Retur ini perlu diperiksa manual sebelum dianggap beres.'}
                </Pemberitahuan>
            ) : null}
            {Retur.Status === 'Diposting' ? (
                <Pemberitahuan jenis="info" judul={Retur.MengurangiPiutang ? 'Nota kredit' : 'Belum difakturkan'}>
                    {Retur.MengurangiPiutang
                        ? 'Nilai retur ini sudah mengurangi piutang fakturnya, jadi yang ditagihkan ke pembeli tinggal sisanya.'
                        : 'Penyerahannya belum difakturkan, jadi retur ini mengurangi akun Piutang Belum Difakturkan. Faktur nanti hanya menagihkan sisanya.'}
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <a href={`${alamat}/${Retur.Uuid}/cetak`} target="_blank" rel="noreferrer">
                        {Retur.MengurangiPiutang ? 'Cetak nota kredit' : 'Cetak tanda terima retur'}
                    </a>
                </Button>
                {Tindakan.Batalkan ? (
                    <Button
                        variant="outline"
                        onClick={() => AturBatalkan(true)}
                        className="h-8 text-bahaya pointer-coarse:h-11"
                    >
                        Batalkan retur
                    </Button>
                ) : null}
            </AksiHalaman>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{Retur.NamaPelanggan}</KeteranganGrosir>
                <KeteranganGrosir label="Outlet penjual">{Retur.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Tanggal retur">{FormatTanggal(Retur.Tanggal)}</KeteranganGrosir>
                <KeteranganGrosir label="Surat jalan">
                    {Retur.UuidSuratJalan === null ? (
                        '—'
                    ) : (
                        <Link
                            href={`${AlamatGrosir}/surat-jalan/${Retur.UuidSuratJalan}`}
                            className="font-mono text-brand underline"
                        >
                            {Retur.NomorSuratJalan}
                        </Link>
                    )}
                </KeteranganGrosir>
                <KeteranganGrosir label="Faktur">
                    {Retur.UuidFaktur === null ? (
                        'Belum difakturkan'
                    ) : (
                        <Link
                            href={`${AlamatGrosir}/faktur/${Retur.UuidFaktur}`}
                            className="font-mono text-brand underline"
                        >
                            {Retur.NomorFaktur}
                        </Link>
                    )}
                </KeteranganGrosir>
                <KeteranganGrosir label="Tarif PPN">
                    {Retur.TarifPpn === null ? 'Tanpa PPN' : FormatPersen(Retur.TarifPpn)}
                </KeteranganGrosir>
                <KeteranganGrosir label="Alasan">{Retur.Alasan}</KeteranganGrosir>
                <KeteranganGrosir label="Catatan">{Retur.Catatan ?? '—'}</KeteranganGrosir>
            </KartuKeteranganGrosir>

            <Panel judul="Barang yang dikembalikan">
                <TabelData
                    id="grosir-retur-baris"
                    label={`Barang retur ${Retur.Nomor}`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Urutan)}
                    kosong={{ judul: 'Retur ini tidak punya baris.' }}
                />
                <RingkasanNilaiGrosir
                    baris={[
                        { label: 'Subtotal', nilai: Retur.Subtotal },
                        { label: 'Diskon', nilai: Retur.Diskon },
                        { label: 'Dasar pengenaan pajak', nilai: Retur.DasarPengenaanPajak },
                        { label: 'Pajak', nilai: Retur.Pajak },
                        { label: 'Total retur', nilai: Retur.Total, tebal: true },
                        ...(BandingkanDesimal(Retur.NilaiRefund, '0') > 0
                            ? [
                                  {
                                      label: `Dikembalikan ke pembeli${Retur.NamaAkunRefund === null ? '' : ` (${Retur.NamaAkunRefund})`}`,
                                      nilai: Retur.NilaiRefund,
                                  },
                              ]
                            : []),
                        { label: 'HPP dibalik', nilai: Retur.TotalHpp },
                    ]}
                />
            </Panel>

            <JurnalDokumenGrosir jurnal={Jurnal} izin={Izin} />
            <RiwayatGrosirDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasanGrosir
                    judul="Batalkan retur"
                    keterangan="Barang yang tadi masuk dikeluarkan kembali, jurnal returnya dibalik, dan nota kreditnya dicabut sehingga piutang fakturnya pulih."
                    labelAksi="Batalkan retur"
                    alamat={`${alamat}/${Retur.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
