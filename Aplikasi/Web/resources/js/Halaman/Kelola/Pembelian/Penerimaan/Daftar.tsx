import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import {
    BuatSaringPembelian,
    HalamanDaftarPembelian,
    KolomNomor,
    KolomPemasok,
    KolomStatus,
    KolomTanggal,
    KolomUang,
    TombolBuat,
} from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { BarisDaftarPenerimaan, PropsDaftarPenerimaan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/penerimaan`;

const kolom: KolomTabel<BarisDaftarPenerimaan>[] = [
    KolomNomor(alamat),
    KolomTanggal(),
    KolomPemasok(),
    {
        id: 'Asal',
        header: 'Asal',
        enableSorting: false,
        meta: { label: 'Asal', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) =>
            p.BelanjaStok ? 'Belanja stok' : p.NomorPesanan ? `PO ${p.NomorPesanan}` : 'Tanpa pesanan',
    },
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
    KolomStatus(),
    {
        id: 'Faktur',
        header: 'Faktur',
        enableSorting: false,
        meta: { label: 'Faktur', prioritas: 'rendah' },
        cell: ({ row }) =>
            row.original.Difakturkan ? (
                <LabelStatus jenis="sukses" teks="Sudah difakturkan" />
            ) : (
                <LabelStatus jenis="peringatan" teks="Belum difakturkan" />
            ),
    },
    KolomUang('Total', 'Nilai', (p) => p.Total, true),
];

/** F-04 fase 1: daftar penerimaan barang (GRN) & belanja stok. */
export default function HalamanDaftarPenerimaan({ Penerimaan, OpsiStatus, OpsiPemasok, Izin }: PropsDaftarPenerimaan) {
    return (
        <HalamanDaftarPembelian
            judul="Penerimaan barang"
            keterangan="Barang masuk dari pemasok menambah stok saat disimpan. Nilai barang (harga, diskon, alokasi ongkir) menjadi dasar HPP."
            izin={Izin}
            objek="penerimaan barang"
        >
            <AksiHalaman
                utama={<TombolBuat href={`${alamat}/buat`} label="Terima barang" izin={Izin} />}
                lainnya={[
                    ...(Izin.Kelola ? [{ label: 'Belanja stok', href: `${AlamatPembelian}/belanja-stok` }] : []),
                    { label: 'Biaya tambahan', href: `${AlamatPembelian}/biaya-tambahan` },
                ]}
            />
            <TabelData
                id="pembelian-penerimaan"
                label="Daftar penerimaan barang"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Penerimaan }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor, surat jalan, atau catatan"
                saring={BuatSaringPembelian(OpsiStatus, OpsiPemasok)}
                alamatDetail={(p) => `${alamat}/${p.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada penerimaan barang.',
                }}
            />
        </HalamanDaftarPembelian>
    );
}
