import { CopyIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import IlustrasiBayar, { type JenisIlustrasiBayar } from '@/Komponen/Langganan/Bayar/IlustrasiBayar';
import type { KeadaanBayar } from '@/Tipe/TagihanLangganan';

type KeadaanHasil = Exclude<KeadaanBayar, 'Menunggu' | 'Berhasil' | 'Ditolak'> | 'Memeriksa';

const isiHasil: Record<KeadaanHasil, { ilustrasi: JenisIlustrasiBayar; judul: string; teks: string }> = {
    Memeriksa: {
        ilustrasi: 'memeriksa',
        judul: 'Memeriksa pembayaran Anda',
        teks: 'Biasanya kurang dari satu menit. Halaman ini berpindah sendiri begitu pembayaran terkonfirmasi.',
    },
    Kedaluwarsa: {
        ilustrasi: 'kedaluwarsa',
        judul: 'Waktu bayar habis',
        teks: 'Kode bayar sebelumnya sudah tidak berlaku. Kalau Anda sudah sempat membayar, jangan bayar ulang dan hubungi Bantuan. Kalau belum, pilih cara bayar untuk membuat kode baru.',
    },
    TidakPasti: {
        ilustrasi: 'tidakPasti',
        judul: 'Kami masih memeriksa',
        teks: 'Kami belum bisa memastikan pembayaran ini. Itu bukan salah Anda, dan sebaiknya jangan bayar lagi dulu.',
    },
    Gagal: {
        ilustrasi: 'gagal',
        judul: 'Pembayaran belum selesai',
        teks: 'Pembayaran dibatalkan atau tidak diteruskan oleh penyedia pembayaran. Kalau saldo Anda sempat terpotong, jangan bayar ulang dan hubungi Bantuan. Kalau tidak, silakan pilih cara bayar lagi.',
    },
};

type PropsHasilPembayaran = {
    keadaan: KeadaanHasil;
    /** Kode `PYG-…` pembayaran terakhir, ditampilkan bila hasilnya belum pasti. */
    kodeReferensi?: string | null;
    /** Aksi di bawah teks (tombol). */
    children?: ReactNode;
};

/**
 * D-305: layar hasil pembayaran yang belum selesai (memeriksa, kedaluwarsa, belum pasti, gagal). Setiap hasil
 * menjelaskan keadaan sebenarnya dan langkah berikutnya, tanpa kode gerbang dan tanpa menyebut "ditolak" untuk hal
 * yang bukan salah pengguna. Hasil `berhasil` punya layar sendiri di halaman tagihan.
 */
export default function HasilPembayaran({ keadaan, kodeReferensi = null, children }: PropsHasilPembayaran) {
    const isi = isiHasil[keadaan];
    const [tersalin, AturTersalin] = useState(false);

    const Salin = async () => {
        if (kodeReferensi === null) {
            return;
        }

        try {
            await navigator.clipboard.writeText(kodeReferensi);
            AturTersalin(true);
            window.setTimeout(() => AturTersalin(false), 2500);
        } catch {
            AturTersalin(false);
        }
    };

    return (
        <div
            role={keadaan === 'Memeriksa' ? 'status' : undefined}
            data-hasil={keadaan}
            className="flex flex-col items-center gap-3 px-2 py-4 text-center"
        >
            <IlustrasiBayar jenis={isi.ilustrasi} />
            <h3 className="text-subjudul font-semibold text-teks-utama">{isi.judul}</h3>
            <p className="max-w-md text-isi text-teks-sekunder">{isi.teks}</p>
            {keadaan === 'TidakPasti' ? (
                <p className="max-w-md text-isi text-teks-sekunder">
                    Sudah telanjur bayar? Buka Bantuan di pojok atas lalu sebutkan kode referensi ini.
                </p>
            ) : null}
            {kodeReferensi !== null && (keadaan === 'TidakPasti' || keadaan === 'Gagal') ? (
                <div className="flex items-center gap-2 rounded-panel bg-permukaan-redup px-3 py-1">
                    <span className="text-keterangan text-teks-sekunder">Kode referensi</span>
                    <span className="font-semibold tabular-nums text-teks-utama">{kodeReferensi}</span>
                    <button
                        type="button"
                        className="inline-flex size-8 items-center justify-center rounded-kontrol text-brand hover:bg-brand-lembut pointer-coarse:size-11"
                        aria-label={tersalin ? 'Kode referensi tersalin' : 'Salin kode referensi'}
                        onClick={() => void Salin()}
                    >
                        <CopyIcon aria-hidden="true" className="size-4" />
                    </button>
                </div>
            ) : null}
            {children ? <div className="flex flex-wrap justify-center gap-2 pt-1">{children}</div> : null}
        </div>
    );
}

export type { KeadaanHasil };
