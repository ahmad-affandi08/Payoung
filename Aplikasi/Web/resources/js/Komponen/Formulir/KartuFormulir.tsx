import type { ReactNode } from 'react';

import { Card } from '@/Komponen/Ui/card';

type PropsKartuFormulir = {
    /** Penjelasan singkat di atas formulir: untuk apa data ini dipakai. */
    keterangan?: ReactNode;
    children: ReactNode;
};

/**
 * Wadah halaman "Tambah …" (pola yang sama dengan Tambah produk): judul dari tata letak, keterangan singkat, lalu
 * formulir di dalam satu kartu. Dipakai menggantikan formulir tambah di panel samping.
 */
export default function KartuFormulir({ keterangan, children }: PropsKartuFormulir) {
    // Fragmen, bukan `<div>`: keterangan harus anak langsung badan halaman supaya CSS `.badan-halaman` memasangnya rapat
    // ke judul dengan huruf keterangan, dan kartunya mendapat jarak serta perataan HP yang sama dengan blok lain.
    return (
        <>
            {keterangan ? <p className="max-w-3xl text-isi text-teks-sekunder">{keterangan}</p> : null}
            <Card className="gap-0 rounded-panel p-4 shadow-none">{children}</Card>
        </>
    );
}
