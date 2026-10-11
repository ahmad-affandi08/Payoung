import { Check, Copy } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Komponen/Ui/alert-dialog';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

/** Satu-satunya label tombol penutup, supaya setiap nilai rahasia berakhir dengan konfirmasi yang sama. */
export const LABEL_SELESAI_RAHASIA = 'Saya sudah menyimpannya';

/** Judul peringatan yang sama untuk semua nilai rahasia. */
export const JUDUL_PERINGATAN_RAHASIA = 'Hanya ditampilkan sekali';

/**
 * Mengatur buka-tutup dialog rahasia dari prop sekali-tampil milik server (flash sesi).
 *
 * Dialog terbuka saat server mengirim nilai baru dan tertutup hanya lewat `selesai` (tombol konfirmasi). Nilai yang
 * sama tidak membuka dialog lagi setelah ditutup, dan respons berikutnya tanpa nilai (flash sudah habis) tidak
 * menutup dialog yang sedang terbuka. Disetel saat render (pola "menyesuaikan state terhadap prop"), bukan di efek,
 * supaya tidak ada satu render dengan isi lama.
 */
export function useRahasiaSekali<T>(data: T | null | undefined): { aktif: T | null; selesai: () => void } {
    const [aktif, AturAktif] = useState<T | null>(data ?? null);
    const [dilihat, AturDilihat] = useState<T | null | undefined>(data);

    if (dilihat !== data) {
        AturDilihat(data);

        if (data) {
            AturAktif(data);
        }
    }

    return { aktif, selesai: () => AturAktif(null) };
}

/** Kotak nilai: font Mono, bisa dipilih sekali klik, memecah baris bila panjang. */
const kelasKotakNilai =
    'rounded-kontrol border border-garis bg-latar px-3 py-2 font-mono break-all text-teks-utama select-all';

function useSalin(teks: string) {
    const [tersalin, AturTersalin] = useState(false);

    const Salin = () => {
        void navigator.clipboard
            ?.writeText(teks)
            .then(() => AturTersalin(true))
            .catch(() => AturTersalin(false));
    };

    return { tersalin, Salin };
}

function Kapital(teks: string): string {
    return teks.charAt(0).toUpperCase() + teks.slice(1);
}

function TombolSalin({ teks, labelSalin, labelTersalin }: { teks: string; labelSalin: string; labelTersalin: string }) {
    const { tersalin, Salin } = useSalin(teks);

    return (
        <>
            <div>
                <Tombol varian="sekunder" onClick={Salin}>
                    {tersalin ? (
                        <>
                            <Check className="size-4" aria-hidden /> {labelTersalin}
                        </>
                    ) : (
                        <>
                            <Copy className="size-4" aria-hidden /> {labelSalin}
                        </>
                    )}
                </Tombol>
            </div>
            <p aria-live="polite" className="sr-only">
                {tersalin ? `${labelTersalin} ke papan klip.` : ''}
            </p>
        </>
    );
}

type PropsNilaiRahasia = {
    /** Isi yang ditampilkan dan disalin, apa adanya. */
    nilai: string;
    /** Sebutan di tombol & pembaca layar: "kode", "token", "rahasia". */
    jenis: string;
    /** Nilai pendek yang diketik orang (kode aktivasi): huruf besar, renggang, rata tengah. */
    besar?: boolean;
};

/** Satu nilai rahasia dengan tombol salin. */
export function NilaiRahasia({ nilai, jenis, besar = false }: PropsNilaiRahasia) {
    return (
        <div className="flex flex-col gap-2">
            <p
                className={cn(
                    kelasKotakNilai,
                    besar ? 'text-center text-judul font-bold tracking-widest' : 'text-label',
                )}
            >
                {nilai}
            </p>
            <TombolSalin teks={nilai} labelSalin={`Salin ${jenis}`} labelTersalin={`${Kapital(jenis)} tersalin`} />
        </div>
    );
}

/** Beberapa nilai rahasia sekaligus (kode pemulihan) dengan satu tombol "salin semua". */
export function DaftarNilaiRahasia({ nilai, jenis }: { nilai: string[]; jenis: string }) {
    return (
        <div className="flex flex-col gap-2">
            <ul className="grid grid-cols-2 gap-2 text-center text-isi">
                {nilai.map((baris) => (
                    <li key={baris} className={kelasKotakNilai}>
                        {baris}
                    </li>
                ))}
            </ul>
            <TombolSalin
                teks={nilai.join('\n')}
                labelSalin={`Salin semua ${jenis}`}
                labelTersalin={`Semua ${jenis} tersalin`}
            />
        </div>
    );
}

type PropsDialogRahasia = {
    /** Apa yang baru dibuat, sebagai kalimat singkat: "Token Aplikasi akuntansi dibuat". */
    judul: string;
    /** Tindak lanjut khusus jenis nilainya (di mana menyimpan/memasang). Kalimat "hanya sekali" sudah ada di judul peringatan. */
    peringatan: ReactNode;
    /** Isi: `NilaiRahasia`, `DaftarNilaiRahasia`, atau gabungan dengan teks penjelas. */
    children: ReactNode;
    /** Dipanggil saat pengguna menekan tombol konfirmasi; satu-satunya jalan menutup dialog. */
    saatSelesai: () => void;
};

/**
 * Nilai rahasia yang hanya ditampilkan sekali (kode aktivasi perangkat, token API, rahasia webhook, kode pemulihan 2FA).
 *
 * Aturan yang sama untuk semuanya: terbuka otomatis di atas halaman asalnya, tidak tertutup lewat Esc atau klik di
 * luar, dan hanya ditutup dengan tombol "Saya sudah menyimpannya". Selama terbuka, menutup/memuat ulang tab diberi
 * peringatan karena nilainya tidak bisa diambil lagi. Pesan berhasil dari server (`Kilat`) tidak dikirim bersamaan
 * supaya tidak ada dua dialog bertumpuk; judul dialog ini sudah menjadi pemberitahuan berhasilnya.
 */
export default function DialogRahasia({ judul, peringatan, children, saatSelesai }: PropsDialogRahasia) {
    useEffect(() => {
        const Jaga = (peristiwa: BeforeUnloadEvent) => peristiwa.preventDefault();
        window.addEventListener('beforeunload', Jaga);

        return () => window.removeEventListener('beforeunload', Jaga);
    }, []);

    return (
        <AlertDialog open onOpenChange={() => undefined}>
            <AlertDialogContent
                className="max-h-[90dvh] overflow-y-auto"
                onEscapeKeyDown={(peristiwa) => peristiwa.preventDefault()}
            >
                <AlertDialogHeader>
                    <AlertDialogTitle className="text-subjudul font-semibold text-teks-utama">{judul}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div>
                            <Pemberitahuan jenis="peringatan" judul={JUDUL_PERINGATAN_RAHASIA}>
                                {peringatan}
                            </Pemberitahuan>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <div className="flex flex-col gap-3 text-isi text-teks-sekunder">{children}</div>
                <AlertDialogFooter>
                    <Tombol onClick={saatSelesai}>{LABEL_SELESAI_RAHASIA}</Tombol>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
