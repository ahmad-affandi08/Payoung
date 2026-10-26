import { Link } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import { HalamanDaftarPembelian, KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import type { BarisPenitipKonsinyasi, PropsDaftarKonsinyasi } from '@/Tipe/Pembelian';

export const AlamatKonsinyasi = `${AlamatPembelian}/konsinyasi`;

const kolom: KolomTabel<BarisPenitipKonsinyasi>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Penitip',
        meta: { label: 'Penitip', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <Link
                href={`${AlamatKonsinyasi}/penitip/${p.Uuid}`}
                className="font-semibold break-words text-brand underline"
            >
                {p.Nama}
            </Link>
        ),
    },
    {
        id: 'JumlahProduk',
        accessorKey: 'JumlahProduk',
        header: 'Produk titipan',
        meta: { label: 'Produk titipan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => row.original.JumlahProduk.toLocaleString('id-ID'),
    },
    KolomUang('Terjual', 'Nilai terjual', (p) => p.Terjual),
    KolomUang('Dibayar', 'Sudah disetor', (p) => p.Dibayar),
    KolomUang('Sisa', 'Hutang ke penitip', (p) => p.Sisa),
];

/**
 * F-05i: penitip barang konsinyasi beserta hutangnya (nilai titipan yang sudah terjual − setoran). Barang titipan
 * bukan aset toko: hanya yang terjual menjadi hutang (J-05.7).
 */
export default function HalamanDaftarKonsinyasi({ Penitip, TotalSisa, Izin }: PropsDaftarKonsinyasi) {
    return (
        <HalamanDaftarPembelian
            judul="Konsinyasi"
            keterangan="Barang titipan pemasok (penitip) yang dijual toko. Stok titipan bukan aset toko, yang terjual menjadi hutang ke penitip dan dilunasi lewat setoran."
            izin={Izin}
            objek="konsinyasi"
        >
            <AksiHalaman
                keterangan={`Total hutang ke penitip ${FormatRupiah(TotalSisa)}`}
                {...(Izin.Kelola
                    ? {
                          utama: (
                              <Button asChild className="h-8 pointer-coarse:h-11">
                                  <Link href={`${AlamatKonsinyasi}/buat?jenis=Masuk`}>Catat titipan masuk</Link>
                              </Button>
                          ),
                      }
                    : {})}
                lainnya={[
                    { label: 'Riwayat titipan', href: `${AlamatKonsinyasi}/dokumen` },
                    ...(Izin.Kelola
                        ? [{ label: 'Retur ke penitip', href: `${AlamatKonsinyasi}/buat?jenis=Retur` }]
                        : []),
                ]}
            />
            <TabelData
                id="pembelian-konsinyasi-penitip"
                label="Daftar penitip"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Penitip }}
                ambilIdBaris={(p) => p.Uuid}
                cari="Cari nama penitip"
                alamatDetail={(p) => `${AlamatKonsinyasi}/penitip/${p.Uuid}`}
                labelBaris={(p) => `penitip ${p.Nama}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada barang titipan. Ubah jenis produk menjadi Konsinyasi, lalu catat titipan masuk.',
                }}
            />
        </HalamanDaftarPembelian>
    );
}
