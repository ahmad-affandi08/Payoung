import { router } from '@inertiajs/react';

import DialogKodePemulihan from '@/Komponen/Autentikasi/DialogKodePemulihan';
import { useRahasiaSekali } from '@/Komponen/Umpan/DialogRahasia';
import TataLetakAutentikasiPengelola from '@/TataLetak/TataLetakAutentikasiPengelola';

/**
 * Kode pemulihan 2FA konsol, ditampilkan sekali saja setelah aktivasi. Dialognya sama dengan di Keamanan akun tenant;
 * menekan "Saya sudah menyimpannya" membawa pengguna ke beranda konsol.
 */
export default function KodePemulihan({ KodePemulihan }: { KodePemulihan: string[] }) {
    const kode = useRahasiaSekali(KodePemulihan);

    return (
        <TataLetakAutentikasiPengelola
            judul="Verifikasi dua langkah aktif"
            keterangan="Satu langkah lagi: simpan kode pemulihan Anda sebelum masuk ke konsol."
        >
            <p className="text-isi text-teks-sekunder">
                Kode pemulihan dipakai untuk masuk bila ponsel dengan aplikasi autentikator hilang atau tidak tersedia.
            </p>
            {kode.aktif ? <DialogKodePemulihan kode={kode.aktif} saatSelesai={() => router.visit('/')} /> : null}
        </TataLetakAutentikasiPengelola>
    );
}
