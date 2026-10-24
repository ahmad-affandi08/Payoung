import type { KeadaanBacaQris } from '@/Komponen/PanduanAwal/useBacaQris';
import { Spinner } from '@/Komponen/Ui/spinner';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

/** D-189: hasil baca QR dari gambar QRIS, dalam bahasa pemilik toko (apa yang terjadi di kasir, bukan istilah teknis). */
export default function HasilBacaQris({ keadaan }: { keadaan: KeadaanBacaQris }) {
    if (keadaan.Tahap === 'kosong') {
        return null;
    }

    if (keadaan.Tahap === 'membaca') {
        return (
            <p className="flex items-center gap-2 text-keterangan text-teks-sekunder" role="status">
                <Spinner role={undefined} aria-label={undefined} aria-hidden="true" className="size-4" />
                Membaca kode QR dari gambar…
            </p>
        );
    }

    if (keadaan.Tahap === 'berhasil') {
        return (
            <Pemberitahuan jenis="sukses" judul="QRIS terbaca, nominal akan terisi otomatis">
                {keadaan.NamaMerchant}
                {keadaan.Kota !== '' ? ` | ${keadaan.Kota}` : ''}. Di kasir, kode QR ditampilkan lengkap dengan nominal
                transaksi, jadi pelanggan tidak perlu mengetik angkanya. Kasir tetap mencentang konfirmasi setelah dana
                masuk.
            </Pemberitahuan>
        );
    }

    return (
        <Pemberitahuan jenis="peringatan" judul="Nominal belum bisa otomatis">
            {keadaan.Pesan}
        </Pemberitahuan>
    );
}
