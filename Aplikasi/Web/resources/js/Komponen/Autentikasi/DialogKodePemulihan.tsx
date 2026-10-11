import DialogRahasia, { DaftarNilaiRahasia } from '@/Komponen/Umpan/DialogRahasia';

/**
 * Kode pemulihan verifikasi dua langkah, dipakai sama persis di Keamanan akun (tenant) dan setelah aktivasi 2FA di
 * konsol pengelola: satu dialog rahasia sekali-tampil (lihat `DialogRahasia`).
 */
export default function DialogKodePemulihan({ kode, saatSelesai }: { kode: string[]; saatSelesai: () => void }) {
    return (
        <DialogRahasia
            judul="Verifikasi dua langkah aktif"
            peringatan="Simpan kode pemulihan ini di pengelola kata sandi atau tempat aman lain. Setiap kode hanya bisa dipakai satu kali untuk masuk bila ponsel Anda tidak tersedia."
            saatSelesai={saatSelesai}
        >
            <DaftarNilaiRahasia nilai={kode} jenis="kode" />
        </DialogRahasia>
    );
}
