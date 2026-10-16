import { Link, usePage } from '@inertiajs/react';

import { AlamatInvoice } from '@/Komponen/Invoice/BagianInvoice';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarInvoiceBerulang, PropsDaftarInvoiceBerulang, StatusInvoiceBerulang } from '@/Tipe/Invoice';

const alamat = `${AlamatInvoice}/berulang`;

const jenisStatus: Record<StatusInvoiceBerulang, 'sukses' | 'peringatan' | 'netral'> = {
    Aktif: 'sukses',
    Dijeda: 'peringatan',
    Selesai: 'netral',
};

const kolom: KolomTabel<BarisDaftarInvoiceBerulang>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Templat',
        meta: { label: 'Templat', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <Link href={`${alamat}/${t.Uuid}`} className="font-semibold break-words text-brand underline">
                {t.Nama}
            </Link>
        ),
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row }) => <span className="break-words">{row.original.NamaPelanggan}</span>,
    },
    {
        id: 'Jadwal',
        header: 'Jadwal',
        enableSorting: false,
        meta: { label: 'Jadwal', prioritas: 'rendah' },
        cell: ({ row }) => row.original.Jadwal,
    },
    {
        id: 'BerikutnyaPada',
        accessorKey: 'BerikutnyaPada',
        header: 'Terbit berikutnya',
        meta: { label: 'Terbit berikutnya', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.BerikutnyaPada === null ? '—' : FormatTanggal(row.original.BerikutnyaPada)),
    },
    {
        id: 'JumlahTerbit',
        accessorKey: 'JumlahTerbit',
        header: 'Sudah terbit',
        meta: { label: 'Sudah terbit', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: t } }) =>
            t.BatasKali === null ? String(t.JumlahTerbit) : `${String(t.JumlahTerbit)} dari ${String(t.BatasKali)}`,
    },
    {
        id: 'Mode',
        header: 'Mode',
        enableSorting: false,
        meta: { label: 'Mode', prioritas: 'rendah' },
        cell: ({ row }) => row.original.LabelMode,
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <div className="flex flex-col gap-1">
                <LabelStatus jenis={jenisStatus[t.Status]} teks={t.LabelStatus} />
                {t.GalatTerakhir !== null ? (
                    <span className="text-keterangan text-bahaya">Gagal terbit: {t.GalatTerakhir}</span>
                ) : null}
            </div>
        ),
    },
];

/** Daftar templat invoice berulang (F-21): tagihan langganan, sewa, atau retainer yang terbit otomatis tiap periode. */
export default function HalamanDaftarInvoiceBerulang({ Templat, OpsiStatus, Izin }: PropsDaftarInvoiceBerulang) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
    ];

    return (
        <TataLetakAplikasi judul="Invoice berulang" jejak={[{ label: 'Invoice', href: AlamatInvoice }]}>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Templat tagihan yang terbit sendiri tiap minggu, bulan, atau tahun, misalnya hosting, sewa, atau
                retainer. Isi invoice dihitung ulang tiap siklus dengan tarif pajak yang berlaku pada tanggalnya.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="invoice.kelola" objek="invoice berulang" /> : null}
            <DaftarGalatServer galat={props.errors} />
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`${alamat}/buat`}>Buat templat</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="invoice-berulang"
                label="Daftar invoice berulang"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Templat }}
                ambilIdBaris={(t) => t.Uuid}
                urutBawaan="BerikutnyaPada"
                cari="Cari nama templat"
                saring={saring}
                alamatDetail={(t) => `${alamat}/${t.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada invoice berulang.' }}
            />
        </TataLetakAplikasi>
    );
}
