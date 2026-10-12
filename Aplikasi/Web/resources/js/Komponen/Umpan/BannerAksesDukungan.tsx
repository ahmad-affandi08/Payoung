import { router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { AksesDukunganAktif } from '@/Tipe/Aplikasi';

/**
 * PGL-16 (BR-P09): selama petugas Payoung masuk lewat akses dukungan, setiap halaman menampilkan bilah merah tetap di atas
 * yang menyebut cakupan, batas waktu, dan tombol mengakhiri sesi. Sengaja tidak bisa ditutup.
 */
export default function BannerAksesDukungan({
    sesi,
    namaUsaha,
}: {
    sesi: AksesDukunganAktif;
    namaUsaha: string | null;
}) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <div
            role="alert"
            data-jenis="akses-dukungan"
            className="sticky top-0 z-40 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-bahaya bg-bahaya-lembut px-4 py-2"
        >
            <p className="min-w-0 flex-1 text-isi text-teks-utama">
                <strong className="font-semibold">
                    Anda mengakses akun {namaUsaha ?? 'ini'} sebagai Dukungan{sesi.Darurat ? ' (akses darurat)' : ''}.
                </strong>{' '}
                {sesi.NamaPetugas} | {sesi.LabelCakupan} | sampai {FormatTanggalWaktu(sesi.BerakhirPada)} |{' '}
                {sesi.DataPribadiTerbuka ? 'data pribadi pelanggan terbuka' : 'data pribadi pelanggan tersamar'}
            </p>
            <Tombol
                varian="bahaya"
                memproses={memproses}
                onClick={() => {
                    AturMemproses(true);
                    router.post('/dukungan/akhiri', {}, { onFinish: () => AturMemproses(false) });
                }}
            >
                Akhiri sesi
            </Tombol>
        </div>
    );
}
