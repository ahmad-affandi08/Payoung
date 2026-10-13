import { Link } from '@inertiajs/react';

import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { JudulUnit, RincianUnit } from '@/Pustaka/UnitServis';
import { JenisStatusPerintahKerja, type BarisDaftarPerintahKerja } from '@/Tipe/Bengkel';

/** Bengkel (§9.10): alamat halaman & kolom tabel perintah kerja (daftar dan riwayat servis kendaraan). */
export const AlamatPerintahKerja = '/kelola/bengkel/perintah-kerja';

export function BuatKolomPerintahKerja(): KolomTabel<BarisDaftarPerintahKerja>[] {
    return [
        {
            id: 'DibuatPada',
            accessorKey: 'DibuatPada',
            header: 'Perintah kerja',
            meta: { label: 'Perintah kerja', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: p } }) => (
                <span className="flex flex-col gap-0.5">
                    <Link
                        href={`${AlamatPerintahKerja}/${p.Uuid}`}
                        className="font-mono font-semibold text-brand underline"
                    >
                        {p.Nomor}
                    </Link>
                    <span className="text-keterangan whitespace-nowrap text-teks-sekunder tabular-nums">
                        {FormatTanggalWaktu(p.DibuatPada)}
                    </span>
                </span>
            ),
        },
        {
            id: 'Kendaraan',
            header: 'Unit',
            enableSorting: false,
            meta: { label: 'Unit', prioritas: 'penting' },
            cell: ({ row: { original: p } }) => (
                <span className="flex flex-col gap-0.5 break-words">
                    <span className="font-mono font-semibold text-teks-utama">{JudulUnit(p.Kendaraan)}</span>
                    <span className="text-keterangan text-teks-sekunder">
                        {RincianUnit(p.Kendaraan)} | {p.Pelanggan.Nama}
                    </span>
                </span>
            ),
        },
        {
            id: 'Status',
            header: 'Status',
            enableSorting: false,
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row: { original: p } }) => (
                <span className="flex flex-col items-start gap-1">
                    <LabelStatus jenis={JenisStatusPerintahKerja(p.Status)} teks={p.LabelStatus} />
                    {p.Penjualan ? (
                        <span className="font-mono text-keterangan text-teks-sekunder">{p.Penjualan.Nomor}</span>
                    ) : null}
                </span>
            ),
        },
        {
            id: 'Mekanik',
            header: 'Mekanik',
            enableSorting: false,
            meta: { label: 'Mekanik', prioritas: 'rendah' },
            cell: ({ row: { original: p } }) => (p.Mekanik.length === 0 ? '-' : p.Mekanik.join(', ')),
        },
        {
            id: 'ServisBerikutnyaPada',
            accessorKey: 'ServisBerikutnyaPada',
            header: 'Servis berikutnya',
            meta: { label: 'Servis berikutnya', prioritas: 'rendah' },
            cell: ({ row: { original: p } }) =>
                p.ServisBerikutnyaPada === null && p.ServisBerikutnyaKm === null
                    ? '-'
                    : [
                          p.ServisBerikutnyaPada ? FormatTanggal(p.ServisBerikutnyaPada) : null,
                          p.ServisBerikutnyaKm === null ? null : `${p.ServisBerikutnyaKm.toLocaleString('id-ID')} km`,
                      ]
                          .filter(Boolean)
                          .join(' | '),
        },
        {
            id: 'Outlet',
            header: 'Outlet',
            enableSorting: false,
            meta: { label: 'Outlet', prioritas: 'rendah' },
            cell: ({ row }) => row.original.Outlet.Nama,
        },
        {
            id: 'Total',
            accessorKey: 'Total',
            header: 'Estimasi',
            meta: { label: 'Estimasi', angka: true, prioritas: 'penting' },
            cell: ({ row: { original: p } }) => (
                <span className="flex flex-col items-end gap-0.5">
                    <span className="tabular-nums">{FormatRupiah(p.Total)}</span>
                    {p.TotalDisetujui !== '0.00' ? (
                        <span className="text-keterangan text-teks-sekunder tabular-nums">
                            Disetujui {FormatRupiah(p.TotalDisetujui)}
                        </span>
                    ) : null}
                </span>
            ),
        },
    ];
}
