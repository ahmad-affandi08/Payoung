import { Link } from '@inertiajs/react';
import { useState } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import {
    AlamatPembelian,
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
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailRetur } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/retur`;

type BarisRetur = PropsDetailRetur['Baris'][number];

const kolom: KolomTabel<BarisRetur>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{b.NamaProduk}</span>
                {b.NomorSeri.length > 0 ? (
                    <span className="text-keterangan break-all text-teks-sekunder">Seri: {b.NomorSeri.join(', ')}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah (satuan dasar)', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.JumlahDasar, b.SimbolSatuan),
    },
    {
        id: 'Nilai',
        header: 'Nilai persediaan',
        enableSorting: false,
        meta: { label: 'Nilai persediaan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Nilai),
    },
    {
        id: 'NilaiHutang',
        header: 'Pengurang hutang',
        enableSorting: false,
        meta: { label: 'Pengurang hutang', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.NilaiHutang),
    },
    {
        id: 'Pajak',
        header: 'PPN',
        enableSorting: false,
        meta: { label: 'PPN masukan kontra', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.Pajak),
    },
];

/** F-04 fase 1: detail retur pembelian (stok keluar, hutang berkurang, batalkan). */
export default function HalamanDetailRetur({ Retur: r, Baris, Jurnal, Riwayat, Izin, Tindakan }: PropsDetailRetur) {
    const [batalkan, AturBatalkan] = useState(false);

    return (
        <TataLetakAplikasi judul={`Retur ${r.Nomor}`}>
            <div className="flex flex-wrap items-center gap-2">
                <LabelStatusPembelian status={r.Status} label={r.LabelStatus} />
            </div>
            {r.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Retur dibatalkan">
                    {r.AlasanBatal}. Stok dan hutang sudah dikembalikan.
                </Pemberitahuan>
            ) : null}
            {Tindakan.Batalkan ? (
                <div>
                    <Button variant="destructive" onClick={() => AturBatalkan(true)}>
                        Batalkan retur
                    </Button>
                </div>
            ) : null}

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{r.Nomor}</span>
                </Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(r.Tanggal)}</Keterangan>
                <Keterangan label="Pemasok">{r.Pemasok ? `${r.Pemasok.Nama} (${r.Pemasok.Kode})` : '—'}</Keterangan>
                <Keterangan label="Dari penerimaan">
                    {r.Penerimaan ? (
                        <Link
                            href={`${AlamatPembelian}/penerimaan/${r.Penerimaan.Uuid}`}
                            className="font-mono text-brand underline"
                        >
                            {r.Penerimaan.Nomor}
                        </Link>
                    ) : (
                        '—'
                    )}
                </Keterangan>
                <Keterangan label="Faktur">
                    {r.Faktur ? (
                        <Link
                            href={`${AlamatPembelian}/faktur/${r.Faktur.Uuid}`}
                            className="font-mono text-brand underline"
                        >
                            {r.Faktur.Nomor}
                        </Link>
                    ) : (
                        'Belum difakturkan (mengurangi hutang belum difakturkan)'
                    )}
                </Keterangan>
                <Keterangan label="Lokasi stok">{r.NamaGudang}</Keterangan>
                <Keterangan label="Alasan">{r.Alasan}</Keterangan>
                <Keterangan label="Dicatat oleh">{r.DibuatOleh ?? '—'}</Keterangan>
            </KartuKeterangan>

            <Panel judul="Barang diretur" idJudul="judul-barang-retur">
                <TabelData
                    id="pembelian-retur-baris"
                    label={`Barang diretur, ${String(Baris.length)} baris`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Id)}
                    cari="Cari nama produk"
                    kosong={{ judul: 'Tidak ada barang.' }}
                />
                <RingkasanNilai
                    baris={[
                        { label: 'Nilai persediaan keluar', nilai: r.NilaiBarang },
                        { label: 'Pengurang hutang', nilai: r.NilaiHutang },
                        ...(BandingkanDesimal(r.NilaiRefund, '0') > 0
                            ? [{ label: 'Uang dikembalikan pemasok', nilai: r.NilaiRefund }]
                            : []),
                        { label: 'PPN masukan kontra', nilai: r.Pajak },
                        { label: 'Total retur', nilai: r.Total, tebal: true },
                    ]}
                />
            </Panel>

            <DaftarJurnalDokumen jurnal={Jurnal} bolehLihat={Izin.LihatJurnal} />
            <DaftarRiwayatDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasan
                    judul={`Batalkan retur ${r.Nomor}?`}
                    keterangan="Barang dicatat masuk kembali dan hutang ke pemasok dikembalikan."
                    labelAksi="Batalkan retur"
                    alamat={`${alamat}/${r.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
