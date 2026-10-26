import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import {
    BuatSaringPembelian,
    HalamanDaftarPembelian,
    KolomNomor,
    KolomPemasok,
    KolomStatus,
    KolomTanggal,
    KolomUang,
} from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import type { BarisDaftarRetur, PropsDaftarRetur } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/retur`;

const kolom: KolomTabel<BarisDaftarRetur>[] = [
    KolomNomor(alamat),
    KolomTanggal(),
    KolomPemasok(),
    {
        id: 'Penerimaan',
        header: 'Dari penerimaan',
        enableSorting: false,
        meta: { label: 'Dari penerimaan', prioritas: 'rendah', kelasSel: 'font-mono break-all' },
        cell: ({ row }) => row.original.NomorPenerimaan ?? '—',
    },
    {
        id: 'Alasan',
        header: 'Alasan',
        enableSorting: false,
        meta: { label: 'Alasan', prioritas: 'rendah' },
        cell: ({ row }) => <span className="break-words">{row.original.Alasan}</span>,
    },
    KolomStatus(),
    KolomUang('Total', 'Nilai retur', (r) => r.Total, true),
];

/** F-04 fase 1: daftar retur pembelian. Retur dibuat dari halaman detail penerimaan barang. */
export default function HalamanDaftarRetur({ Retur, OpsiStatus, OpsiPemasok, Izin }: PropsDaftarRetur) {
    return (
        <HalamanDaftarPembelian
            judul="Retur pembelian"
            keterangan="Barang rusak atau salah yang dikembalikan ke pemasok. Buat retur dari halaman detail penerimaan barang. Stok berkurang dan hutang ke pemasok ikut berkurang."
            izin={Izin}
            objek="retur pembelian"
        >
            <TabelData
                id="pembelian-retur"
                label="Daftar retur pembelian"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Retur }}
                ambilIdBaris={(r) => r.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor atau alasan"
                saring={BuatSaringPembelian(OpsiStatus, OpsiPemasok)}
                alamatDetail={(r) => `${alamat}/${r.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada retur pembelian.' }}
            />
        </HalamanDaftarPembelian>
    );
}
