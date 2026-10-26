import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

type PropsPesanHanyaLihat = { izin: string; objek: string };

/** Izin teknis → bagian yang sehari-hari dikenal pemilik toko; kode izin tidak pernah ditampilkan. */
const BAGIAN_IZIN: Record<string, string> = {
    'akuntansi.kelola': 'pembukuan',
    'grosir.kelola': 'penjualan grosir',
    'invoice.kelola': 'invoice',
    'karyawan.kelola': 'karyawan',
    'pelanggan.kelola': 'pelanggan',
    'pembelian.kelola': 'pembelian',
    'persediaan.kelola': 'stok',
    'produk.harga.ubah': 'harga produk',
    'produk.kelola': 'produk',
};

/** Keadaan tanpa izin ubah (PRD §17.6.6): layar tetap bisa dibaca, tombol ubah disembunyikan, alasannya ditulis. */
export default function PesanHanyaLihat({ izin, objek }: PropsPesanHanyaLihat) {
    const bagian = BAGIAN_IZIN[izin];

    return (
        <Pemberitahuan jenis="info" judul="Hanya bisa melihat">
            Anda bisa melihat {objek}, tetapi tidak bisa mengubahnya.{' '}
            {bagian === undefined
                ? 'Minta Pemilik toko memberi Anda akses untuk mengubahnya.'
                : `Minta Pemilik toko memberi Anda akses untuk mengelola ${bagian}.`}
        </Pemberitahuan>
    );
}
