import { Link } from '@inertiajs/react';

import {
    AlamatGrosir,
    BuatSaringGrosir,
    HalamanGrosir,
    KolomNomorGrosir,
    KolomPelangganGrosir,
    KolomStatusGrosir,
    KolomTanggalGrosir,
    KolomUangGrosir,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisDaftarPesananGrosir, PropsDaftarPesananGrosir } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/pesanan`;

const kolom: KolomTabel<BarisDaftarPesananGrosir>[] = [
    KolomNomorGrosir(alamat),
    KolomTanggalGrosir(),
    KolomPelangganGrosir(),
    {
        id: 'TanggalKirimDiminta',
        header: 'Minta dikirim',
        enableSorting: false,
        meta: { label: 'Minta dikirim', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.TanggalKirimDiminta ? FormatTanggal(row.original.TanggalKirimDiminta) : '—'),
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah' },
        cell: ({ row }) => row.original.KodeOutlet,
    },
    KolomStatusGrosir(),
    KolomUangGrosir('Total', 'Total', (p) => p.Total, true),
];

/**
 * Daftar pesanan grosir (F-12, §9.7). Pesanan hanya kesepakatan: stok, HPP, pendapatan, dan PPN belum bergerak sampai
 * barangnya diserahkan lewat surat jalan (BR-12.2).
 */
export default function HalamanDaftarPesananGrosir({ Pesanan, OpsiStatus, Izin }: PropsDaftarPesananGrosir) {
    return (
        <HalamanGrosir
            judul="Pesanan grosir"
            keterangan="Pesanan pembeli grosir. Limit kredit diperiksa saat dikonfirmasi, barang diserahkan bertahap lewat surat jalan."
            izin={Izin}
            objek="pesanan grosir"
        >
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`${alamat}/buat`}>Buat pesanan grosir</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="grosir-pesanan"
                label="Daftar pesanan grosir"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Pesanan }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor pesanan"
                saring={BuatSaringGrosir(OpsiStatus)}
                alamatDetail={(p) => `${alamat}/${p.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada pesanan grosir.' }}
            />
        </HalamanGrosir>
    );
}
