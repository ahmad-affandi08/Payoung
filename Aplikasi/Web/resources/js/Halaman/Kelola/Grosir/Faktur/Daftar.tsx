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
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisDaftarFaktur, PropsDaftarFaktur } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/faktur`;

const kolom: KolomTabel<BarisDaftarFaktur>[] = [
    KolomNomorGrosir(alamat),
    KolomTanggalGrosir(),
    KolomPelangganGrosir(),
    {
        id: 'JatuhTempo',
        header: 'Jatuh tempo',
        meta: { label: 'Jatuh tempo', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.JatuhTempo),
    },
    {
        id: 'NomorFakturPajak',
        header: 'Faktur Pajak',
        enableSorting: false,
        meta: { label: 'Nomor Faktur Pajak', prioritas: 'rendah' },
        cell: ({ row: { original: f } }) =>
            f.NomorFakturPajak === null ? (
                <span className="text-teks-sekunder">Belum diisi</span>
            ) : (
                <span className="font-mono break-all">{f.NomorFakturPajak}</span>
            ),
    },
    KolomStatusGrosir(),
    KolomUangGrosir('Total', 'Total', (f) => f.Total, true),
    {
        id: 'Sisa',
        header: 'Sisa tagihan',
        enableSorting: false,
        meta: { label: 'Sisa tagihan', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: f } }) =>
            f.Sisa === null
                ? '—'
                : `${FormatRupiah(f.Sisa)}${f.LabelStatusPiutang ? ` | ${f.LabelStatusPiutang}` : ''}`,
    },
];

/**
 * Daftar faktur penjualan grosir (F-12, §9.7, BR-12.4). Sisa tagihannya dibaca dari piutang, bukan disalin ke faktur,
 * supaya tidak ada dua angka yang bisa berbeda saat pelunasan dicatat atau dibatalkan (BR-12.5).
 */
export default function HalamanDaftarFakturGrosir({ Faktur, OpsiStatus, Izin }: PropsDaftarFaktur) {
    return (
        <HalamanGrosir
            judul="Faktur penjualan grosir"
            keterangan="Tagihan atas barang yang sudah diserahkan, gabungan surat jalan satu pelanggan dalam satu bulan."
            izin={Izin}
            objek="faktur penjualan"
        >
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`${alamat}/buat`}>Buat faktur</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="grosir-faktur"
                label="Daftar faktur penjualan grosir"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Faktur }}
                ambilIdBaris={(f) => f.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor faktur atau nomor Faktur Pajak"
                saring={BuatSaringGrosir(OpsiStatus)}
                alamatDetail={(f) => `${alamat}/${f.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada faktur penjualan grosir.' }}
            />
        </HalamanGrosir>
    );
}
