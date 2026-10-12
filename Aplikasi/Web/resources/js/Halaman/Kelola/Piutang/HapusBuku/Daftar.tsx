import { useState } from 'react';

import { DialogAlasan } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import { KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import { AlamatPiutang, HalamanDaftarPiutang, LabelStatusPiutang } from '@/Komponen/Piutang/BagianPiutang';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisHapusBuku, PropsDaftarHapusBuku } from '@/Tipe/Piutang';

const alamat = `${AlamatPiutang}/hapus-buku`;

const kolom: KolomTabel<BarisHapusBuku>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: d } }) => <span className="font-mono font-semibold break-all">{d.Nomor}</span>,
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'NomorPiutang',
        header: 'Piutang',
        enableSorting: false,
        meta: { label: 'Piutang', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => <span className="font-mono break-all">{d.NomorPiutang}</span>,
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'rendah' },
        cell: ({ row: { original: d } }) => <span className="break-words">{d.NamaPelanggan ?? 'Tanpa pelanggan'}</span>,
    },
    {
        id: 'Alasan',
        header: 'Alasan',
        enableSorting: false,
        meta: { label: 'Alasan', prioritas: 'rendah' },
        cell: ({ row: { original: d } }) => (
            <span className="break-words">
                {d.Alasan}
                {d.AlasanBatal ? ` | Dibatalkan: ${d.AlasanBatal}` : ''}
            </span>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: d } }) => <LabelStatusPiutang status={d.Status} label={d.LabelStatus} />,
    },
    KolomUang('Jumlah', 'Jumlah', (d) => d.Jumlah, true),
];

/** E8: riwayat hapus-buku piutang; yang masih berlaku bisa dibatalkan (piutang kembali menjadi tagihan terbuka). */
export default function HalamanDaftarHapusBuku({ HapusBuku, OpsiStatus, Izin }: PropsDaftarHapusBuku) {
    const [dibatalkan, AturDibatalkan] = useState<BarisHapusBuku | null>(null);
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
    ];

    return (
        <HalamanDaftarPiutang
            judul="Hapus-buku piutang"
            keterangan="Sisa piutang yang dinyatakan tidak akan tertagih dan sudah dibukukan sebagai beban. Piutangnya tidak dihapus dari riwayat; membatalkan hapus-buku mengembalikannya sebagai tagihan terbuka."
            izin={Izin}
            objek="hapus-buku piutang"
        >
            <TabelData
                id="piutang-hapus-buku"
                label="Daftar hapus-buku piutang"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: HapusBuku }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor hapus-buku atau nomor piutang"
                saring={saring}
                labelBaris={(d) => `hapus-buku ${d.Nomor}`}
                aksiBaris={(d: BarisHapusBuku) => (
                    <ItemAksiBaris
                        aksi={
                            Izin.Kelola && d.Status === 'Diposting'
                                ? [{ label: 'Batalkan hapus-buku', saatPilih: () => AturDibatalkan(d) }]
                                : []
                        }
                    />
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada piutang yang dihapus-buku.',
                }}
            />
            {dibatalkan ? (
                <DialogAlasan
                    judul={`Batalkan hapus-buku ${dibatalkan.Nomor}?`}
                    keterangan="Jurnal hapus-buku dibalik dan piutang kembali menjadi tagihan terbuka."
                    labelAksi="Batalkan hapus-buku"
                    alamat={`${alamat}/${dibatalkan.Uuid}/batalkan`}
                    saatTutup={() => AturDibatalkan(null)}
                />
            ) : null}
        </HalamanDaftarPiutang>
    );
}
