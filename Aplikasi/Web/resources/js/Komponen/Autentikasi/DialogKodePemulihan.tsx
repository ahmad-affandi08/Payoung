import DialogRahasia, { DaftarNilaiRahasia } from '@/Komponen/Umpan/DialogRahasia';

/**
 * Kode pemulihan verifikasi dua langkah, dipakai sama persis di Keamanan akun (tenant) dan setelah aktivasi 2FA di
 * konsol pengelola: satu dialog rahasia sekali-tampil (lihat `DialogRahasia`).
 */
export default function DialogKodePemulihan({ kode, saatSelesai }: { kode: string[]; saatSelesai: () => void }) {
    return (
        <DialogRahasia
            judul="Verifikasi dua langkah aktif"
            peringatan="Simpan di pengelola kata sandi atau tempat aman. Tiap kode hanya sekali pakai bila ponsel tidak tersedia."
            saatSelesai={saatSelesai}
        >
            <DaftarNilaiRahasia nilai={kode} jenis="kode" />
        </DialogRahasia>
    );
}
