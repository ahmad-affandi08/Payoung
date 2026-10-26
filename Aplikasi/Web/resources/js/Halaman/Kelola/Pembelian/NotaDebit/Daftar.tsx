import { Link } from '@inertiajs/react';

import { AlamatPembelian, LabelStatusPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { HalamanDaftarPembelian, KolomPemasok, KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisNotaDebit, PropsDaftarNotaDebit } from '@/Tipe/Pembelian';

export const AlamatNotaDebit = `${AlamatPembelian}/nota-debit`;

const kolom: KolomTabel<BarisNotaDebit>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: n } }) => (
            <Link
                href={`${AlamatNotaDebit}/${n.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {n.Nomor}
            </Link>
        ),
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Jenis',
        header: 'Jenis',
        enableSorting: false,
        meta: { label: 'Jenis', prioritas: 'penting' },
        cell: ({ row: { original: n } }) => (
            <span className="flex flex-col">
                <span>{n.LabelJenis}</span>
                {n.NomorNotaPemasok ? (
                    <span className="font-mono text-keterangan text-teks-sekunder">{n.NomorNotaPemasok}</span>
                ) : null}
            </span>
        ),
    },
    KolomPemasok<BarisNotaDebit>(),
    {
        id: 'Faktur',
        header: 'Faktur',
        enableSorting: false,
        meta: { label: 'Faktur', prioritas: 'rendah' },
        cell: ({ row }) => <span className="font-mono">{row.original.NomorFaktur}</span>,
    },
    KolomUang('Total', 'Total', (n) => n.Total, true),
    KolomUang('NilaiRefund', 'Uang kembali', (n) => n.NilaiRefund),
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatusPembelian
                status={row.original.Status}
                label={row.original.Status === 'Diposting' ? 'Tercatat' : 'Dibatalkan'}
            />
        ),
    },
];

/**
 * v6.07 (F-04 BR-04.10): pengurang hutang atas faktur karena selisih harga atau klaim pemasok tanpa barang kembali.
 * Barang yang dikembalikan tetap lewat retur pembelian.
 */
export default function HalamanDaftarNotaDebit({ Nota, OpsiJenis, OpsiStatus, Izin }: PropsDaftarNotaDebit) {
    const saring: DefinisiSaring[] = [
        {
            id: 'Jenis',
            label: 'Jenis',
            jenis: 'pilihanBanyak',
            opsi: OpsiJenis.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
    ];

    return (
        <HalamanDaftarPembelian
            judul="Nota debit pembelian"
            keterangan="Pengurang hutang untuk selisih harga atau klaim kepada pemasok tanpa barang yang dikembalikan. Catat dari halaman fakturnya. Barang yang dikembalikan tetap lewat retur."
            izin={Izin}
            objek="nota debit"
        >
            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href={`${AlamatPembelian}/faktur`}>Pilih faktur pembelian</Link>
                </Button>
            </AksiHalaman>
            <TabelData
                id="pembelian-nota-debit"
                label="Daftar nota debit pembelian"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatNotaDebit, awal: Nota }}
                ambilIdBaris={(n) => n.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor nota, nota pemasok, atau nomor faktur"
                saring={saring}
                alamatDetail={(n) => `${AlamatNotaDebit}/${n.Uuid}`}
                labelBaris={(n) => `nota debit ${n.Nomor}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada nota debit pembelian.' }}
            />
        </HalamanDaftarPembelian>
    );
}
