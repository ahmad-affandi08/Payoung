import { Link } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { AlamatPembelian, LabelStatusPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import { HalamanDaftarPembelian, KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisBiayaTambahan, PropsDaftarBiayaTambahan } from '@/Tipe/Pembelian';

export const AlamatBiayaTambahan = `${AlamatPembelian}/biaya-tambahan`;

const kolom: KolomTabel<BarisBiayaTambahan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <Link
                href={`${AlamatBiayaTambahan}/${b.Uuid}`}
                className="font-mono font-semibold break-all text-brand underline"
            >
                {b.Nomor}
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
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span>{b.LabelJenis}</span>
                {b.NamaPenagih ? <span className="text-keterangan text-teks-sekunder">{b.NamaPenagih}</span> : null}
            </span>
        ),
    },
    {
        id: 'Penerimaan',
        header: 'Penerimaan',
        enableSorting: false,
        meta: { label: 'Penerimaan', prioritas: 'rendah' },
        cell: ({ row }) => <span className="font-mono">{row.original.NomorPenerimaan}</span>,
    },
    KolomUang('Jumlah', 'Jumlah', (b) => b.Jumlah, true),
    KolomUang('KePersediaan', 'Ke nilai stok', (b) => b.KePersediaan),
    KolomUang('KeHpp', 'Ke HPP', (b) => b.KeHpp),
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
 * v3.41 (INV-14): biaya pihak ketiga setelah barang diterima (ekspedisi, bea masuk, asuransi, bongkar muat). Bagian
 * untuk stok yang masih ada menaikkan nilai stok; bagian untuk barang yang sudah terjual langsung ke HPP.
 */
export default function HalamanDaftarBiayaTambahan({ Biaya, OpsiJenis, OpsiStatus, Izin }: PropsDaftarBiayaTambahan) {
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
            judul="Biaya tambahan pembelian"
            keterangan="Ongkos ekspedisi, bea masuk, asuransi, atau bongkar muat yang ditagih pihak lain setelah barang diterima. Catat dari halaman penerimaan barangnya."
            izin={Izin}
            objek="biaya tambahan"
        >
            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href={`${AlamatPembelian}/penerimaan`}>Pilih penerimaan barang</Link>
                </Button>
            </AksiHalaman>
            <TabelData
                id="pembelian-biaya-tambahan"
                label="Daftar biaya tambahan pembelian"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatBiayaTambahan, awal: Biaya }}
                ambilIdBaris={(b) => b.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor biaya atau nomor penerimaan"
                saring={saring}
                alamatDetail={(b) => `${AlamatBiayaTambahan}/${b.Uuid}`}
                labelBaris={(b) => `biaya ${b.Nomor}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada biaya tambahan pembelian.' }}
            />
        </HalamanDaftarPembelian>
    );
}
