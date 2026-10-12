import { Link } from '@inertiajs/react';
import { useState } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import {
    AlamatPembelian,
    DaftarDokumenTerkait,
    DaftarJurnalDokumen,
    DaftarRiwayatDokumen,
    DialogAlasan,
    KartuKeterangan,
    Keterangan,
    LabelStatusPembelian,
    RingkasanNilai,
} from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatHppSatuan, FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDetailPenerimaan, PropsDetailPenerimaan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/penerimaan`;

export const kolomBarisPenerimaan: KolomTabel<BarisDetailPenerimaan>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{b.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{b.Sku ?? 'Tanpa SKU'}</span>
                {b.NomorBatch ? (
                    <span className="text-keterangan text-teks-sekunder">
                        Batch {b.NomorBatch}
                        {b.TanggalKedaluwarsa ? ` | kedaluwarsa ${FormatTanggal(b.TanggalKedaluwarsa)}` : ''}
                    </span>
                ) : null}
                {b.NomorSeri.length > 0 ? (
                    <span className="text-keterangan break-all text-teks-sekunder">Seri: {b.NomorSeri.join(', ')}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Diterima',
        enableSorting: false,
        meta: { label: 'Jumlah diterima', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col items-end">
                <span>{FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}</span>
                {b.JumlahPesanan ? (
                    <span className="text-keterangan text-teks-sekunder">
                        dipesan {FormatJumlahStok(b.JumlahPesanan)}
                    </span>
                ) : null}
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
        id: 'Diskon',
        header: 'Diskon',
        enableSorting: false,
        meta: { label: 'Diskon', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.Diskon),
    },
    {
        id: 'AlokasiBiaya',
        header: 'Ongkir',
        enableSorting: false,
        meta: { label: 'Alokasi ongkir', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.AlokasiBiaya),
    },
    {
        id: 'Nilai',
        header: 'Nilai persediaan',
        enableSorting: false,
        meta: { label: 'Nilai persediaan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Nilai),
    },
    {
        id: 'HppSatuan',
        header: 'Nilai per satuan dasar',
        enableSorting: false,
        meta: { label: 'Nilai per satuan dasar', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatHppSatuan(row.original.HppSatuan),
    },
    {
        id: 'Diretur',
        header: 'Diretur',
        enableSorting: false,
        meta: { label: 'Sudah diretur', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.JumlahDiretur, b.SimbolSatuan),
    },
];

/** PRD-10: tautan Cetak label barang diterima (satu stiker per satuan dasar); null bila tak ada barang yang bisa dilabeli. */
export function BuatTautanLabel(baris: readonly BarisDetailPenerimaan[]): string | null {
    const item = baris.flatMap((b) =>
        b.UuidSatuanLabel !== null && b.JumlahLabel !== null && b.JumlahLabel > 0
            ? [`Item[]=${b.UuidSatuanLabel}:${String(b.JumlahLabel)}`]
            : [],
    );

    return item.length === 0 ? null : `/kelola/produk/label?${item.join('&')}`;
}

/** F-04 fase 1: detail penerimaan barang / belanja stok (batalkan, retur, fakturkan). */
export default function HalamanDetailPenerimaan({
    Penerimaan: p,
    Baris,
    Retur,
    Jurnal,
    Riwayat,
    Izin,
    Tindakan,
}: PropsDetailPenerimaan) {
    const [batalkan, AturBatalkan] = useState(false);
    const tautanLabel = BuatTautanLabel(Baris);

    return (
        <TataLetakAplikasi judul={`${p.BelanjaStok ? 'Belanja stok' : 'Penerimaan'} ${p.Nomor}`}>
            <div className="flex flex-wrap items-center gap-2">
                <LabelStatusPembelian status={p.Status} label={p.LabelStatus} />
                {p.Faktur ? (
                    <Link
                        href={`${AlamatPembelian}/faktur/${p.Faktur.Uuid}`}
                        className="font-mono text-isi text-brand underline"
                    >
                        Faktur {p.Faktur.Nomor}
                    </Link>
                ) : (
                    <span className="text-isi text-teks-sekunder">Belum difakturkan</span>
                )}
            </div>
            {p.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Penerimaan dibatalkan">
                    {p.AlasanBatal}. Stok dan jurnalnya sudah dibalik
                    {p.DibatalkanOleh ? ` (oleh ${p.DibatalkanOleh})` : ''}.
                </Pemberitahuan>
            ) : null}

            <div className="flex flex-wrap gap-2">
                {Tindakan.Fakturkan && p.Pemasok ? (
                    <Button asChild>
                        <Link href={`${AlamatPembelian}/faktur/buat?pemasok=${p.Pemasok.Uuid}&penerimaan=${p.Uuid}`}>
                            Catat faktur
                        </Link>
                    </Button>
                ) : null}
                {Tindakan.Retur ? (
                    <Button asChild variant="outline">
                        <Link href={`${AlamatPembelian}/retur/buat?penerimaan=${p.Uuid}`}>Retur ke pemasok</Link>
                    </Button>
                ) : null}
                {p.Status !== 'Dibatalkan' && tautanLabel !== null ? (
                    <Button asChild variant="outline">
                        <Link href={tautanLabel}>Cetak label barang diterima</Link>
                    </Button>
                ) : null}
                {Tindakan.BiayaTambahan === true ? (
                    <Button asChild variant="outline">
                        <Link href={`${AlamatPembelian}/biaya-tambahan/buat?penerimaan=${p.Uuid}`}>
                            Catat biaya tambahan
                        </Link>
                    </Button>
                ) : null}
                {Tindakan.Batalkan ? (
                    <Button variant="destructive" onClick={() => AturBatalkan(true)}>
                        Batalkan penerimaan
                    </Button>
                ) : null}
            </div>

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{p.Nomor}</span>
                </Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(p.Tanggal)}</Keterangan>
                <Keterangan label="Pemasok">{p.Pemasok ? `${p.Pemasok.Nama} (${p.Pemasok.Kode})` : '—'}</Keterangan>
                <Keterangan label="Pesanan">
                    {p.Pesanan ? (
                        <Link
                            href={`${AlamatPembelian}/pesanan/${p.Pesanan.Uuid}`}
                            className="font-mono text-brand underline"
                        >
                            {p.Pesanan.Nomor}
                        </Link>
                    ) : (
                        'Tanpa pesanan'
                    )}
                </Keterangan>
                <Keterangan label="Lokasi stok">
                    {p.NamaGudang}
                    {p.NamaOutlet ? ` | ${p.NamaOutlet}` : ''}
                </Keterangan>
                <Keterangan label={p.BelanjaStok ? 'Nomor nota' : 'Surat jalan'}>{p.NomorSuratJalan ?? '—'}</Keterangan>
                <Keterangan label="Dicatat oleh">{p.DibuatOleh ?? '—'}</Keterangan>
                {p.Lampiran ? (
                    <Keterangan label="Lampiran">
                        <a href={`${alamat}/${p.Uuid}/lampiran`} className="text-brand underline">
                            {p.Lampiran.Nama}
                        </a>
                    </Keterangan>
                ) : null}
                {p.Catatan ? <Keterangan label="Catatan">{p.Catatan}</Keterangan> : null}
            </KartuKeterangan>

            <Panel judul="Barang" idJudul="judul-barang-penerimaan">
                <TabelData
                    id="pembelian-penerimaan-baris"
                    label={`Barang diterima, ${String(Baris.length)} baris`}
                    kolom={kolomBarisPenerimaan}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Id)}
                    cari="Cari nama produk atau SKU"
                    kosong={{ judul: 'Tidak ada barang.' }}
                />
                <RingkasanNilai
                    baris={[
                        { label: 'Subtotal barang', nilai: p.Subtotal },
                        { label: 'Ongkos kirim', nilai: p.Ongkir },
                        { label: 'Nilai persediaan', nilai: p.TotalNilai, tebal: true },
                        ...(p.TarifPpn
                            ? [
                                  {
                                      label: `PPN masukan ${FormatPersen(p.TarifPpn)}${p.PpnDikreditkan ? ' (dikreditkan)' : ''}`,
                                      nilai: p.Pajak,
                                  },
                              ]
                            : []),
                    ]}
                />
            </Panel>

            <DaftarDokumenTerkait
                judul="Retur pembelian"
                alamat={`${AlamatPembelian}/retur`}
                dokumen={Retur.map((r) => ({ ...r, Nilai: r.Total }))}
                kosong="Belum ada retur dari penerimaan ini."
            />
            <DaftarJurnalDokumen jurnal={Jurnal} bolehLihat={Izin.LihatJurnal} />
            <DaftarRiwayatDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasan
                    judul={`Batalkan ${p.Nomor}?`}
                    keterangan={`Stok ${FormatRupiah(p.TotalNilai)} ditarik kembali dan jurnalnya dibalik. Hanya bisa bila barangnya belum terpakai, belum difakturkan, dan belum diretur.`}
                    labelAksi="Batalkan penerimaan"
                    alamat={`${alamat}/${p.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
