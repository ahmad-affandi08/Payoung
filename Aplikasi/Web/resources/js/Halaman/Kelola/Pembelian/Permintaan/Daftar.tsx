import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import {
    HalamanDaftarPembelian,
    KolomNomor,
    KolomPemasok,
    KolomStatus,
    KolomTanggal,
    TombolBuat,
} from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisDaftarPermintaan, PropsDaftarPermintaan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/permintaan`;

const kolom: KolomTabel<BarisDaftarPermintaan>[] = [
    KolomNomor(alamat),
    KolomTanggal(),
    {
        id: 'Lokasi',
        header: 'Lokasi',
        enableSorting: false,
        meta: { label: 'Lokasi', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col break-words">
                <span>{p.NamaGudang}</span>
                {p.NamaOutlet ? <span className="text-keterangan text-teks-sekunder">{p.NamaOutlet}</span> : null}
            </span>
        ),
    },
    {
        id: 'DibutuhkanPada',
        header: 'Dibutuhkan',
        enableSorting: false,
        meta: { label: 'Dibutuhkan pada', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.DibutuhkanPada ? FormatTanggal(row.original.DibutuhkanPada) : '—'),
    },
    KolomPemasok(),
    {
        id: 'JumlahBaris',
        header: 'Barang',
        enableSorting: false,
        meta: { label: 'Jumlah barang', angka: true, prioritas: 'penting' },
        cell: ({ row }) => `${String(row.original.JumlahBaris)} item`,
    },
    KolomStatus(),
];

/** F-04 PR: daftar permintaan pembelian dari outlet. */
export default function HalamanDaftarPermintaan({ Permintaan, OpsiStatus, Izin }: PropsDaftarPermintaan) {
    return (
        <HalamanDaftarPembelian
            judul="Permintaan pembelian"
            keterangan="Outlet meminta barang dibelikan. Permintaan disetujui lebih dulu, lalu dijadikan pesanan pembelian ke pemasok."
            izin={Izin}
            objek="permintaan pembelian"
            jejak={[{ label: 'Pesanan pembelian', href: `${AlamatPembelian}/pesanan` }]}
        >
            <AksiHalaman>
                <TombolBuat href={`${alamat}/buat`} label="Buat permintaan pembelian" izin={Izin} />
            </AksiHalaman>
            <TabelData
                id="pembelian-permintaan"
                label="Daftar permintaan pembelian"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Permintaan }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, catatan, atau pemasok"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                    { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
                ]}
                alamatDetail={(p) => `${alamat}/${p.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada permintaan pembelian.',
                }}
            />
        </HalamanDaftarPembelian>
    );
}
