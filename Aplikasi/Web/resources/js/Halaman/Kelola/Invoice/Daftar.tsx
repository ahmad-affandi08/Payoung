import { Link } from '@inertiajs/react';

import AksiMassalInvoice from '@/Komponen/Invoice/AksiMassalInvoice';
import { AlamatInvoice, LabelStatusBayarInvoice } from '@/Komponen/Invoice/BagianInvoice';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { usePage } from '@inertiajs/react';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarInvoice, PropsDaftarInvoice } from '@/Tipe/Invoice';

const kolom: KolomTabel<BarisDaftarInvoice>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: d } }) => (
            <Link
                href={`${AlamatInvoice}/${d.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {d.Nomor ?? 'Draf'}
            </Link>
        ),
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row }) => (
            <span className="break-words">
                {row.original.NamaPelanggan}
                {row.original.Perihal ? (
                    <span className="block text-keterangan text-teks-sekunder">{row.original.Perihal}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'JatuhTempo',
        accessorKey: 'JatuhTempo',
        header: 'Jatuh tempo',
        meta: { label: 'Jatuh tempo', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.JatuhTempo),
    },
    {
        id: 'Jenis',
        header: 'Jenis',
        enableSorting: false,
        meta: { label: 'Jenis', prioritas: 'rendah' },
        cell: ({ row }) => row.original.LabelJenis,
    },
    {
        id: 'StatusBayar',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => (
            <LabelStatusBayarInvoice status={d.StatusBayar} label={d.LabelStatusBayar} />
        ),
    },
    {
        id: 'Total',
        accessorKey: 'Total',
        header: 'Total',
        meta: { label: 'Total', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Total),
    },
    {
        id: 'Sisa',
        header: 'Sisa tagihan',
        enableSorting: false,
        meta: { label: 'Sisa tagihan', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: d } }) => (d.Sisa === null ? '—' : FormatRupiah(d.Sisa)),
    },
    {
        id: 'Dikirim',
        header: 'Dikirim / dilihat',
        enableSorting: false,
        meta: { label: 'Dikirim / dilihat', prioritas: 'rendah' },
        cell: ({ row: { original: d } }) => (
            <span className="text-keterangan text-teks-sekunder">
                {d.TerkirimPada === null ? 'Belum dikirim' : `Dikirim ${FormatTanggalWaktu(d.TerkirimPada)}`}
                <br />
                {d.DilihatPada === null ? 'Belum dilihat' : `Dilihat ${FormatTanggalWaktu(d.DilihatPada)}`}
            </span>
        ),
    },
];

/**
 * Daftar invoice (F-21, D-166). Status penagihan tiap baris dibaca dari piutang dan tanggal oleh server, jadi daftar,
 * detail, dan halaman pelanggan memakai satu tafsir yang sama.
 */
export default function HalamanDaftarInvoice({ Invoice, OpsiStatus, OpsiJenis, Izin }: PropsDaftarInvoice) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const saring: DefinisiSaring[] = [
        {
            id: 'Pembayaran',
            label: 'Pembayaran',
            jenis: 'pilihan',
            opsi: [
                { nilai: 'Terbuka', label: 'Belum lunas' },
                { nilai: 'LewatTempo', label: 'Lewat jatuh tempo' },
                { nilai: 'Lunas', label: 'Lunas' },
            ],
        },
        {
            id: 'Status',
            label: 'Status dokumen',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Jenis',
            label: 'Jenis',
            jenis: 'pilihanBanyak',
            opsi: OpsiJenis.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
    ];

    return (
        <TataLetakAplikasi judul="Invoice">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Tagihan untuk jasa dan barang tanpa stok. Kirim ke pelanggan lewat WhatsApp atau tautan, lalu catat
                pembayarannya. Pengingat otomatis mengikuti pengaturan di Piutang pelanggan. Barang berstok ditagih
                lewat pesanan grosir dan surat jalan.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="invoice.kelola" objek="invoice" /> : null}
            <DaftarGalatServer galat={props.errors} />
            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href={`${AlamatInvoice}/berulang`}>Invoice berulang</Link>
                </Button>
                {Izin.Kelola ? (
                    <>
                        <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                            <Link href={`${AlamatInvoice}/buat?jenis=UangMuka`}>Buat invoice uang muka</Link>
                        </Button>
                        <Button asChild className="h-8 pointer-coarse:h-11">
                            <Link href={`${AlamatInvoice}/buat`}>Buat invoice</Link>
                        </Button>
                    </>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="invoice"
                label="Daftar invoice"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatInvoice, awal: Invoice }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, perihal, atau nomor PO"
                saring={saring}
                alamatDetail={(d) => `${AlamatInvoice}/${d.Uuid}`}
                labelBaris={(d) => `invoice ${d.Nomor ?? 'draf'}`}
                {...(Izin.Kelola
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<BarisDaftarInvoice>) => (
                              <AksiMassalInvoice konteks={konteks} />
                          ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada invoice.' }}
            />
        </TataLetakAplikasi>
    );
}
