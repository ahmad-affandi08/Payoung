import { Link } from '@inertiajs/react';

import { KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPreOrder, PropsDaftarPreOrder, StatusPreOrder } from '@/Tipe/PreOrder';

export const AlamatPreOrder = '/kelola/pre-order';

export function AmbilJenisStatusPreOrder(status: StatusPreOrder): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    switch (status) {
        case 'Siap':
            return 'peringatan';
        case 'Diambil':
            return 'sukses';
        case 'Dibatalkan':
            return 'bahaya';
        default:
            return 'netral';
    }
}

const kolom: KolomTabel<BarisPreOrder>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <Link
                href={`${AlamatPreOrder}/${p.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {p.Nomor}
            </Link>
        ),
    },
    {
        id: 'TanggalAmbil',
        accessorKey: 'TanggalAmbil',
        header: 'Tanggal ambil',
        meta: { label: 'Tanggal ambil', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalAmbil),
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <span className="break-words">{p.Pelanggan}</span>
                {p.NoHpPelanggan ? (
                    <span className="font-mono text-keterangan text-teks-sekunder">{p.NoHpPelanggan}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: p } }) => (
            <LabelStatus jenis={AmbilJenisStatusPreOrder(p.Status)} teks={p.LabelStatus} />
        ),
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah' },
        cell: ({ row }) => row.original.Outlet,
    },
    KolomUang('TotalPesanan', 'Perkiraan total', (p) => p.TotalPesanan),
    KolomUang('UangMuka', 'Uang muka', (p) => p.UangMuka, true),
    KolomUang('SisaUangMuka', 'Sisa DP', (p) => p.SisaUangMuka),
];

/**
 * F-12 bagian 2: pre-order dengan uang muka yang dibuat kasir. DP dicatat sebagai Uang Muka Pelanggan (kewajiban) dan
 * baru menjadi pendapatan saat pesanan diambil.
 */
export default function HalamanDaftarPreOrder({ Pesanan, OpsiStatus }: PropsDaftarPreOrder) {
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'TanggalAmbil', label: 'Tanggal ambil', jenis: 'rentangTanggal' },
    ];

    return (
        <TataLetakAplikasi judul="Pre-order">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Pesanan dengan uang muka di kasir, misalnya kue ulang tahun. Uang muka jadi pendapatan setelah pesanan
                diambil dan dilunasi.
            </p>
            <TabelData
                id="pre-order"
                label="Daftar pre-order"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatPreOrder, ...(Pesanan ? { awal: Pesanan } : {}) }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="TanggalAmbil"
                cari="Cari nomor atau pelanggan"
                saring={saring}
                alamatDetail={(p) => `${AlamatPreOrder}/${p.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada pre-order. Pre-order dibuat kasir di aplikasi dengan uang muka.',
                }}
            />
        </TataLetakAplikasi>
    );
}
