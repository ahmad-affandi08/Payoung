import { History } from 'lucide-react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';

export type RevisiHalaman = {
    Uuid: string;
    Jenis: 'Terbit' | 'SebelumPulih' | 'Manual';
    Judul: string;
    JumlahBlok: number;
    DibuatPada: string;
    Pembuat: string | null;
};

type PropsPanelRiwayat = {
    terbuka: boolean;
    saatTutup: () => void;
    revisi: RevisiHalaman[];
    bolehUbah: boolean;
    memproses: boolean;
    saatPulihkan: (revisi: RevisiHalaman) => void;
};

const LABEL_JENIS: Record<RevisiHalaman['Jenis'], string> = {
    Terbit: 'Saat diterbitkan',
    SebelumPulih: 'Draf sebelum dipulihkan',
    Manual: 'Disimpan manual',
};

/** Panel "Riwayat versi": salinan isi halaman saat diterbitkan, dan pemulihan ke draf (D-63). */
export default function PanelRiwayat({
    terbuka,
    saatTutup,
    revisi,
    bolehUbah,
    memproses,
    saatPulihkan,
}: PropsPanelRiwayat) {
    return (
        <Sheet open={terbuka} onOpenChange={(buka) => (buka ? undefined : saatTutup())}>
            <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle className="text-judul font-semibold text-teks-utama">Riwayat versi</SheetTitle>
                    <SheetDescription className="text-isi text-teks-sekunder">
                        Setiap kali halaman diterbitkan, salinannya disimpan di sini. Memulihkan versi menaruh isinya di
                        draf. Situs publik tidak berubah sampai Anda menerbitkan lagi.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-col gap-2 px-4 pb-6">
                    {revisi.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-panel border border-dashed border-garis-input px-4 py-8 text-center">
                            <History className="size-7 text-teks-sekunder" aria-hidden />
                            <p className="text-isi text-teks-sekunder">
                                Belum ada riwayat. Versi pertama tersimpan saat halaman ini diterbitkan.
                            </p>
                        </div>
                    ) : (
                        <ol className="flex flex-col gap-2">
                            {revisi.map((r) => (
                                <li
                                    key={r.Uuid}
                                    className="flex items-center gap-3 rounded-panel border border-garis bg-permukaan p-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-isi font-semibold text-teks-utama">
                                            {FormatTanggalWaktu(r.DibuatPada)}
                                        </p>
                                        <p className="truncate text-keterangan text-teks-sekunder">
                                            {LABEL_JENIS[r.Jenis]} | {String(r.JumlahBlok)} blok
                                            {r.Pembuat ? ` | ${r.Pembuat}` : ''}
                                        </p>
                                    </div>
                                    {bolehUbah ? (
                                        <Tombol varian="sekunder" disabled={memproses} onClick={() => saatPulihkan(r)}>
                                            Pulihkan
                                        </Tombol>
                                    ) : null}
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
