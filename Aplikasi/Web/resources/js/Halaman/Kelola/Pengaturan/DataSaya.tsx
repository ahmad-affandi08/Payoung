import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';

type BarisEkspor = {
    Uuid: string;
    Status: 'Antre' | 'Berjalan' | 'Siap' | 'Gagal' | 'Kedaluwarsa';
    LabelStatus: string;
    UkuranByte: number;
    JumlahTabel: number;
    JumlahBaris: number;
    DibuatPada: string | null;
    KedaluwarsaPada: string | null;
    DiunduhPada: string | null;
    Galat: string | null;
    BisaDiunduh: boolean;
};

type PropsDataSaya = {
    Ekspor: BarisEkspor[];
};

const jenisStatus = {
    Antre: 'peringatan',
    Berjalan: 'peringatan',
    Siap: 'sukses',
    Gagal: 'bahaya',
    Kedaluwarsa: 'netral',
} as const;

/** Pengaturan › Data saya (UU PDP): ekspor data lengkap usaha. Penutupan akun menyusul di halaman yang sama. */
export default function HalamanDataSaya({ Ekspor }: PropsDataSaya) {
    const formulir = useForm({});
    const sedangDisiapkan = Ekspor.some((baris) => baris.Status === 'Antre' || baris.Status === 'Berjalan');

    useEffect(() => {
        if (!sedangDisiapkan) {
            return undefined;
        }

        const pewaktu = window.setInterval(() => router.reload({ only: ['Ekspor'] }), 4000);

        return () => window.clearInterval(pewaktu);
    }, [sedangDisiapkan]);

    return (
        <TataLetakAplikasi judul="Data saya">
            <div className="grid gap-4">
                <Panel
                    judul="Ekspor data usaha"
                    tingkat="h2"
                    keterangan="Unduh salinan lengkap data usaha Anda (produk, pelanggan, transaksi, stok, keuangan, karyawan) sebagai berkas ZIP berisi CSV."
                    aksi={
                        <Tombol
                            memproses={formulir.processing}
                            disabled={sedangDisiapkan}
                            onClick={() =>
                                formulir.post('/kelola/pengaturan/data-saya/ekspor', { preserveScroll: true })
                            }
                        >
                            Minta ekspor data
                        </Tombol>
                    }
                >
                    <div className="grid gap-3">
                        <Pemberitahuan jenis="info" judul="Yang perlu Anda tahu">
                            Berkas berisi data pelanggan dan karyawan, jadi simpan di tempat aman. Kata sandi, PIN,
                            token, sidik wajah, identitas pajak terenkripsi, dan berkas foto tidak ikut. Berkas bisa
                            diunduh selama 7 hari, dan ekspor baru bisa diminta sekali per 24 jam.
                        </Pemberitahuan>
                        {Ekspor.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">
                                Belum ada ekspor. Tekan &quot;Minta ekspor data&quot; untuk memulai.
                            </p>
                        ) : (
                            <ul className="grid gap-2" aria-label="Riwayat ekspor data">
                                {Ekspor.map((baris) => (
                                    <li
                                        key={baris.Uuid}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-panel border border-garis p-3"
                                    >
                                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                                            <span className="text-isi font-semibold text-teks-utama">
                                                {FormatTanggalWaktu(baris.DibuatPada)}
                                            </span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                {baris.Status === 'Siap' || baris.Status === 'Kedaluwarsa'
                                                    ? `${String(baris.JumlahTabel)} berkas | ${baris.JumlahBaris.toLocaleString('id-ID')} baris | ${FormatUkuranBerkas(baris.UkuranByte)}`
                                                    : (baris.Galat ?? 'Menyiapkan data Anda…')}
                                                {baris.BisaDiunduh && baris.KedaluwarsaPada
                                                    ? ` | tersedia sampai ${FormatTanggalWaktu(baris.KedaluwarsaPada)}`
                                                    : ''}
                                            </span>
                                        </div>
                                        <LabelStatus jenis={jenisStatus[baris.Status]} teks={baris.LabelStatus} />
                                        {baris.BisaDiunduh ? (
                                            <Tombol
                                                varian="sekunder"
                                                onClick={() => {
                                                    window.location.href = `/kelola/pengaturan/data-saya/ekspor/${baris.Uuid}/unduh`;
                                                }}
                                            >
                                                Unduh
                                            </Tombol>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </Panel>
            </div>
        </TataLetakAplikasi>
    );
}
