import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import HasilPembayaran from '@/Komponen/Langganan/Bayar/HasilPembayaran';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { AmbilJson } from '@/Pustaka/PermintaanJson';

type PropsMemeriksaPembayaran = {
    uuidTagihan: string;
    /** Jeda pertanyaan status ke server (ms). */
    jedaPolling?: number;
    namaPenyedia?: string;
};

/**
 * D-305: layar setelah pemilik kembali dari halaman bayar DOKU. Pembayarannya masih menunggu kabar dari DOKU, jadi
 * halaman menanyakan status ke server (yang menanyakannya ke DOKU) tiap beberapa detik, dan memuat ulang begitu
 * hasilnya berubah. Lunasnya tagihan tetap tidak pernah diputuskan di peramban.
 */
export default function MemeriksaPembayaran({
    uuidTagihan,
    jedaPolling = 6000,
    namaPenyedia = 'penyedia pembayaran',
}: PropsMemeriksaPembayaran) {
    const [memeriksa, AturMemeriksa] = useState(false);
    const [kabar, AturKabar] = useState<string | null>(null);
    const jalan = useRef(true);

    const Cek = async (diam: boolean): Promise<void> => {
        try {
            const hasil = await AmbilJson<{ Lunas: boolean; MasihMenunggu: boolean }>(
                `/kelola/langganan/tagihan/${uuidTagihan}/status-bayar`,
            );

            if (!jalan.current) {
                return;
            }

            if (hasil.Lunas || !hasil.MasihMenunggu) {
                router.reload();

                return;
            }

            if (!diam) {
                AturKabar('Pembayaran belum kami terima. Coba cek lagi sebentar lagi.');
            }
        } catch {
            if (!diam) {
                AturKabar('Status belum bisa diperiksa. Periksa koneksi lalu coba lagi.');
            }
        }
    };

    useEffect(() => {
        jalan.current = true;
        const putaran = window.setInterval(() => void Cek(true), jedaPolling);

        return () => {
            jalan.current = false;
            window.clearInterval(putaran);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Cek hanya bergantung pada uuidTagihan.
    }, [uuidTagihan, jedaPolling]);

    const Periksa = async () => {
        AturKabar(null);
        AturMemeriksa(true);
        await Cek(false);
        AturMemeriksa(false);
    };

    return (
        <Card role="region" aria-label="Pembayaran tagihan" className="rounded-panel px-4 py-6 shadow-none">
            <HasilPembayaran keadaan="Memeriksa">
                <Tombol varian="sekunder" memproses={memeriksa} onClick={() => void Periksa()}>
                    Cek sekarang
                </Tombol>
            </HasilPembayaran>
            {kabar ? (
                <Pemberitahuan jenis="info" judul="Belum terbayar">
                    {kabar}
                </Pemberitahuan>
            ) : null}
            <p className="text-center text-keterangan text-teks-sekunder">
                Belum membayar di halaman {namaPenyedia}? Selesaikan di sana. Kalau waktunya habis, halaman ini akan
                berubah dan Anda bisa memilih cara bayar lagi.
            </p>
        </Card>
    );
}
