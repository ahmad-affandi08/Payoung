import type { ReactNode } from 'react';

import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Komponen/Ui/alert-dialog';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Komponen/Ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

type PropsDialogFormulir = {
    judul: string;
    keterangan?: ReactNode;
    /**
     * - `dialog`: formulir pendek di tengah layar.
     * - `panel`: formulir panjang (banyak centang) di panel samping.
     * - `konfirmasi`: tindakan berisiko; AlertDialog, tidak tertutup saat klik di luar.
     */
    jenis?: 'dialog' | 'panel' | 'konfirmasi';
    /** Lebar maksimum `dialog`/`panel`: `sedang` (bawaan) untuk formulir pendek, `lebar` untuk formulir dua kolom. */
    lebar?: 'sedang' | 'lebar';
    /**
     * Galat umum (`errors.Umum`) dari pengiriman terakhir, tampil di badan dialog di bawah judul. Halaman di
     * belakang tertutup tirai, jadi galat yang tidak terikat ke satu bidang harus tampil di dalam dialog.
     */
    galatUmum?: string | undefined;
    saatTutup: () => void;
    children: ReactNode;
};

const kelasJudul = 'text-subjudul font-semibold text-teks-utama';
const kelasKeterangan = 'flex flex-col gap-2 text-isi text-teks-sekunder';
const kelasLebar = { sedang: 'sm:max-w-xl', lebar: 'sm:max-w-3xl' } as const;

/**
 * Wadah formulir tindakan & data master. Dirender (terbuka) selama induknya memasangnya; menutup lewat Esc,
 * tombol "Tutup" (X), atau Batal memanggil `saatTutup`. Tombol kirim & batal tetap milik formulir di dalamnya.
 */
export default function DialogFormulir(props: PropsDialogFormulir) {
    const { judul, keterangan, jenis = 'dialog', lebar = 'sedang', galatUmum, children } = props;
    // Dialog yang menyambungkan `galatUmum` menampilkan galat server sendiri, jadi `DialogHasil` tidak menumpuknya.
    // Yang tidak menyambungkannya dibiarkan, dan galatnya tampil lewat `DialogHasil` di atasnya.
    const penandaGalat = 'galatUmum' in props ? { 'data-galat-umum': '' } : {};
    const UbahTerbuka = (terbuka: boolean) => {
        if (!terbuka) {
            props.saatTutup();
        }
    };
    const denganKeterangan = keterangan !== undefined && keterangan !== null;
    const galat = galatUmum ? <Pemberitahuan jenis="bahaya">{galatUmum}</Pemberitahuan> : null;

    if (jenis === 'konfirmasi') {
        return (
            <AlertDialog open onOpenChange={UbahTerbuka}>
                <AlertDialogContent
                    {...penandaGalat}
                    className="max-h-[90vh] overflow-y-auto"
                    {...(denganKeterangan ? {} : { 'aria-describedby': undefined })}
                >
                    <AlertDialogHeader>
                        <AlertDialogTitle className={kelasJudul}>{judul}</AlertDialogTitle>
                        {denganKeterangan ? (
                            <AlertDialogDescription asChild>
                                <div className={kelasKeterangan}>{keterangan}</div>
                            </AlertDialogDescription>
                        ) : null}
                    </AlertDialogHeader>
                    {galat}
                    {children}
                </AlertDialogContent>
            </AlertDialog>
        );
    }

    if (jenis === 'panel') {
        return (
            <Sheet open onOpenChange={UbahTerbuka}>
                <SheetContent
                    {...penandaGalat}
                    side="right"
                    className={cn('w-full overflow-y-auto', kelasLebar[lebar])}
                    {...(denganKeterangan ? {} : { 'aria-describedby': undefined })}
                >
                    <SheetHeader>
                        <SheetTitle className={kelasJudul}>{judul}</SheetTitle>
                        {denganKeterangan ? (
                            <SheetDescription asChild>
                                <div className={kelasKeterangan}>{keterangan}</div>
                            </SheetDescription>
                        ) : null}
                    </SheetHeader>
                    {galat ? <div className="px-4">{galat}</div> : null}
                    <div className="px-4 pb-4">{children}</div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open onOpenChange={UbahTerbuka}>
            <DialogContent
                {...penandaGalat}
                className={cn('max-h-[90vh] overflow-y-auto', kelasLebar[lebar])}
                {...(denganKeterangan ? {} : { 'aria-describedby': undefined })}
            >
                <DialogHeader>
                    <DialogTitle className={kelasJudul}>{judul}</DialogTitle>
                    {denganKeterangan ? (
                        <DialogDescription asChild>
                            <div className={kelasKeterangan}>{keterangan}</div>
                        </DialogDescription>
                    ) : null}
                </DialogHeader>
                {galat}
                {children}
            </DialogContent>
        </Dialog>
    );
}
