import { Link } from '@inertiajs/react';

import { Button } from '@/Komponen/Ui/button';
import TataLetakAutentikasiPengelola from '@/TataLetak/TataLetakAutentikasiPengelola';

type PropsGalat = { Status: number; Judul: string; Keterangan: string };

/** Keadaan "tanpa izin" dan galat Platform Pengelola (PRD §17.6.6). */
export default function Galat({ Status, Judul, Keterangan }: PropsGalat) {
    return (
        <TataLetakAutentikasiPengelola judul={Judul}>
            <div className="flex flex-col gap-4">
                <p className="text-isi text-teks-utama">{Keterangan}</p>
                <p className="font-mono text-keterangan text-teks-sekunder">Kode masalah: {Status}</p>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11 w-fit text-label font-semibold">
                    <Link href="/">Kembali ke beranda</Link>
                </Button>
            </div>
        </TataLetakAutentikasiPengelola>
    );
}
