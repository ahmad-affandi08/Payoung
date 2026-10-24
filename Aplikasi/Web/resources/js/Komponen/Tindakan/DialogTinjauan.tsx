import type { ReactNode } from 'react';

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

type PropsDialogTinjauan = {
    judul: string;
    deskripsi: ReactNode;
    saatTutup: () => void;
    /** Tombol keputusan (setujui/terbitkan, tolak). Tidak menutup dialog sendiri; tutup lewat `onSuccess`. */
    aksi: ReactNode;
    /** Isian tambahan di antara deskripsi dan tombol, misal catatan peninjau. */
    children?: ReactNode;
    /** Galat umum (`errors.Umum`) dari pengiriman terakhir dialog ini; halaman di belakang tertutup tirai. */
    galatUmum?: string | undefined;
    labelBatal?: string;
};

/**
 * Tinjauan four-eyes: peninjau menyetujui atau menolak pengajuan anggota lain (tarif pajak, hari libur, harga
 * paket), dengan catatan peninjau sebagai `children` dan beberapa tombol keputusan di `aksi`. Untuk konfirmasi
 * satu tindakan (terbitkan, hapus) pakai `DialogKonfirmasi`. Dialog selalu terbuka selama dirender; Esc dan
 * tombol Batal memanggil `saatTutup`.
 */
export default function DialogTinjauan({
    judul,
    deskripsi,
    saatTutup,
    aksi,
    children,
    labelBatal = 'Batal',
    galatUmum,
}: PropsDialogTinjauan) {
    return (
        <AlertDialog
            open
            onOpenChange={(terbuka) => {
                if (!terbuka) {
                    saatTutup();
                }
            }}
        >
            <AlertDialogContent data-galat-umum="">
                <AlertDialogHeader>
                    <AlertDialogTitle className="text-subjudul text-teks-utama">{judul}</AlertDialogTitle>
                    <AlertDialogDescription>{deskripsi}</AlertDialogDescription>
                </AlertDialogHeader>
                {galatUmum ? <Pemberitahuan jenis="bahaya">{galatUmum}</Pemberitahuan> : null}
                {children}
                <AlertDialogFooter>
                    {aksi}
                    <AlertDialogCancel>{labelBatal}</AlertDialogCancel>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
