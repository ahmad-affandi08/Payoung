import type { ReactNode } from 'react';

import LabelStatus from '@/Komponen/Umpan/LabelStatus';

type PropsBarisPengaturan = {
    judul: string;
    /** Nilai yang ditampilkan masih nilai awal server, belum pernah disimpan dari konsol. */
    nilaiAwal?: boolean;
    /** Tombol ubah di kanan judul (kosong bila peran hanya boleh melihat). */
    aksi?: ReactNode;
    /** Keterangan kecil di bawah nilai. */
    keterangan?: ReactNode;
    children: ReactNode;
};

/**
 * Satu baris pengaturan konsol: judul di kiri, tombol Ubah di kanan, nilai di bawahnya, keterangan paling bawah.
 * Dipakai berderet di dalam satu `Panel` (dipisah garis) supaya semua pengaturan penagihan tampil seragam, bukan
 * kalimat dan tombol yang berserakan di antara tabel.
 */
export default function BarisPengaturanKonsol({
    judul,
    nilaiAwal = false,
    aksi,
    keterangan,
    children,
}: PropsBarisPengaturan) {
    return (
        <div className="flex flex-col gap-1.5 py-4 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-isi font-semibold text-teks-utama">{judul}</h3>
                {nilaiAwal ? <LabelStatus jenis="peringatan" teks="Nilai awal server" /> : null}
                {aksi ? <div className="ml-auto">{aksi}</div> : null}
            </div>
            <div className="flex flex-col gap-1 text-isi text-teks-sekunder">{children}</div>
            {keterangan ? <p className="text-keterangan text-teks-sekunder">{keterangan}</p> : null}
        </div>
    );
}
