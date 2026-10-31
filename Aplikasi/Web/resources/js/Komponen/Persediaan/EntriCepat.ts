import { useRef } from 'react';

/**
 * Entri cepat barang di form dokumen (transfer stok, belanja stok, pesanan pembelian, dan sejenisnya).
 *
 * Alurnya tanpa naik-turun halaman: pilih produk di kotak cari, barang itu muncul **paling atas** tepat di bawah
 * kotak cari, isian jumlahnya langsung aktif, lalu Enter mengembalikan fokus ke kotak cari untuk barang berikutnya.
 * Dengan begitu kotak cari dan baris yang sedang diisi selalu berdekatan, berapa pun banyak barangnya.
 *
 * @param awalan nama unik per form, dipakai membentuk id isian (misal `transfer`)
 */
export function useEntriCepat(awalan: string) {
    const idCari = `${awalan}-cari-produk`;
    const kunciBaru = useRef<string | null>(null);

    const IdJumlah = (kunci: string) => `${awalan}-jumlah-${kunci}`;

    /** Tandai baris yang baru ditambahkan supaya jumlahnya difokuskan begitu daftar produk menutup. */
    const CatatBarisBaru = (kunci: string) => {
        kunciBaru.current = kunci;
    };

    /**
     * Diberikan ke pemilih produk. Mengembalikan `true` bila ada baris baru yang jumlahnya difokuskan (fokus tidak
     * lagi dikembalikan ke tombol cari). Tanpa baris baru (misal daftar ditutup dengan Esc) fokus kembali seperti biasa.
     */
    const AlihkanFokusSetelahPilih = (): boolean => {
        const kunci = kunciBaru.current;
        kunciBaru.current = null;

        if (kunci === null) {
            return false;
        }

        requestAnimationFrame(() => document.getElementById(IdJumlah(kunci))?.focus());

        return true;
    };

    /** Enter di isian jumlah: fokus ke kotak cari dan buka daftarnya untuk barang berikutnya. */
    const KembaliKeCari = () => {
        const tombol = document.getElementById(idCari);

        tombol?.focus();
        tombol?.click();
    };

    return { idCari, IdJumlah, CatatBarisBaru, AlihkanFokusSetelahPilih, KembaliKeCari };
}
