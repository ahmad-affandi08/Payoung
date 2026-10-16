import { Link, usePage } from '@inertiajs/react';

import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { AlamatPenawaran, LabelStatusPenawaran } from '@/Komponen/Penawaran/BagianPenawaran';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarPenawaran, PropsDaftarPenawaran } from '@/Tipe/Penawaran';

const kolom: KolomTabel<BarisDaftarPenawaran>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: d } }) => (
            <Link
                href={`${AlamatPenawaran}/${d.Uuid}`}
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
        id: 'BerlakuSampai',
        accessorKey: 'BerlakuSampai',
        header: 'Berlaku sampai',
        meta: { label: 'Berlaku sampai', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.BerlakuSampai),
    },
    {
        id: 'StatusTampil',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => (
            <LabelStatusPenawaran status={d.StatusTampil} label={d.LabelStatusTampil} />
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
 * Daftar penawaran (F-21 bagian 3, D-168). Status tampil (menunggu keputusan, kedaluwarsa, sudah jadi invoice) diturunkan
 * server dari tanggal dan invoice hasilnya, jadi daftar, detail, dan halaman publik satu tafsir.
 */
export default function HalamanDaftarPenawaran({ Penawaran, OpsiStatus, Izin }: PropsDaftarPenawaran) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
    ];

    return (
        <TataLetakAplikasi judul="Penawaran" jejak={[{ label: 'Invoice', href: '/kelola/invoice' }]}>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Tawarkan harga lebih dulu. Pelanggan membuka tautan penawaran, lalu menerima atau menolaknya. Penawaran
                yang diterima dijadikan draf invoice dengan satu klik. Penawaran tidak membukukan apa pun.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="invoice.kelola" objek="penawaran" /> : null}
            <DaftarGalatServer galat={props.errors} />
            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href="/kelola/invoice">Invoice</Link>
                </Button>
                {Izin.Kelola ? (
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPenawaran}/buat`}>Buat penawaran</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="penawaran"
                label="Daftar penawaran"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatPenawaran, awal: Penawaran }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, perihal, atau nomor PO"
                saring={saring}
                ekspor={{ alamat: `${AlamatPenawaran}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                alamatDetail={(d) => `${AlamatPenawaran}/${d.Uuid}`}
                labelBaris={(d) => `penawaran ${d.Nomor ?? 'draf'}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada penawaran.' }}
            />
        </TataLetakAplikasi>
    );
}
