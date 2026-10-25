import { useState } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { ALAMAT_ABSEN } from '@/Fitur/Absensi/Alamat';
import { KirimJson } from '@/Pustaka/PermintaanJson';

type PropsFormMintaKoreksi = {
    /** Yang gagal: absen masuk atau keluar. */
    jenis: 'Masuk' | 'Keluar';
    saatSelesai: () => void;
    saatBatal: () => void;
    alamatDasar?: string;
};

/**
 * Jalan keluar bila absen terus gagal (wajah tidak dikenali di tempat gelap, kamera rusak): karyawan menulis alasannya
 * dan pengelola mencatat jamnya sendiri. Tidak menggantikan absen; hanya permintaan yang muncul di Kotak Tindakan.
 */
export default function FormMintaKoreksi({
    jenis,
    saatSelesai,
    saatBatal,
    alamatDasar = ALAMAT_ABSEN,
}: PropsFormMintaKoreksi) {
    const [alasan, AturAlasan] = useState('');
    const [mengirim, AturMengirim] = useState(false);
    const [galat, AturGalat] = useState<string | null>(null);

    const Kirim = async () => {
        if (alasan.trim().length < 5) {
            AturGalat(
                'Tulis alasannya singkat, misal "kamera depan rusak" atau "wajah tidak dikenali di ruangan gelap".',
            );

            return;
        }

        AturMengirim(true);
        AturGalat(null);

        try {
            await KirimJson(`${alamatDasar}/minta-koreksi`, { Jenis: jenis, Alasan: alasan.trim() });
            saatSelesai();
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof Error ? kesalahan.message : 'Permintaan gagal dikirim. Coba lagi.');
        } finally {
            AturMengirim(false);
        }
    };

    return (
        <div className="flex flex-col gap-3 rounded-panel bg-permukaan-redup p-3">
            <p className="text-isi text-teks-utama">
                Absen {jenis === 'Masuk' ? 'masuk' : 'pulang'} belum berhasil? Kirim permintaan ke manajer. Ia akan
                mencatat jam {jenis === 'Masuk' ? 'masuk' : 'pulang'} Anda setelah memeriksa.
            </p>
            <BidangTeksPanjang
                label="Alasan"
                nilai={alasan}
                saatBerubah={AturAlasan}
                galat={galat ?? undefined}
                baris={3}
                maksimal={200}
                required
            />
            <div className="flex flex-wrap gap-2">
                <Tombol memproses={mengirim} onClick={() => void Kirim()}>
                    Kirim permintaan
                </Tombol>
                <Tombol varian="sekunder" onClick={saatBatal}>
                    Batal
                </Tombol>
            </div>
        </div>
    );
}
