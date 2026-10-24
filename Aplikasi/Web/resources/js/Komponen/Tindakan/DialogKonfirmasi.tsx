import type { ReactNode } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Komponen/Ui/alert-dialog';

type PropsDialogKonfirmasi = {
    judul: string;
    /** Akibat tindakan, ditulis konkret (§17.6.7). */
    children: ReactNode;
    labelAksi: string;
    /** `bahaya` untuk tindakan merusak (nonaktifkan, cabut, hapus); `utama` untuk tindakan final biasa. */
    varian?: 'bahaya' | 'utama';
    memproses?: boolean;
    /** Tombol aksi dinonaktifkan selama syarat belum dipenuhi (misal konfirmasi belum dicentang). */
    nonaktif?: boolean;
    saatKonfirmasi: () => void;
    saatBatal: () => void;
    /**
     * Galat umum (`errors.Umum`) dari pengiriman terakhir; tampil di badan dialog. Menyambungkan prop ini (walau nilainya
     * kosong) menandai dialog menampilkan galat sendiri, jadi `DialogHasil` tidak menumpuknya. Tanpa prop ini galat
     * server tampil lewat `DialogHasil` di atas dialog.
     */
    galatUmum?: string | undefined;
};

/**
 * Konfirmasi tindakan berisiko (AlertDialog). Terbuka selama dipasang; tidak tertutup saat klik di luar.
 * Tombol aksi tidak menutup dialog sendiri: induk menutupnya saat permintaan berhasil (`onSuccess`).
 */
export default function DialogKonfirmasi(props: PropsDialogKonfirmasi) {
    const { judul, children, labelAksi, varian = 'bahaya', memproses = false, nonaktif = false, galatUmum } = props;
    const penandaGalat = 'galatUmum' in props ? { 'data-galat-umum': '' } : {};

    return (
        <AlertDialog
            open
            onOpenChange={(terbuka) => {
                if (!terbuka && !memproses) {
                    props.saatBatal();
                }
            }}
        >
            <AlertDialogContent {...penandaGalat}>
                <AlertDialogHeader>
                    <AlertDialogTitle className="text-subjudul font-semibold text-teks-utama">{judul}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="flex flex-col gap-2 text-isi text-teks-sekunder">{children}</div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {galatUmum ? <Pemberitahuan jenis="bahaya">{galatUmum}</Pemberitahuan> : null}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={memproses}>Batal</AlertDialogCancel>
                    <Tombol varian={varian} memproses={memproses} disabled={nonaktif} onClick={props.saatKonfirmasi}>
                        {labelAksi}
                    </Tombol>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
