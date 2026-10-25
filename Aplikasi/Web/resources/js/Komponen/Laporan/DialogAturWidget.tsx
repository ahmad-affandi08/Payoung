import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';

type PropsDialogAturWidget<T extends string> = {
    /** Widget yang tersedia bagi pengguna ini (urut tampil); yang di luar izin tidak ditawarkan. */
    daftar: readonly { Kode: T; Judul: string }[];
    tersembunyi: T[];
    saatUbah: (daftar: T[]) => void;
    saatTutup: () => void;
};

/** Dialog "Atur widget" bersama Beranda tenant dan Beranda konsol: centang = tampil, pilihan diingat di peramban. */
export default function DialogAturWidget<T extends string>({
    daftar,
    tersembunyi,
    saatUbah,
    saatTutup,
}: PropsDialogAturWidget<T>) {
    return (
        <DialogFormulir
            judul="Atur widget"
            keterangan="Pilih widget yang tampil di Beranda. Pilihan ini hanya berlaku di peramban ini."
            saatTutup={saatTutup}
        >
            <div className="flex flex-col">
                {daftar.map((w) => (
                    <KotakCentang
                        key={w.Kode}
                        label={w.Judul}
                        nilai={!tersembunyi.includes(w.Kode)}
                        saatBerubah={(tampil) =>
                            saatUbah(tampil ? tersembunyi.filter((k) => k !== w.Kode) : [...tersembunyi, w.Kode])
                        }
                    />
                ))}
            </div>
            <DialogFooter className="sm:justify-start">
                <Tombol onClick={saatTutup}>Selesai</Tombol>
                <Tombol varian="sekunder" disabled={tersembunyi.length === 0} onClick={() => saatUbah([])}>
                    Tampilkan semua
                </Tombol>
            </DialogFooter>
        </DialogFormulir>
    );
}
