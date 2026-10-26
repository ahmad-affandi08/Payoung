import { Link } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { HalamanDaftarPembelian, KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisDokumenKonsinyasi, PropsDokumenKonsinyasi } from '@/Tipe/Pembelian';

import { AlamatKonsinyasi } from '../Daftar';

const alamat = `${AlamatKonsinyasi}/dokumen`;

const kolom: KolomTabel<BarisDokumenKonsinyasi>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: d } }) => (
            <Link href={`${alamat}/${d.Uuid}`} className="font-mono font-semibold break-all text-brand underline">
                {d.Nomor}
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
        cell: ({ row: { original: d } }) => (
            <LabelStatus jenis={d.Jenis === 'Masuk' ? 'sukses' : 'netral'} teks={d.LabelJenis} />
        ),
    },
    {
        id: 'Pemasok',
        header: 'Penitip',
        enableSorting: false,
        meta: { label: 'Penitip', prioritas: 'penting' },
        cell: ({ row }) => <span className="break-words">{row.original.NamaPemasok}</span>,
    },
    {
        id: 'Gudang',
        header: 'Lokasi stok',
        enableSorting: false,
        meta: { label: 'Lokasi stok', prioritas: 'rendah' },
        cell: ({ row }) => row.original.NamaGudang,
    },
    KolomUang('TotalNilai', 'Nilai titipan', (d) => d.TotalNilai, true),
];

/** F-05i: riwayat dokumen titipan masuk & retur ke penitip (hanya mutasi stok, tanpa jurnal). */
export default function HalamanDokumenKonsinyasi({ Dokumen, OpsiJenis, OpsiPemasok, Izin }: PropsDokumenKonsinyasi) {
    const saring: DefinisiSaring[] = [
        {
            id: 'Jenis',
            label: 'Jenis',
            jenis: 'pilihanBanyak',
            opsi: OpsiJenis.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Pemasok',
            label: 'Penitip',
            jenis: 'pilihan',
            opsi: OpsiPemasok.map((p) => ({ nilai: p.Uuid, label: `${p.Nama} (${p.Kode})` })),
        },
    ];

    return (
        <HalamanDaftarPembelian
            judul="Riwayat titipan"
            keterangan="Titipan masuk dinilai harga titip. Retur ke penitip dinilai harga rata-rata titipan saat keluar. Keduanya tidak dijurnal karena barang titipan bukan aset toko."
            izin={Izin}
            objek="titipan"
        >
            <AksiHalaman>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href={AlamatKonsinyasi}>Penitip & hutang</Link>
                </Button>
                {Izin.Kelola ? (
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatKonsinyasi}/buat?jenis=Masuk`}>Catat titipan masuk</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pembelian-konsinyasi-dokumen"
                label="Riwayat dokumen titipan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Dokumen }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor dokumen"
                saring={saring}
                alamatDetail={(d) => `${alamat}/${d.Uuid}`}
                labelBaris={(d) => `dokumen ${d.Nomor}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada titipan masuk atau retur ke penitip.' }}
            />
        </HalamanDaftarPembelian>
    );
}
