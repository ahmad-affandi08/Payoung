import { Link } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
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
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDetailFaktur, PropsDetailFaktur } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/faktur`;

/** Kolom 3-way matching: pesanan → penerimaan → faktur per baris. */
export const kolomBarisFaktur: KolomTabel<BarisDetailFaktur>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.NomorPenerimaan ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{b.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">
                    {[b.NomorPesanan, b.NomorPenerimaan].filter(Boolean).join(' → ') || '—'}
                </span>
            </span>
        ),
    },
    {
        id: 'Pesanan',
        header: 'Pesanan',
        enableSorting: false,
        meta: { label: 'Jumlah & harga pesanan', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) =>
            b.JumlahPesanan ? (
                <span className="flex flex-col items-end">
                    <span>{FormatJumlahStok(b.JumlahPesanan, b.SimbolSatuan)}</span>
                    <span className="text-keterangan text-teks-sekunder">
                        {b.HargaPesanan ? FormatRupiah(b.HargaPesanan) : '—'}
                    </span>
                </span>
            ) : (
                '—'
            ),
    },
    {
        id: 'Penerimaan',
        header: 'Diterima',
        enableSorting: false,
        meta: { label: 'Jumlah & harga diterima', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col items-end">
                <span>{FormatJumlahStok(b.Jumlah, b.SimbolSatuan)}</span>
                <span className="text-keterangan text-teks-sekunder">{FormatRupiah(b.HargaPenerimaan)}</span>
            </span>
        ),
    },
    {
        id: 'Harga',
        header: 'Harga faktur',
        enableSorting: false,
        meta: { label: 'Harga faktur', angka: true, prioritas: 'penting' },
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
        id: 'SelisihHarga',
        header: 'Selisih',
        enableSorting: false,
        meta: { label: 'Selisih harga', angka: true, prioritas: 'rendah' },
        cell: ({ row }) =>
            AmbilTandaDesimal(row.original.SelisihHarga) === 0 ? '—' : FormatRupiah(row.original.SelisihHarga),
    },
    {
        id: 'Pajak',
        header: 'PPN',
        enableSorting: false,
        meta: { label: 'PPN masukan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.Pajak),
    },
];

/** F-04 fase 1: detail faktur pembelian (3-way matching, hutang, pembayaran, retur). */
export default function HalamanDetailFaktur({
    Faktur: f,
    Baris,
    Penerimaan,
    Pembayaran,
    Retur,
    NotaDebit = [],
    Jurnal,
    Riwayat,
    Izin,
    Tindakan,
}: PropsDetailFaktur) {
    const [batalkan, AturBatalkan] = useState(false);

    return (
        <TataLetakAplikasi judul={`Faktur ${f.Nomor}`}>
            <div className="flex flex-wrap items-center gap-2">
                <LabelStatusPembelian status={f.Status} label={f.LabelStatus} />
                <span className="text-isi text-teks-sekunder">
                    Sisa hutang <span className="font-semibold tabular-nums">{FormatRupiah(f.Sisa)}</span>
                </span>
            </div>
            {f.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Faktur dibatalkan">
                    {f.AlasanBatal}
                </Pemberitahuan>
            ) : null}
            {AmbilTandaDesimal(f.SelisihHarga) !== 0 ? (
                <Pemberitahuan jenis="info" judul="Ada selisih harga dengan penerimaan">
                    Selisih {FormatRupiah(f.SelisihHarga)} sudah disesuaikan ke persediaan/HPP saat faktur disimpan.
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                {Tindakan.Bayar && f.Pemasok ? (
                    <Button asChild>
                        <Link href={`${AlamatPembelian}/pembayaran/buat?pemasok=${f.Pemasok.Uuid}&faktur=${f.Uuid}`}>
                            Bayar faktur
                        </Link>
                    </Button>
                ) : null}
                {Tindakan.NotaDebit ? (
                    <Button asChild variant="outline">
                        <Link href={`${AlamatPembelian}/nota-debit/buat?faktur=${f.Uuid}`}>Buat nota debit</Link>
                    </Button>
                ) : null}
                {Tindakan.Batalkan ? (
                    <Button variant="destructive" onClick={() => AturBatalkan(true)}>
                        Batalkan faktur
                    </Button>
                ) : null}
            </AksiHalaman>

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{f.Nomor}</span>
                </Keterangan>
                <Keterangan label="No. faktur pemasok">
                    <span className="font-mono">{f.NomorFakturPemasok}</span>
                </Keterangan>
                <Keterangan label="Pemasok">{f.Pemasok ? `${f.Pemasok.Nama} (${f.Pemasok.Kode})` : '—'}</Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(f.Tanggal)}</Keterangan>
                <Keterangan label="Jatuh tempo">
                    {FormatTanggal(f.JatuhTempo)} ({f.TerminHari === 0 ? 'tunai' : `${String(f.TerminHari)} hari`})
                </Keterangan>
                <Keterangan label="Dicatat oleh">{f.DibuatOleh ?? '—'}</Keterangan>
                {f.Lampiran ? (
                    <Keterangan label="Lampiran">
                        <a href={`${alamat}/${f.Uuid}/lampiran`} className="text-brand underline">
                            {f.Lampiran.Nama}
                        </a>
                    </Keterangan>
                ) : null}
                {f.Catatan ? <Keterangan label="Catatan">{f.Catatan}</Keterangan> : null}
            </KartuKeterangan>

            <Panel judul="Pencocokan barang" idJudul="judul-baris-faktur">
                <TabelData
                    id="pembelian-faktur-baris"
                    label={`Baris faktur, ${String(Baris.length)} baris`}
                    kolom={kolomBarisFaktur}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Id)}
                    cari="Cari nama produk atau nomor dokumen"
                    kosong={{ judul: 'Faktur ini tidak berisi barang.' }}
                />
                <RingkasanNilai
                    baris={[
                        { label: 'Nilai penerimaan', nilai: f.NilaiPenerimaan },
                        { label: 'Subtotal faktur', nilai: f.Subtotal },
                        { label: 'Ongkos kirim', nilai: f.Ongkir },
                        {
                            label: f.TarifPpn ? `PPN masukan ${FormatPersen(f.TarifPpn)}` : 'PPN masukan',
                            nilai: f.Pajak,
                        },
                        { label: 'Total faktur', nilai: f.Total, tebal: true },
                        { label: 'Sudah dibayar', nilai: f.JumlahDibayar },
                        { label: 'Dikurangi retur & nota debit', nilai: f.JumlahRetur },
                        { label: 'Sisa hutang', nilai: f.Sisa, tebal: true },
                    ]}
                />
            </Panel>

            <DaftarDokumenTerkait
                judul="Penerimaan barang"
                alamat={`${AlamatPembelian}/penerimaan`}
                dokumen={Penerimaan.map((p) => ({ ...p, Nilai: p.TotalNilai }))}
                kosong="Tidak ada penerimaan."
            />
            <DaftarDokumenTerkait
                judul="Pembayaran"
                alamat={`${AlamatPembelian}/pembayaran`}
                dokumen={Pembayaran.map((p) => ({ ...p, Nilai: p.Jumlah }))}
                kosong="Belum ada pembayaran untuk faktur ini."
            />
            {Retur.length > 0 ? (
                <DaftarDokumenTerkait
                    judul="Retur"
                    alamat={`${AlamatPembelian}/retur`}
                    dokumen={Retur.map((r) => ({ ...r, Nilai: r.Total }))}
                    kosong=""
                />
            ) : null}
            {NotaDebit.length > 0 ? (
                <DaftarDokumenTerkait
                    judul="Nota debit"
                    alamat={`${AlamatPembelian}/nota-debit`}
                    dokumen={NotaDebit.map((n) => ({ ...n, Nilai: n.Total }))}
                    kosong=""
                />
            ) : null}
            <DaftarJurnalDokumen jurnal={Jurnal} bolehLihat={Izin.LihatJurnal} />
            <DaftarRiwayatDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasan
                    judul={`Batalkan faktur ${f.Nomor}?`}
                    keterangan="Hutang dan jurnal faktur dibalik. Penerimaan barangnya bisa difakturkan ulang. Hanya untuk faktur yang belum dibayar."
                    labelAksi="Batalkan faktur"
                    alamat={`${alamat}/${f.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
